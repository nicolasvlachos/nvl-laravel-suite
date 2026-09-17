<?php

declare(strict_types=1);

use Nvl\Csv\Tenancy\CsvAdoptionAdapter;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

it('registers the tenant csv readiness adopter without inventing a data root', function (): void {
    expect(app(TenantAdoptionRegistry::class)->all()['csv'] ?? null)->toBe(CsvAdoptionAdapter::class)
        ->and(app(CsvAdoptionAdapter::class)->resources())->toBe([]);
});
