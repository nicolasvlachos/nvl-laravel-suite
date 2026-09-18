<?php

declare(strict_types=1);

namespace App\Consumers;

use App\Contracts\TenancyConsumerWorkflow;
use App\Models\TenantTaxonomyRecord;
use App\Tenancy\HostMembershipAccess;
use App\Tenancy\HostPrincipal;
use App\Tenancy\HostTenantDirectory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Nvl\Taxonomy\Actions\AttachTermsAction;
use Nvl\Taxonomy\Actions\CreateTermAction;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Models\Term;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantId;
use RuntimeException;

/** Proves standalone Taxonomy with host UUID principals and no NVL Auth. */
final readonly class TaxonomyOnlyConsumerWorkflow implements TenancyConsumerWorkflow
{
    public function __construct(
        private TenantAdoptionCoordinator $adoption,
        private TenantRunner $tenants,
        private TenantBoundary $boundary,
        private CreateTermAction $createTerm,
        private AttachTermsAction $attachTerms,
        private HostMembershipAccess $memberships,
        private Filesystem $files,
    ) {}

    public function execute(string $phase): array
    {
        return $phase === 'seed' ? $this->seed() : $this->verify();
    }

    /** @return array{passed: bool, checks: array<string, bool>, profile: string} */
    private function seed(): array
    {
        $tenantA = new TenantId(HostTenantDirectory::TENANT_A);
        $tenantB = new TenantId(HostTenantDirectory::TENANT_B);
        $legacy = TenantTaxonomyRecord::query()->create(['slug' => 'legacy', 'title' => 'Legacy']);
        $operation = new PlatformOperation('tenancy-consumer.taxonomy-adopt', 'system', 'tenancy-production-consumer');
        $plan = $this->adoption->prepare(['consumer-taxonomy-records', 'taxonomy'], [
            new TenantAssignment('consumer.taxonomy-records', $legacy->id, $tenantA),
        ], $operation);
        while (! $this->adoption->backfill($plan, 100, $operation)) {
        }
        $this->adoption->activate($plan, $operation);
        $records = [];
        foreach ([$tenantA, $tenantB] as $tenant) {
            $records[$tenant->value] = $this->tenants->run($tenant, function (): array {
                $owner = new TenantTaxonomyRecord(['slug' => 'same-key', 'title' => 'Same title']);
                $owner->forceFill($this->boundary->attributes('consumer.taxonomy-records'));
                $owner->saveOrFail();
                $term = $this->createTerm->execute(new MutateTermPayload('category', 'same-slug', ['en' => ['name' => 'Same term']]));
                $this->attachTerms->execute($owner, 'category', [$term]);

                return ['owner_id' => $owner->id, 'term_id' => $term->id];
            });
        }
        $this->files->put(storage_path('app/private/tenancy-consumer/report.json'), json_encode(['records' => $records], JSON_THROW_ON_ERROR));

        return ['passed' => true, 'checks' => ['seeded' => true], 'profile' => 'taxonomy-only'];
    }

    /** @return array{passed: bool, checks: array<string, bool>, profile: string} */
    private function verify(): array
    {
        $this->memberships->assertMember(new HostPrincipal('host-a'), new TenantId(HostTenantDirectory::TENANT_A));
        $report = json_decode($this->files->get(storage_path('app/private/tenancy-consumer/report.json')), true, flags: JSON_THROW_ON_ERROR);
        $root = $this->associativeArray($report, 'Taxonomy report');
        $records = $this->associativeArray($root['records'] ?? null, 'Taxonomy records');
        $a = $this->associativeArray($records[HostTenantDirectory::TENANT_A] ?? null, 'tenant A Taxonomy record');
        $b = $this->associativeArray($records[HostTenantDirectory::TENANT_B] ?? null, 'tenant B Taxonomy record');
        $tenantA = new TenantId(HostTenantDirectory::TENANT_A);
        $tenantB = new TenantId(HostTenantDirectory::TENANT_B);
        $ownerA = $this->tenants->run(
            $tenantA,
            fn (): TenantTaxonomyRecord => $this->boundary
                ->query(TenantTaxonomyRecord::query(), 'consumer.taxonomy-records')
                ->findOrFail($a['owner_id']),
        );
        $ownerB = $this->tenants->run(
            $tenantB,
            fn (): TenantTaxonomyRecord => $this->boundary
                ->query(TenantTaxonomyRecord::query(), 'consumer.taxonomy-records')
                ->findOrFail($b['owner_id']),
        );
        $termA = $this->tenants->run($tenantA, fn (): Term => Term::query()->findOrFail($a['term_id']));
        $termB = $this->tenants->run($tenantB, fn (): Term => Term::query()->findOrFail($b['term_id']));
        $checks = [
            'equal_keys_distinct_ids' => $ownerA->id !== $ownerB->id && $termA->id !== $termB->id,
            'foreign_owner_denied' => $this->denied(fn (): TenantTaxonomyRecord => $this->tenants->run(
                $tenantB,
                fn (): TenantTaxonomyRecord => $this->boundary
                    ->query(TenantTaxonomyRecord::query(), 'consumer.taxonomy-records')
                    ->findOrFail($ownerA->id),
            )),
            'foreign_term_denied' => $this->denied(fn (): Term => $this->tenants->run(
                $tenantB,
                fn (): Term => Term::query()->findOrFail($termA->id),
            )),
        ];

        return ['passed' => ! in_array(false, $checks, true), 'checks' => $checks, 'profile' => 'taxonomy-only'];
    }

    private function denied(callable $operation): bool
    {
        try {
            $operation();

            return false;
        } catch (ModelNotFoundException|TenantBoundaryViolation) {
            return true;
        }
    }

    /** @return array<string, mixed> */
    private function associativeArray(mixed $value, string $name): array
    {
        if (! is_array($value)) {
            throw new RuntimeException("The {$name} is invalid.");
        }

        $record = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException("The {$name} is invalid.");
            }
            $record[$key] = $item;
        }

        return $record;
    }
}
