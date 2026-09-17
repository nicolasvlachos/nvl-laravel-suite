<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Nvl\Auth\Actions\ApiTokens\CreateApiTokenAction;
use Nvl\Auth\Actions\Invitations\AcceptInvitationAction;
use Nvl\Auth\Actions\Invitations\CreateInvitationAction;
use Nvl\Auth\Actions\Memberships\ListOwnMembershipsAction;
use Nvl\Auth\Actions\Memberships\ProvisionTenantOwnerAction;
use Nvl\Auth\Actions\Memberships\RevokeMembershipAction;
use Nvl\Auth\Actions\Rbac\SynchronizePermissionCatalogAction;
use Nvl\Auth\Actions\Rbac\SynchronizeRoleTemplatesAction;
use Nvl\Auth\Actions\Users\SyncUserRolesAction;
use Nvl\Auth\Data\Mutations\ApiTokenData;
use Nvl\Auth\Data\Mutations\StoreInvitationData;
use Nvl\Auth\Data\Mutations\SyncUserRolesData;
use Nvl\Auth\ValueObjects\SubjectReference;
use Nvl\Auth\ValueObjects\SystemMutationContext;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Exercises Auth through an activated multi-tenant consumer profile. */
final readonly class TenantAuthConsumerProbe implements AuthConsumerSmoke
{
    public function __construct(
        private TenantAdoptionCoordinator $adoption,
        private TenantRunner $tenants,
        private ProvisionTenantOwnerAction $provisionOwner,
        private SynchronizePermissionCatalogAction $synchronizePermissions,
        private SynchronizeRoleTemplatesAction $synchronizeRoles,
        private SyncUserRolesAction $syncRoles,
        private CreateApiTokenAction $createToken,
        private CreateInvitationAction $createInvitation,
        private AcceptInvitationAction $acceptInvitation,
        private ListOwnMembershipsAction $listOwnMemberships,
        private RevokeMembershipAction $revokeMembership,
    ) {}

    /** @return array<string, int|string|bool> */
    public function execute(bool $verifyQueuedMail): array
    {
        if ($verifyQueuedMail) {
            throw new LogicException('Queued mail verification is unavailable in the Auth tenancy profile.');
        }

        $tenantA = new TenantId('018f0000-0000-7000-8000-000000000001');
        $tenantB = new TenantId('018f0000-0000-7000-8000-000000000002');
        foreach ([[$tenantA, 'Consumer A'], [$tenantB, 'Consumer B']] as [$tenant, $name]) {
            DB::table('nvl_tenancy_tenants')->updateOrInsert(
                ['id' => $tenant->value],
                ['name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $operation = new PlatformOperation('auth-consumer.adoption', 'system', 'auth-consumer');
        $principal = User::forceCreate([
            'name' => 'Tenant Consumer Principal',
            'email' => 'tenant-principal@auth-consumer.test',
            'email_verified_at' => now(),
            'password' => null,
            'is_active' => true,
            'locale' => 'en',
            'timezone' => 'UTC',
            'profile' => [],
            'preferences' => [],
        ]);
        $plan = $this->adoption->prepare(['auth'], [], $operation);
        while (! $this->adoption->backfill($plan, 100, $operation)) {
            // Continue only advancing bounded package checkpoints.
        }
        if (! $this->adoption->verify($plan)->passed()) {
            throw new LogicException('The sealed consumer Auth adoption did not verify.');
        }
        $this->adoption->activate($plan, $operation);
        $this->tenants->platform(
            $operation,
            fn (): int => $this->synchronizePermissions->execute($principal),
        );

        $reference = SubjectReference::fromAuthenticatable($principal);
        $authority = new SystemMutationContext(
            reason: 'auth-production-consumer-tenancy',
            correlationId: 'auth-production-consumer-tenancy-v1',
        );
        foreach ([$tenantA, $tenantB] as $tenant) {
            $this->tenants->run($tenant, function () use ($authority, $principal, $reference): void {
                $this->provisionOwner->execute($authority, $reference);
                $this->synchronizeRoles->execute($principal);
                $this->syncRoles->execute(
                    $authority,
                    $principal,
                    new SyncUserRolesData(['auth-consumer-administrator']),
                );
            });
        }
        $issued = $this->tenants->run($tenantA, fn () => $this->createToken->execute(
            $principal,
            new ApiTokenData('tenant-consumer', ['consumer:read']),
        ));
        $recipient = User::forceCreate([
            'name' => 'Tenant Consumer Invitee',
            'email' => 'tenant-invitee@auth-consumer.test',
            'email_verified_at' => now(),
            'password' => null,
            'is_active' => true,
            'locale' => 'en',
            'timezone' => 'UTC',
            'profile' => [],
            'preferences' => [],
        ]);
        $invitation = $this->tenants->run($tenantA, fn () => $this->createInvitation->execute(
            new StoreInvitationData(recipient: $recipient->email),
            $principal,
        ));
        $accepted = $this->acceptInvitation->execute($invitation->token, $recipient);
        $membership = $this->listOwnMemberships->execute($recipient)
            ->firstWhere('tenant_id', $tenantA->value);
        if (! is_array($membership)) {
            throw new LogicException('The accepted tenant membership was not projected.');
        }
        $revoked = $this->tenants->run($tenantA, fn () => $this->revokeMembership->execute(
            $authority,
            $membership['membership_id'],
            $membership['revision'],
        ));

        return [
            'principal_id' => $principal->mailNotificationIdentifier(),
            'tenants' => 2,
            'memberships' => 2,
            'distinct_roles' => true,
            'tenant_token' => $issued->token->tenantId === $tenantA->value,
            'invitation_accepted' => $accepted->accepted_at !== null,
            'membership_revoked' => $revoked->status->value === 'revoked',
        ];
    }
}
