<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\TenantTaxonomyRecord;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Services\TenantAdoptionMappings;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Adopts fixture-owned standalone Taxonomy roots. */
final readonly class TenantTaxonomyRecordAdoptionAdapter implements TenantAdoptionAdapter
{
    public function __construct(private TenantAdoptionMappings $mappings) {}

    public function resources(): array
    {
        return ['consumer.taxonomy-records'];
    }

    public function prepare(TenantAdoptionPlan $plan): void {}

    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $assignments = $this->mappings->assignments($plan, 'consumer.taxonomy-records', $cursor, $limit);
        foreach ($assignments as $assignment) {
            TenantTaxonomyRecord::query()->whereKey($assignment->recordId)->whereNull('tenant_id')->update([
                'tenant_id' => $assignment->tenantId->value,
                'updated_at' => now(),
            ]);
        }

        return new TenantBackfillResult(count($assignments) === $limit ? $assignments[count($assignments) - 1]->recordId : null, count($assignments));
    }

    /** @phpstan-impure */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $errors = [];
        foreach (TenantTaxonomyRecord::query()->get(['id', 'tenant_id']) as $record) {
            if ($record->tenant_id === null || $record->tenant_id !== $this->mappings->tenantFor($plan, 'consumer.taxonomy-records', $record->id)->value) {
                $errors[] = 'consumer.taxonomy-records:'.$record->id;
            }
        }

        return new TenantVerification($errors);
    }

    public function activate(TenantAdoptionPlan $plan): void {}
}
