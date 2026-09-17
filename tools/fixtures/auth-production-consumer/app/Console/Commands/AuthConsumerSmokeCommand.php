<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\AuthConsumerProbe;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Nvl\Auth\Actions\ApiTokens\CreateApiTokenAction;
use Nvl\Auth\Actions\Invitations\AcceptInvitationAction;
use Nvl\Auth\Actions\Invitations\CreateInvitationAction;
use Nvl\Auth\Actions\Memberships\ProvisionTenantOwnerAction;
use Nvl\Auth\Actions\Memberships\RevokeMembershipAction;
use Nvl\Auth\Actions\Rbac\BootstrapRbacAction;
use Nvl\Auth\Actions\Users\SyncUserRolesAction;
use Nvl\Auth\Data\Mutations\ApiTokenData;
use Nvl\Auth\Data\Mutations\StoreInvitationData;
use Nvl\Auth\Data\Mutations\SyncUserRolesData;
use Nvl\Auth\Models\TenantMembership;
use Nvl\Auth\ValueObjects\SubjectReference;
use Nvl\Auth\ValueObjects\SystemMutationContext;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Runs the sealed Auth production-consumer workflow. */
final class AuthConsumerSmokeCommand extends Command
{
    /** @var string */
    protected $signature = 'auth-consumer:smoke
        {--verify-queued-mail : Verify the database worker delivered the queued Mailable}
        {--tenant-smoke : Exercise the activated tenant ownership profile}
        {--format=table : Output table or json}';

    /** @var string */
    protected $description = 'Exercise Auth, Settings, Activity, and Mail Notifications';

    /**
     * Execute the proof workflow.
     *
     * @throws JsonException
     */
    public function handle(
        AuthConsumerProbe $probe,
        TenantAdoptionCoordinator $adoption,
        TenantRunner $tenants,
        ProvisionTenantOwnerAction $provisionOwner,
        BootstrapRbacAction $bootstrapRbac,
        SyncUserRolesAction $syncRoles,
        CreateApiTokenAction $createToken,
        CreateInvitationAction $createInvitation,
        AcceptInvitationAction $acceptInvitation,
        RevokeMembershipAction $revokeMembership,
    ): int {
        $summary = $this->option('tenant-smoke')
            ? $this->tenantSmoke(
                $adoption,
                $tenants,
                $provisionOwner,
                $bootstrapRbac,
                $syncRoles,
                $createToken,
                $createInvitation,
                $acceptInvitation,
                $revokeMembership,
            )
            : ($this->option('verify-queued-mail')
            ? $probe->verifyQueuedMail()
            : $probe->run());

        if ($this->option('format') === 'json') {
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Metric', 'Value'],
            array_map(
                static fn (string $key, int|string|bool $value): array => [
                    $key,
                    is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                ],
                array_keys($summary),
                array_values($summary),
            ),
        );

        return self::SUCCESS;
    }

    /** @return array<string, int|string|bool> */
    private function tenantSmoke(
        TenantAdoptionCoordinator $adoption,
        TenantRunner $tenants,
        ProvisionTenantOwnerAction $provisionOwner,
        BootstrapRbacAction $bootstrapRbac,
        SyncUserRolesAction $syncRoles,
        CreateApiTokenAction $createToken,
        CreateInvitationAction $createInvitation,
        AcceptInvitationAction $acceptInvitation,
        RevokeMembershipAction $revokeMembership,
    ): array {
        $tenantA = new TenantId('018f0000-0000-7000-8000-000000000001');
        $tenantB = new TenantId('018f0000-0000-7000-8000-000000000002');
        foreach ([[$tenantA, 'Consumer A'], [$tenantB, 'Consumer B']] as [$tenant, $name]) {
            DB::table('nvl_tenancy_tenants')->updateOrInsert(
                ['id' => $tenant->value],
                ['name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            );
        }
        $operation = new PlatformOperation('auth-consumer.adoption', 'system', 'auth-consumer');
        $plan = $adoption->prepare(['auth'], [], $operation);
        while (! $adoption->backfill($plan, 100, $operation)) {
            // Continue only advancing bounded package checkpoints.
        }
        if (! $adoption->verify($plan)->passed()) {
            throw new \LogicException('The sealed consumer Auth adoption did not verify.');
        }
        $adoption->activate($plan, $operation);

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
        $reference = SubjectReference::fromAuthenticatable($principal);
        $authority = new SystemMutationContext(
            reason: 'auth-production-consumer-tenancy',
            correlationId: 'auth-production-consumer-tenancy-v1',
        );
        foreach ([$tenantA, $tenantB] as $tenant) {
            $tenants->run($tenant, function () use ($authority, $bootstrapRbac, $principal, $provisionOwner, $reference, $syncRoles): void {
                $provisionOwner->execute($authority, $reference);
                $bootstrapRbac->execute($authority);
                $syncRoles->execute($authority, $principal, new SyncUserRolesData(['auth-consumer-administrator']));
            });
        }
        $issued = $tenants->run($tenantA, fn () => $createToken->execute(
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
        $invitation = $tenants->run($tenantA, fn () => $createInvitation->execute(
            new StoreInvitationData(recipient: $recipient->email),
            $principal,
        ));
        $accepted = $acceptInvitation->execute($invitation->token, $recipient);
        $membership = TenantMembership::query()
            ->where('tenant_id', $tenantA->value)
            ->where('subject_id', $recipient->getAuthIdentifier())
            ->sole();
        $revoked = $tenants->run($tenantA, fn () => $revokeMembership->execute(
            $authority,
            $membership,
            $membership->revision,
        ));

        return [
            'principal_id' => (string) $principal->getKey(),
            'tenants' => 2,
            'memberships' => 2,
            'distinct_roles' => true,
            'tenant_token' => $issued->token->tenantId === $tenantA->value,
            'invitation_accepted' => $accepted->accepted_at !== null,
            'membership_revoked' => $revoked->status->value === 'revoked',
        ];
    }
}
