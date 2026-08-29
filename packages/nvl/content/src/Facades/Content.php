<?php

declare(strict_types=1);

namespace Nvl\Content\Facades;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Nvl\Content\Content as ContentEngine;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentBlockData;
use Nvl\Content\Data\ContentCompositionSnapshotData;
use Nvl\Content\Data\ContentDefinitionData;
use Nvl\Content\Data\ContentDefinitionMigrationPlanData;
use Nvl\Content\Data\ContentDefinitionMigrationResultData;
use Nvl\Content\Data\ContentDefinitionSyncPlanData;
use Nvl\Content\Data\ContentEditorData;
use Nvl\Content\Data\ContentFieldPresetData;
use Nvl\Content\Data\ContentPlacementData;
use Nvl\Content\Data\ContentScopeData;
use Nvl\Content\Data\ContentScopeResolutionData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Content\Data\Mutations\UpdateContentBlockData;
use Nvl\Content\Data\Mutations\UpdateContentPlacementData;
use Nvl\Content\Data\RenderedContentCompositionData;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Filterable\Data\FilterSet;

/**
 * Laravel facade for the canonical model-first Content application surface.
 *
 * @method static Collection<int, ContentDefinitionData> definitions(ContentActorData $actor)
 * @method static Collection<int, ContentFieldPresetData> presets(ContentActorData $actor)
 * @method static LengthAwarePaginator<int, ContentBlockData> blocks(FilterSet $filters, ContentActorData $actor, int $perPage = 25)
 * @method static ContentBlockData block(ContentBlock|string $block, ContentActorData $actor)
 * @method static ContentScopeResolutionData resolveScopes(list<ContentScopeData> $scopes, string $locale, ContentActorData $actor, ?int $limit = null, bool $publicOnly = true)
 * @method static ContentDefinitionSyncPlanData syncDefinitions(ContentActorData $actor, bool $dryRun = false)
 * @method static ContentDefinitionMigrationPlanData planDefinitionMigrations(ContentActorData $actor, ?string $definition = null, ?int $limit = null)
 * @method static ContentDefinitionMigrationResultData applyDefinitionMigrations(ContentDefinitionMigrationPlanData $plan, ContentActorData $actor)
 * @method static ContentBlock createBlock(CreateContentBlockData $data, ContentActorData $actor)
 * @method static ContentBlock updateBlock(ContentBlock|string $block, UpdateContentBlockData $data, ContentActorData $actor)
 * @method static ContentBlock publishBlock(ContentBlock|string $block, int $expectedRevision, ContentActorData $actor)
 * @method static ContentBlock archiveBlock(ContentBlock|string $block, int $expectedRevision, ContentActorData $actor)
 * @method static void deleteBlock(ContentBlock|string $block, int $expectedRevision, ContentActorData $actor)
 * @method static ContentBlock restoreBlock(ContentBlock|string $block, int $expectedRevision, ContentActorData $actor)
 * @method static Collection<int, string> groups(Model&ContentOwner $owner, ContentActorData $actor)
 * @method static Collection<int, ContentPlacementData> placements(Model&ContentOwner $owner, string $group, ContentActorData $actor)
 * @method static ContentEditorData editor(Model&ContentOwner $owner, string $group, ContentActorData $actor)
 * @method static ContentPlacement place(ContentBlock|string $block, Model&ContentOwner $owner, string $group, PlaceContentBlockData $data, ContentActorData $actor)
 * @method static ContentPlacement updatePlacement(ContentPlacement|string $placement, UpdateContentPlacementData $data, ContentActorData $actor)
 * @method static void deletePlacement(ContentPlacement|string $placement, int $expectedRevision, ContentActorData $actor)
 * @method static RenderedContentCompositionData render(Model&ContentOwner $owner, string $group, string $locale, ContentActorData $actor, bool $publicOnly = true)
 * @method static ContentCompositionSnapshotData capture(Model&ContentOwner $owner, string $group, ContentActorData $actor, bool $publishing = false)
 * @method static RenderedContentCompositionData renderSnapshot(ContentCompositionSnapshotData $snapshot, string $locale, ContentActorData $actor)
 *
 * @see ContentEngine
 */
final class Content extends Facade
{
    /**
     * Return the Content engine container binding.
     */
    protected static function getFacadeAccessor(): string
    {
        return ContentEngine::class;
    }
}
