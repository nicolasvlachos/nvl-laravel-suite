<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nvl\Content\Actions\ApplyContentDefinitionMigrationsAction;
use Nvl\Content\Actions\CreateContentBlockAction;
use Nvl\Content\Actions\DeleteContentPlacementAction;
use Nvl\Content\Actions\PlaceContentBlockAction;
use Nvl\Content\Actions\PlanContentDefinitionMigrationsAction;
use Nvl\Content\Actions\PublishContentBlockAction;
use Nvl\Content\Actions\ReplaceContentPlacementAction;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Actions\UpdateContentBlockAction;
use Nvl\Content\Actions\UpdateContentPlacementAction;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Content\Data\Mutations\UpdateContentBlockData;
use Nvl\Content\Data\Mutations\UpdateContentPlacementData;
use Nvl\Content\FieldTypes\MediaFieldTypeAdapter;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Schema\ContentDefinitionSource;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Content\Services\ContentFieldTypeRegistry;
use Nvl\Content\Services\ContentOwnerDeletion;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Support\ContentOwnerDeletionBridge;
use Nvl\Content\Tests\Fixtures\TestContentOwner;
use Nvl\Content\Tests\Fixtures\TestSoftDeletingContentOwner;
use Nvl\Content\Tests\Fixtures\TestStringContentOwner;
use Nvl\Media\Actions\DeleteMediaAction;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Exceptions\MediaInUseException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

beforeEach(function (): void {
    Storage::fake('public');
    app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system());
});

function placementMediaImage(): Media
{
    return Media::factory()->create([
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'type' => MediaType::IMAGE,
        'is_public' => true,
        'visibility' => MediaVisibility::Public,
        'status' => MediaLifecycleStatus::Available,
    ]);
}

function placementMediaBlock(string $key, ?Media $baseImage = null): ContentBlock
{
    return app(CreateContentBlockAction::class)->execute(new CreateContentBlockData(
        definition: 'hero',
        key: $key,
        scope: 'site',
        scopeKey: 'main-site',
        values: $baseImage === null ? [] : ['image' => $baseImage->id],
        translations: ['en' => ['title' => 'Media placement']],
    ), ContentActorData::system());
}

it('protects public media reused by two placement overrides from ordinary deletion', function (): void {
    Storage::fake('public');
    $actor = ContentActorData::system();
    app(SyncContentDefinitionsAction::class)->execute($actor);
    $media = Media::factory()->create([
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'type' => MediaType::IMAGE,
        'is_public' => true,
        'visibility' => MediaVisibility::Public,
        'status' => MediaLifecycleStatus::Available,
    ]);

    foreach (['one', 'two'] as $key) {
        $block = app(CreateContentBlockAction::class)->execute(new CreateContentBlockData(
            definition: 'hero',
            key: 'placed-'.$key,
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'title']],
        ), $actor);
        $owner = TestContentOwner::query()->create(['name' => $key]);
        app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
            key: 'placement',
            overrides: ['image' => $media->id],
        ), $actor);
    }

    expect(fn () => app(DeleteMediaAction::class)->execute($media))
        ->toThrow(MediaInUseException::class);
});

it('reconciles override association identity and removes only placement usages when unplaced', function (): void {
    $actor = ContentActorData::system();
    $first = placementMediaImage();
    $second = placementMediaImage();
    $block = placementMediaBlock('media-lifecycle', $first);
    $owner = TestContentOwner::query()->create(['name' => 'Lifecycle page']);
    $placement = app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
        key: 'media',
        overrides: ['image' => $first->id],
    ), $actor);
    $association = MediaAssociation::query()->where('associable_type', $placement->getMorphClass())
        ->where('associable_id', $placement->id)->sole();

    expect($association->media_id)->toBe($first->id)
        ->and($association->locale)->toBeNull()
        ->and($association->metadata['field_path'])->toBe('image')
        ->and($first->associations()->count())->toBe(2);

    $placement = app(UpdateContentPlacementAction::class)->execute($placement, new UpdateContentPlacementData(
        expectedRevision: $placement->revision,
        region: 'main',
        parentId: null,
        sortOrder: 0,
        isVisible: true,
        overrides: ['image' => $second->id],
    ), $actor);

    expect($first->associations()->count())->toBe(1)
        ->and($second->associations()->sole()->associable_id)->toBe($placement->id);

    app(DeleteContentPlacementAction::class)->execute($placement, $placement->revision, $actor);

    expect($second->associations()->count())->toBe(0)
        ->and($first->associations()->sole()->associable_id)->toBe($block->id)
        ->and(Media::query()->count())->toBe(2);
});

it('drops override Media usages when replacement treats the same value as plain text', function (): void {
    $actor = ContentActorData::system();
    $media = placementMediaImage();
    $block = placementMediaBlock('replace-source');
    $owner = TestContentOwner::query()->create(['name' => 'Replacement page']);
    $placement = app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
        key: 'media',
        overrides: ['image' => $media->id],
    ), $actor);
    app(ContentDefinitionRegistry::class)->register(new ContentDefinitionSource(
        key: 'text-image', name: 'Text image', description: null, category: 'testing', version: 1, view: null,
        schema: ['fields' => [['key' => 'image', 'type' => 'text', 'label' => 'Image identifier']]],
        allowedScopes: ['site'],
    ));
    app(SyncContentDefinitionsAction::class)->execute($actor);
    $replacement = app(CreateContentBlockAction::class)->execute(new CreateContentBlockData(
        definition: 'text-image', key: 'replace-target', scope: 'site', scopeKey: 'main-site',
    ), $actor);

    app(ReplaceContentPlacementAction::class)->execute(
        $owner, 'default', $placement->id, $replacement->id, $placement->revision, $actor,
    );

    expect($media->associations()->count())->toBe(0)
        ->and($placement->refresh()->overrides)->toBe(['image' => $media->id]);
});

it('removes all placement usages when an owner is hard deleted without deleting binaries', function (): void {
    $actor = ContentActorData::system();
    $media = placementMediaImage();
    $block = placementMediaBlock('owner-delete');
    $owner = TestContentOwner::query()->create(['name' => 'Deleted page']);
    $parent = app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
        key: 'parent', overrides: ['image' => $media->id],
    ), $actor);
    app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
        key: 'child', parentId: $parent->id, overrides: ['image' => $media->id],
    ), $actor);

    expect(fn () => app(DeleteContentPlacementAction::class)->execute($parent, $parent->revision, $actor))
        ->toThrow(InvalidArgumentException::class, 'with children');
    expect($media->associations()->count())->toBe(2);

    $owner->delete();

    expect(ContentPlacement::query()->count())->toBe(0)
        ->and($media->associations()->count())->toBe(0)
        ->and(Media::query()->whereKey($media->id)->exists())->toBeTrue();
});

it('preserves placements and their usages when an owner deletion is canceled', function (): void {
    $media = placementMediaImage();
    $block = placementMediaBlock('canceled-owner-delete');
    $owner = TestContentOwner::query()->create(['name' => 'Canceled page']);
    app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
        key: 'media', overrides: ['image' => $media->id],
    ), ContentActorData::system());
    TestContentOwner::deleting(static fn (): bool => false);

    expect($owner->delete())->toBeFalse()
        ->and(TestContentOwner::query()->whereKey($owner->id)->exists())->toBeTrue()
        ->and(ContentPlacement::query()->count())->toBe(1)
        ->and($media->associations()->count())->toBe(1);
});

it('rolls back owner deletion and detached usages when placement cleanup fails', function (): void {
    $media = placementMediaImage();
    $block = placementMediaBlock('failed-owner-delete');
    $owner = TestContentOwner::query()->create(['name' => 'Failed deletion page']);
    app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
        key: 'media', overrides: ['image' => $media->id],
    ), ContentActorData::system());
    ContentPlacement::deleting(static function (): void {
        throw new RuntimeException('Placement cleanup failed.');
    });

    expect(fn () => $owner->delete())->toThrow(RuntimeException::class, 'Placement cleanup failed.');

    expect($owner->exists)->toBeTrue()
        ->and(TestContentOwner::query()->whereKey($owner->id)->exists())->toBeTrue()
        ->and(ContentPlacement::query()->count())->toBe(1)
        ->and($media->associations()->count())->toBe(1);
});

it('preserves usages on owner soft deletion and cleans them on force deletion', function (): void {
    Schema::table('content_test_owners', static function (Blueprint $table): void {
        $table->softDeletes();
    });
    app(ContentOwnerRegistry::class)->register('soft-page', TestSoftDeletingContentOwner::class);
    $media = placementMediaImage();
    $block = placementMediaBlock('soft-deleted-owner');
    $owner = TestSoftDeletingContentOwner::query()->create(['name' => 'Soft-deleted page']);
    app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
        key: 'media', overrides: ['image' => $media->id],
    ), ContentActorData::system());

    $owner->delete();

    expect($owner->trashed())->toBeTrue()
        ->and(ContentPlacement::query()->count())->toBe(1)
        ->and($media->associations()->count())->toBe(1);

    $owner->forceDelete();

    expect(TestSoftDeletingContentOwner::withTrashed()->whereKey($owner->id)->exists())->toBeFalse()
        ->and(ContentPlacement::query()->count())->toBe(0)
        ->and($media->associations()->count())->toBe(0);
});

it('authorizes override Media against the registered content owner', function (): void {
    $media = placementMediaImage();
    $block = placementMediaBlock('owner-policy');
    $owner = TestContentOwner::query()->create(['name' => 'Authorized page']);
    $authorization = Mockery::mock(MediaAuthorization::class);
    $authorization->shouldReceive('allows')->atLeast()->once()->andReturnUsing(
        static function (MediaActorData $actor, MediaAbility $ability, ?Media $asset, ?Model $target) use ($owner, $media): bool {
            expect($target)->toBeInstanceOf(TestContentOwner::class)
                ->and($target?->getKey())->toBe($owner->id)
                ->and($asset?->id)->toBe($media->id)
                ->and($ability)->toBe(MediaAbility::Reuse);

            return true;
        },
    );
    app()->instance(MediaAuthorization::class, $authorization);
    $original = app(ContentFieldTypeRegistry::class);
    $registry = new ContentFieldTypeRegistry;

    foreach ($original->aliases() as $alias) {
        $registry->register($alias === 'media'
            ? new MediaFieldTypeAdapter(false, $authorization)
            : $original->get($alias));
    }

    app()->instance(ContentFieldTypeRegistry::class, $registry);

    app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
        key: 'media', overrides: ['image' => $media->id],
    ), ContentActorData::system());
});

it('fails closed when owner cleanup cannot share the Content connection', function (): void {
    $media = placementMediaImage();
    $block = placementMediaBlock('cross-connection-owner');
    $owner = TestContentOwner::query()->create(['name' => 'Cross-connection page']);
    app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
        key: 'media', overrides: ['image' => $media->id],
    ), ContentActorData::system());
    $defaultConnection = DB::getDefaultConnection();
    config()->set('database.connections.content-owner-alias', config("database.connections.{$defaultConnection}"));
    try {
        DB::connection('content-owner-alias')->setPdo(DB::connection()->getPdo());
        $owner->setConnection('content-owner-alias');

        expect(fn () => $owner->delete())->toThrow(InvalidArgumentException::class, 'same named database connection');

        expect(TestContentOwner::query()->whereKey($owner->id)->exists())->toBeTrue()
            ->and(ContentPlacement::query()->count())->toBe(1)
            ->and($media->associations()->count())->toBe(1);
    } finally {
        DB::purge('content-owner-alias');
    }
});

it('allows deletion of an unregistered Content-capable owner without placements', function (): void {
    $owner = TestStringContentOwner::query()->create(['id' => 'unregistered', 'name' => 'Unregistered owner']);

    expect($owner->delete())->toBeTrue()
        ->and(TestStringContentOwner::query()->whereKey('unregistered')->exists())->toBeFalse();
});

it('rolls back detached usages when placement deletion is vetoed', function (bool $deleteOwner): void {
    $media = placementMediaImage();
    $block = placementMediaBlock('vetoed-placement-delete');
    $owner = TestContentOwner::query()->create(['name' => 'Vetoed deletion page']);
    $placement = app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
        key: 'media', overrides: ['image' => $media->id],
    ), ContentActorData::system());
    ContentPlacement::deleting(static fn (): bool => false);

    $delete = $deleteOwner
        ? fn (): ?bool => $owner->delete()
        : fn () => app(DeleteContentPlacementAction::class)->execute($placement, $placement->revision, ContentActorData::system());

    expect($delete)->toThrow(InvalidArgumentException::class, 'deletion was canceled');

    expect(TestContentOwner::query()->whereKey($owner->id)->exists())->toBeTrue()
        ->and(ContentPlacement::query()->whereKey($placement->id)->exists())->toBeTrue()
        ->and($media->associations()->count())->toBe(1);
})->with(['owner cleanup' => true, 'explicit unplacement' => false]);

it('keeps retained soft-deleted owner placements valid during reusable block mutations', function (string $operation): void {
    Schema::table('content_test_owners', static function (Blueprint $table): void {
        $table->softDeletes();
    });
    $owners = app(ContentOwnerRegistry::class);
    $owners->register('soft-page', TestSoftDeletingContentOwner::class);
    $actor = ContentActorData::system();
    $media = placementMediaImage();
    $block = placementMediaBlock('retained-owner-'.$operation);
    $retainedOwner = TestSoftDeletingContentOwner::query()->create(['name' => 'Retained owner']);
    $liveOwner = TestContentOwner::query()->create(['name' => 'Live owner']);

    foreach ([$retainedOwner, $liveOwner] as $owner) {
        app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
            key: 'media', overrides: ['image' => $media->id],
        ), $actor);
    }

    $retainedOwner->delete();

    expect(fn () => $owners->resolve('soft-page', $retainedOwner->id))
        ->toThrow(InvalidArgumentException::class, 'does not exist');

    if ($operation === 'update') {
        app(UpdateContentBlockAction::class)->execute($block, new UpdateContentBlockData(
            expectedRevision: $block->revision, metadata: ['reviewed' => true],
        ), $actor);
    } elseif ($operation === 'publish') {
        app(PublishContentBlockAction::class)->execute($block, $block->revision, $actor);
    } else {
        $block->forceFill(['definition_version' => 1])->save();
        $plan = app(PlanContentDefinitionMigrationsAction::class)->execute($actor);
        app(ApplyContentDefinitionMigrationsAction::class)->execute($plan, $actor);
    }

    expect($block->refresh()->revision)->toBe(2)
        ->and(ContentPlacement::query()->where('content_block_id', $block->id)->count())->toBe(2)
        ->and($media->associations()->count())->toBe(2);
})->with(['update', 'publish', 'migrate']);

it('provides a stable placement association identity under strict morph maps', function (bool $hasMedia): void {
    $previous = Relation::morphMap();
    $required = Relation::requiresMorphMap();
    Relation::enforceMorphMap(['content-block' => ContentBlock::class], merge: true);

    try {
        $block = placementMediaBlock('strict-morph-map');
        $owner = TestContentOwner::query()->create(['name' => 'Strict map page']);
        $media = $hasMedia ? placementMediaImage() : null;
        $placement = app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
            key: 'media', overrides: $media === null ? [] : ['image' => $media->id],
        ), ContentActorData::system());

        expect($placement->getMorphClass())->toBe('nvl-content-placement');

        if ($media !== null) {
            expect($media->associations()->sole()->associable_type)->toBe('nvl-content-placement');
        }
    } finally {
        Relation::morphMap($previous, merge: false);
        Relation::requireMorphMap($required);
    }
})->with(['empty overrides' => false, 'Media override' => true]);

it('requires the current provider runtime for manually instantiated owner cleanup', function (): void {
    $owner = new TestContentOwner(['name' => 'Manually constructed owner']);
    $owner->save();
    ContentOwnerDeletionBridge::clear();

    try {
        expect(fn () => $owner->delete())->toThrow(LogicException::class, 'booted Content provider');
        expect(TestContentOwner::query()->whereKey($owner->id)->exists())->toBeTrue();
    } finally {
        ContentOwnerDeletionBridge::use(app(ContentOwnerDeletion::class));
    }

    expect($owner->delete())->toBeTrue();
});
