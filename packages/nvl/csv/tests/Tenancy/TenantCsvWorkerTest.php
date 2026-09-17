<?php

declare(strict_types=1);

use Nvl\Csv\Jobs\ProcessCSVChunkJob;
use Nvl\Csv\ValueObjects\CSVWorkReference;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

test('tenant csv job serializes only a captured scalar work reference', function (): void {
    $tenant = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $work = new CSVWorkReference(
        '11111111-1111-4111-8111-111111111111',
        $tenant,
        'local',
        'tenants/'.$tenant.'/csv/11111111-1111-4111-8111-111111111111/manifest.json',
        'contacts',
        1,
    );
    $job = ProcessCSVChunkJob::fromTenantWork(
        $work,
        0,
        new TenantJobEnvelope(new TenantContextSnapshot(TenantContextMode::Tenant, new TenantId($tenant))),
    );
    $payload = serialize($job);

    expect($payload)->not->toContain('rowProcessor')
        ->and(unserialize($payload))->toBeInstanceOf(ProcessCSVChunkJob::class);
});
