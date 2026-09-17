<?php

declare(strict_types=1);

namespace Nvl\Csv\Tenancy;

use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Declares that CSV has no durable relational resources to adopt. */
final readonly class CsvAdoptionAdapter implements TenantAdoptionAdapter
{
    public function resources(): array
    {
        return [];
    }

    public function prepare(TenantAdoptionPlan $plan): void {}

    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        return new TenantBackfillResult(null, 0);
    }

    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        return new TenantVerification([]);
    }

    public function activate(TenantAdoptionPlan $plan): void {}
}
