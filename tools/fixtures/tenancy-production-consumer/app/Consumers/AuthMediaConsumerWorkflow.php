<?php

declare(strict_types=1);

namespace App\Consumers;

use App\Contracts\TenancyConsumerWorkflow;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Nvl\Auth\Actions\Memberships\ListOwnMembershipsAction;
use Nvl\Auth\Actions\Memberships\ProvisionTenantOwnerAction;
use Nvl\Auth\Actions\Rbac\ListRoleOptionsAction;
use Nvl\Auth\Actions\Rbac\SynchronizePermissionCatalogAction;
use Nvl\Auth\Actions\Rbac\SynchronizeRoleTemplatesAction;
use Nvl\Auth\Actions\Users\ShowProfileAction;
use Nvl\Auth\Actions\Users\SyncUserRolesAction;
use Nvl\Auth\Data\Display\RoleOptionData;
use Nvl\Auth\Data\Mutations\SyncUserRolesData;
use Nvl\Auth\ValueObjects\SubjectReference;
use Nvl\Auth\ValueObjects\SystemMutationContext;
use Nvl\Tenancy\Actions\ProvisionTenantAction;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;
use RuntimeException;

/** Runs the full Auth plus Media sealed consumer profile. */
final readonly class AuthMediaConsumerWorkflow implements TenancyConsumerWorkflow
{
    /** Create the full profile around its public package Actions. */
    public function __construct(
        private MediaProof $media,
        private Filesystem $files,
        private ProvisionTenantAction $provisionTenant,
        private TenantRunner $tenants,
        private ProvisionTenantOwnerAction $provisionOwner,
        private SynchronizePermissionCatalogAction $synchronizePermissions,
        private SynchronizeRoleTemplatesAction $synchronizeRoles,
        private SyncUserRolesAction $syncRoles,
        private ShowProfileAction $showProfile,
        private ListOwnMembershipsAction $memberships,
        private ListRoleOptionsAction $roles,
    ) {}

    /** @return array{passed: bool, checks: array<string, bool>, profile: string} */
    public function execute(string $phase): array
    {
        return match ($phase) {
            'seed' => $this->seed(),
            'verify' => $this->verify(),
            default => throw new RuntimeException('The smoke phase must be seed or verify.'),
        };
    }

    /** @return array{passed: bool, checks: array<string, bool>, profile: string} */
    private function seed(): array
    {
        $operation = $this->media->operation('provision');
        $tenantA = $this->provisionTenant->execute('Production consumer A', $operation)->id;
        $tenantB = $this->provisionTenant->execute('Production consumer B', $operation)->id;
        $principal = User::forceCreate([
            'name' => 'Tenancy consumer principal',
            'email' => 'principal@tenancy-consumer.test',
            'email_verified_at' => now(),
            'password' => null,
            'is_active' => true,
            'locale' => 'en',
            'timezone' => 'UTC',
            'profile' => [],
            'preferences' => [],
        ]);
        $adoption = $this->media->adopt(['activity', 'auth', 'consumer-articles', 'media'], $tenantA);
        $this->tenants->platform(
            $this->media->operation('rbac-catalog'),
            fn (): int => $this->synchronizePermissions->execute($principal),
        );
        $authority = new SystemMutationContext(
            reason: 'tenancy-production-consumer',
            correlationId: 'tenancy-production-consumer-v1',
        );
        $roleIds = [];
        foreach ([$tenantA, $tenantB] as $tenant) {
            $roleIds[$tenant->value] = $this->tenants->run($tenant, function () use ($authority, $principal): string {
                $this->provisionOwner->execute($authority, SubjectReference::fromAuthenticatable($principal));
                $this->synchronizeRoles->execute($principal);
                $this->syncRoles->execute(
                    $authority,
                    $principal,
                    new SyncUserRolesData(['tenancy-consumer-editor']),
                );

                return $this->consumerRoleId($principal);
            });
        }
        $assetA = $this->media->seedTenant($tenantA, 'A');
        $assetB = $this->media->seedTenant($tenantB, 'B');
        $this->media->dispatchProbes($tenantA, $tenantB);
        $this->writeReport([
            'profile' => 'auth-media',
            'tenant_a' => $tenantA->value,
            'tenant_b' => $tenantB->value,
            'principal_id' => (string) $principal->getKey(),
            'role_ids' => $roleIds,
            'asset_a' => $assetA,
            'asset_b' => $assetB,
            ...$adoption,
        ]);

        return $this->result([
            'seeded' => true,
            'adoption_interrupted' => $adoption['adoption_interrupted'],
            'adoption_resumed' => $adoption['adoption_resumed'],
        ]);
    }

    /** @return array{passed: bool, checks: array<string, bool>, profile: string} */
    private function verify(): array
    {
        $report = $this->readReport();
        $tenantA = new TenantId($this->string($report, 'tenant_a'));
        $tenantB = new TenantId($this->string($report, 'tenant_b'));
        $principal = User::query()->findOrFail($this->string($report, 'principal_id'));
        $membershipRows = $this->memberships->execute($principal);
        $roleIds = $this->stringMap($report, 'role_ids');
        $currentRoleIds = [];
        $assignedRoleIds = [];
        foreach ([$tenantA, $tenantB] as $tenant) {
            $currentRoleIds[$tenant->value] = $this->tenants->run(
                $tenant,
                fn (): string => $this->consumerRoleId($principal),
            );
            $assignedRoleIds[$tenant->value] = $this->tenants->run(
                $tenant,
                fn (): array => $this->showProfile->execute($principal)
                    ->roles
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all(),
            );
        }
        $expectedMembershipTenants = [$tenantA->value, $tenantB->value];
        sort($expectedMembershipTenants);
        $checks = [
            'memberships_project_per_tenant' => $membershipRows->pluck('tenant_id')->all() === $expectedMembershipTenants,
            'roles_project_per_tenant' => $currentRoleIds === $roleIds
                && $currentRoleIds[$tenantA->value] !== $currentRoleIds[$tenantB->value],
            'role_assignments_project_per_tenant' => $assignedRoleIds === [
                $tenantA->value => [$roleIds[$tenantA->value]],
                $tenantB->value => [$roleIds[$tenantB->value]],
            ],
            ...$this->media->verify(
                $tenantA,
                $tenantB,
                $this->asset($report, 'asset_a'),
                $this->asset($report, 'asset_b'),
            ),
        ];

        return $this->result($checks);
    }

    /** @param array<string, bool> $checks @return array{passed: bool, checks: array<string, bool>, profile: string} */
    private function result(array $checks): array
    {
        return ['passed' => ! in_array(false, $checks, true), 'checks' => $checks, 'profile' => 'auth-media'];
    }

    /** Resolve the fixture role without assuming it is the package's only template. */
    private function consumerRoleId(User $principal): string
    {
        $role = $this->roles->execute($principal)->first(
            static fn (RoleOptionData $option): bool => $option->name === 'tenancy-consumer-editor',
        );
        if ($role === null) {
            throw new RuntimeException('The tenant-local consumer role is unavailable.');
        }

        return $role->id;
    }

    /** @param array<string, mixed> $report */
    private function writeReport(array $report): void
    {
        $this->files->ensureDirectoryExists(dirname($this->reportPath()));
        $this->files->put($this->reportPath(), json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /** @return array<string, mixed> */
    private function readReport(): array
    {
        $report = json_decode($this->files->get($this->reportPath()), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($report)) {
            throw new RuntimeException('The full consumer report is invalid.');
        }

        return $report;
    }

    /** Return the persistent phase report path. */
    private function reportPath(): string
    {
        return storage_path('app/private/tenancy-consumer/report.json');
    }

    /** @param array<string, mixed> $report */
    private function string(array $report, string $key): string
    {
        $value = $report[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The report field [{$key}] is invalid.");
        }

        return $value;
    }

    /** @param array<string, mixed> $report @return array<string, string> */
    private function stringMap(array $report, string $key): array
    {
        $value = $report[$key] ?? null;
        if (! is_array($value)) {
            throw new RuntimeException("The report field [{$key}] is invalid.");
        }

        /** @var array<string, string> $value */
        return $value;
    }

    /** @param array<string, mixed> $report @return array{article_id: string, media_id: string, path: string, digest: string, binary_hash: string, association_count: int} */
    private function asset(array $report, string $key): array
    {
        $value = $report[$key] ?? null;
        if (! is_array($value)) {
            throw new RuntimeException("The report asset [{$key}] is invalid.");
        }

        /** @var array{article_id: string, media_id: string, path: string, digest: string, binary_hash: string, association_count: int} $value */
        return $value;
    }
}
