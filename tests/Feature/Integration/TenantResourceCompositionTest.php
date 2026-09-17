<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Actions\AttachMediaAction;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\Metafields\ListOwnerMetafieldsAction;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Taxonomy\Actions\AttachTermsAction;
use Nvl\Taxonomy\Actions\CreateTermAction;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;
use Tests\Fixtures\TenantResourceCompositionOwner;
use Tests\Fixtures\TenantResourceCompositionTestCase;

/** Adopt the complete three-package graph through real adapters and markers. */
function installTenantResourceComposition(): void
{
    $operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare([
        'resource-composition-owners',
        'media',
        'metafields',
        'taxonomy',
    ], [], $operation);
    $done = false;
    for ($batch = 0; $batch < 50 && ! $done; $batch++) {
        $done = $coordinator->backfill($plan, 100, $operation);
    }
    expect($done)->toBeTrue()
        ->and($coordinator->verify($plan)->passed())->toBeTrue();
    $coordinator->activate($plan, $operation);
    app(MaintenanceMode::class)->deactivate();
    Storage::fake('tenant-disk');
}

/** Run one composition callback under an exact tenant. */
function runTenantResourceComposition(string $tenant, Closure $callback): mixed
{
    return app(TenantRunner::class)->run(new TenantId($tenant), $callback);
}

/** Create a canonical composition owner. */
function tenantResourceOwner(string $tenant): TenantResourceCompositionOwner
{
    return runTenantResourceComposition($tenant, static function (): TenantResourceCompositionOwner {
        $owner = new TenantResourceCompositionOwner(['name' => 'Shared business key']);
        $owner->forceFill(app(TenantBoundary::class)->attributes('test.resource-owners'));
        $owner->save();

        return $owner->refresh();
    });
}

it('composes media metafields taxonomy and translations without cross-tenant reuse', function (): void {
    installTenantResourceComposition();
    $build = static function (string $tenant): array {
        $owner = tenantResourceOwner($tenant);

        return runTenantResourceComposition($tenant, static function () use ($owner): array {
            $media = app(UploadMediaAction::class)->execute(
                UploadedFile::fake()->createWithContent('same.txt', 'identical bytes'),
                'tenant-disk',
                $owner,
                new MediaSlot('default'),
                'same.txt',
                true,
                skipAutoVariations: true,
            );
            app(AttachMediaAction::class)->execute($media, $owner, dispatchVariations: false);
            $definition = app(CreateMetafieldDefinitionAction::class)->execute(CreateMetafieldDefinitionPayload::from([
                'namespace' => 'catalog',
                'key' => 'color',
                'type' => 'string',
                'assignment' => ['ownerType' => 'resource-owner', 'section' => 'general'],
                'translations' => ['en' => ['title' => 'Color'], 'bg' => ['title' => 'Цвят']],
            ]));
            $value = app(SetMetafieldAction::class)->execute($owner, 'catalog.color', 'blue');
            $term = app(CreateTermAction::class)->execute(new MutateTermPayload(
                'category',
                'shared-slug',
                ['en' => ['name' => 'Shared'], 'bg' => ['name' => 'Споделено']],
            ));
            app(AttachTermsAction::class)->execute($owner, 'category', [$term]);

            return compact('owner', 'media', 'definition', 'value', 'term');
        });
    };
    $a = $build(TenantResourceCompositionTestCase::A);
    $b = $build(TenantResourceCompositionTestCase::B);

    expect($a['media']->id)->not->toBe($b['media']->id)
        ->and($a['definition']->id)->not->toBe($b['definition']->id)
        ->and($a['term']->id)->not->toBe($b['term']->id);
    runTenantResourceComposition(TenantResourceCompositionTestCase::A, function () use ($a): void {
        $owner = TenantResourceCompositionOwner::query()
            ->with(['media', 'metafields.definition.translations', 'categories.translations'])
            ->findOrFail($a['owner']->id);
        expect($owner->getMedia())->toHaveCount(1)
            ->and(app(ListOwnerMetafieldsAction::class)->execute($owner))->toHaveCount(1)
            ->and($owner->hasTerm('category', 'shared-slug'))->toBeTrue();
    });

    expect(fn () => runTenantResourceComposition(
        TenantResourceCompositionTestCase::A,
        fn () => app(SetMetafieldAction::class)->execute($b['owner'], 'catalog.color', 'foreign'),
    ))->toThrow(TenantBoundaryViolation::class);
    expect(fn () => runTenantResourceComposition(
        TenantResourceCompositionTestCase::A,
        fn () => app(AttachTermsAction::class)->execute($a['owner'], 'category', [$b['term']]),
    ))->toThrow(TenantBoundaryViolation::class);
    expect(fn () => runTenantResourceComposition(
        TenantResourceCompositionTestCase::A,
        fn () => app(AttachMediaAction::class)->execute($b['media'], $a['owner']),
    ))->toThrow(TenantBoundaryViolation::class);
});

it('denies retained fully eager-loaded tenant A relations after switching to B', function (): void {
    installTenantResourceComposition();
    $owner = tenantResourceOwner(TenantResourceCompositionTestCase::A);
    $retained = runTenantResourceComposition(
        TenantResourceCompositionTestCase::A,
        fn () => TenantResourceCompositionOwner::query()->with(['media', 'metafields', 'categories'])->findOrFail($owner->id),
    );

    expect(fn () => runTenantResourceComposition(TenantResourceCompositionTestCase::B, fn () => $retained->getMedia()))
        ->toThrow(TenantBoundaryViolation::class);
    expect(fn () => runTenantResourceComposition(TenantResourceCompositionTestCase::B, fn () => app(ListOwnerMetafieldsAction::class)->execute($retained)))
        ->toThrow(TenantBoundaryViolation::class);
    expect(fn () => runTenantResourceComposition(TenantResourceCompositionTestCase::B, fn () => $retained->hasTerm('category', 'shared-slug')))
        ->toThrow(TenantBoundaryViolation::class);
});
