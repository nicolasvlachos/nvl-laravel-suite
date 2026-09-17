<?php

declare(strict_types=1);

namespace App\Consumers;

use App\Contracts\TenancyConsumerWorkflow;
use App\Models\TenantTaxonomyRecord;
use App\Tenancy\HostMembershipAccess;
use App\Tenancy\HostPrincipal;
use App\Tenancy\HostTenantDirectory;
use Illuminate\Filesystem\Filesystem;
use Nvl\Taxonomy\Actions\AttachTermsAction;
use Nvl\Taxonomy\Actions\CreateTermAction;
use Nvl\Taxonomy\Data\MutateTermPayload;
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
        $operation = new PlatformOperation('taxonomy-consumer.adopt', 'system', 'taxonomy-consumer');
        $plan = $this->adoption->prepare(['consumer-taxonomy-records', 'taxonomy'], [
            new TenantAssignment('consumer.taxonomy-records', $legacy->id, $tenantA),
        ], $operation);
        while (! $this->adoption->backfill($plan, 100, $operation)) {}
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
        if (! is_array($report)) {
            throw new RuntimeException('The Taxonomy report is invalid.');
        }
        $a = $report['records'][HostTenantDirectory::TENANT_A];
        $b = $report['records'][HostTenantDirectory::TENANT_B];
        $checks = ['equal_keys_distinct_ids' => $a['owner_id'] !== $b['owner_id'] && $a['term_id'] !== $b['term_id']];

        return ['passed' => ! in_array(false, $checks, true), 'checks' => $checks, 'profile' => 'taxonomy-only'];
    }
}
