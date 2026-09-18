<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;
use Tests\Fixtures\TenantResourceCompositionTestCase;

/** Prepare one real, intentionally interrupted resource adoption. */
function prepareInterruptedTenantResourceAdoption(): array
{
    $operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare([
        'resource-composition-owners',
        'media',
        'metafields',
        'taxonomy',
    ], [], $operation);

    return [$coordinator, $plan, $operation];
}

it('resumes source or schema repair only with the immutable reviewed mapping', function (): void {
    [$coordinator, $plan, $operation] = prepareInterruptedTenantResourceAdoption();
    expect($coordinator->resume($plan->id))->toEqual($plan);
    $done = false;
    for ($batch = 0; $batch < 50 && ! $done; $batch++) {
        $done = $coordinator->backfill($plan, 100, $operation);
    }
    expect($done)->toBeTrue()
        ->and($coordinator->verify($plan)->passed())->toBeTrue();
});

it('rejects a changed mapping and requires restore or a new reviewed prepare', function (): void {
    [$coordinator, $plan] = prepareInterruptedTenantResourceAdoption();
    DB::table('nvl_tenancy_adoption_mappings')->insert([
        'run_id' => $plan->id,
        'resource' => 'test.resource-owners',
        'record_id' => 'changed-reviewed-input',
        'tenant_id' => TenantResourceCompositionTestCase::A,
        'metadata' => '[]',
    ]);

    expect(fn () => $coordinator->resume($plan->id))
        ->toThrow(TenantConfigurationInvalid::class);
    expect(fn () => $coordinator->prepare(
        ['resource-composition-owners', 'media', 'metafields', 'taxonomy'],
        [],
        new PlatformOperation('fixture.adoption', 'test', 'fixture'),
    ))->toThrow(TenantSchemaNotReady::class, 'resume its original run');
});

it('keeps prepared resources closed instead of manufacturing readiness markers', function (): void {
    prepareInterruptedTenantResourceAdoption();

    expect(fn () => app(TenantRunner::class)->run(
        new TenantId(TenantResourceCompositionTestCase::A),
        static fn (): array => app(TenantBoundary::class)->attributes('test.resource-owners'),
    ))->toThrow(TenantSchemaNotReady::class);
});
