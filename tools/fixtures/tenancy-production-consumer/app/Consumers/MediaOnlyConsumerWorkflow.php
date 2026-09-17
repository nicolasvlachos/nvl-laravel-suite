<?php

declare(strict_types=1);

namespace App\Consumers;

use App\Contracts\TenancyConsumerWorkflow;
use App\Tenancy\HostMembershipAccess;
use App\Tenancy\HostPrincipal;
use App\Tenancy\HostTenantDirectory;
use Illuminate\Filesystem\Filesystem;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantId;
use RuntimeException;

/** Runs the standalone Media profile with host-owned tenant admission and no Auth. */
final readonly class MediaOnlyConsumerWorkflow implements TenancyConsumerWorkflow
{
    /** Create the standalone profile around host adapters and shared Media proof. */
    public function __construct(
        private MediaProof $media,
        private HostMembershipAccess $memberships,
        private Filesystem $files,
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
        $tenantA = new TenantId(HostTenantDirectory::TENANT_A);
        $tenantB = new TenantId(HostTenantDirectory::TENANT_B);
        $adoption = $this->media->adopt(['consumer-articles', 'media'], $tenantA);
        $assetA = $this->media->seedTenant($tenantA, 'A');
        $assetB = $this->media->seedTenant($tenantB, 'B');
        $this->media->dispatchProbes($tenantA, $tenantB);
        $this->writeReport([
            'profile' => 'media-only',
            'tenant_a' => $tenantA->value,
            'tenant_b' => $tenantB->value,
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
        $memberA = new HostPrincipal('host-a');
        $memberB = new HostPrincipal('host-b');
        $this->memberships->assertMember($memberA, $tenantA);
        $this->memberships->assertMember($memberB, $tenantB);
        $foreignMembershipDenied = false;
        try {
            $this->memberships->assertMember($memberA, $tenantB);
        } catch (TenantBoundaryViolation) {
            $foreignMembershipDenied = true;
        }
        $checks = [
            'host_memberships_admit_a_b' => true,
            'host_foreign_membership_denied' => $foreignMembershipDenied,
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
        return ['passed' => ! in_array(false, $checks, true), 'checks' => $checks, 'profile' => 'media-only'];
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
            throw new RuntimeException('The standalone consumer report is invalid.');
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
