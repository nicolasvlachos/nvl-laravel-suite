<?php

declare(strict_types=1);

namespace Nvl\Csv\Tenancy;

use Illuminate\Contracts\Filesystem\Factory;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use Throwable;

/** Declares that CSV has no durable relational resources to adopt. */
final readonly class CsvAdoptionAdapter implements TenantAdoptionAdapter
{
    public function __construct(private Factory $storage) {}

    public function resources(): array
    {
        return [];
    }

    public function prepare(TenantAdoptionPlan $plan): void
    {
        $verification = $this->verify($plan);
        if (! $verification->passed()) {
            throw new TenantBoundaryViolation('CSV tenant readiness requires private local storage and drained legacy manifests.');
        }
    }

    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        return new TenantBackfillResult(null, 0);
    }

    /** @phpstan-impure */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $errors = [];
        try {
            $disk = $this->storage->disk('local');
            if ($disk->allFiles('csv-processing') !== []) {
                $errors[] = 'csv.legacy_manifests_not_drained';
            }
        } catch (Throwable) {
            $errors[] = 'csv.private_local_storage_unavailable';
        }

        return new TenantVerification($errors);
    }

    public function activate(TenantAdoptionPlan $plan): void
    {
        if (! $this->verify($plan)->passed()) {
            throw new TenantBoundaryViolation('CSV tenant readiness did not verify.');
        }
    }
}
