<?php

declare(strict_types=1);

namespace Nvl\Content;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Content\Actions\ApplyContentDefinitionMigrationsAction;
use Nvl\Content\Actions\ArchiveContentBlockAction;
use Nvl\Content\Actions\CreateContentBlockAction;
use Nvl\Content\Actions\DeleteContentBlockAction;
use Nvl\Content\Actions\DeleteContentPlacementAction;
use Nvl\Content\Actions\GetContentBlockAction;
use Nvl\Content\Actions\ListContentBlocksAction;
use Nvl\Content\Actions\ListContentDefinitionsAction;
use Nvl\Content\Actions\ListContentGroupsAction;
use Nvl\Content\Actions\ListContentPlacementsAction;
use Nvl\Content\Actions\ListContentPresetsAction;
use Nvl\Content\Actions\PlaceContentBlockAction;
use Nvl\Content\Actions\PlanContentDefinitionMigrationsAction;
use Nvl\Content\Actions\PublishContentBlockAction;
use Nvl\Content\Actions\ResolveContentScopesAction;
use Nvl\Content\Actions\RestoreContentBlockAction;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Actions\UpdateContentBlockAction;
use Nvl\Content\Actions\UpdateContentPlacementAction;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
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
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Services\ContentRenderer;
use Nvl\Content\Services\ContentSnapshotService;
use Nvl\Filterable\Data\FilterSet;

/**
 * Provides the canonical model-first application surface behind the Content facade.
 */
final readonly class Content
{
    public function __construct(
        private ListContentDefinitionsAction $listDefinitions,
        private ListContentPresetsAction $listPresets,
        private ListContentBlocksAction $listBlocks,
        private GetContentBlockAction $getBlock,
        private SyncContentDefinitionsAction $syncDefinitions,
        private PlanContentDefinitionMigrationsAction $planDefinitionMigrations,
        private ApplyContentDefinitionMigrationsAction $applyDefinitionMigrations,
        private ListContentGroupsAction $listGroups,
        private ListContentPlacementsAction $listPlacements,
        private CreateContentBlockAction $createBlock,
        private UpdateContentBlockAction $updateBlock,
        private PublishContentBlockAction $publishBlock,
        private ArchiveContentBlockAction $archiveBlock,
        private DeleteContentBlockAction $deleteBlock,
        private RestoreContentBlockAction $restoreBlock,
        private PlaceContentBlockAction $placeBlock,
        private UpdateContentPlacementAction $updatePlacement,
        private DeleteContentPlacementAction $deletePlacement,
        private ContentOwnerRegistry $owners,
        private ContentRenderer $renderer,
        private ContentSnapshotService $snapshots,
        private ResolveContentScopesAction $resolveScopes,
    ) {}

    /**
     * Return every active definition available to an authorized editor.
     *
     * @return Collection<int, ContentDefinitionData>
     */
    public function definitions(ContentActorData $actor): Collection
    {
        return $this->listDefinitions->execute($actor);
    }

    /**
     * Return every reusable semantic field preset available to an authorized editor.
     *
     * @return Collection<int, ContentFieldPresetData>
     */
    public function presets(ContentActorData $actor): Collection
    {
        return $this->listPresets->execute($actor);
    }

    /**
     * Return a filtered, authorized page of reusable Content blocks.
     *
     * @return LengthAwarePaginator<int, ContentBlock>
     */
    public function blocks(
        FilterSet $filters,
        ContentActorData $actor,
        int $perPage = 25,
    ): LengthAwarePaginator {
        return $this->listBlocks->execute($filters, $actor, $perPage);
    }

    /**
     * Resolve complete localized values through ordered scope fallback.
     *
     * @param  list<ContentScopeData>  $scopes
     */
    public function resolveScopes(
        array $scopes,
        string $locale,
        ContentActorData $actor,
        ?int $limit = null,
        bool $publicOnly = true,
    ): ContentScopeResolutionData {
        return $this->resolveScopes->execute(
            $scopes,
            $locale,
            $actor,
            $limit,
            $publicOnly,
        );
    }

    /**
     * Return one authorized reusable Content block.
     */
    public function block(
        ContentBlock|string $block,
        ContentActorData $actor,
    ): ContentBlock {
        return $this->getBlock->execute($block, $actor);
    }

    /**
     * Plan or apply synchronization of source-controlled Content definitions.
     */
    public function syncDefinitions(
        ContentActorData $actor,
        bool $dryRun = false,
    ): ContentDefinitionSyncPlanData {
        return $this->syncDefinitions->execute($actor, $dryRun);
    }

    /**
     * Build an exact, bounded, read-only definition migration plan.
     */
    public function planDefinitionMigrations(
        ContentActorData $actor,
        ?string $definition = null,
        ?int $limit = null,
    ): ContentDefinitionMigrationPlanData {
        return $this->planDefinitionMigrations->execute($actor, $definition, $limit);
    }

    /**
     * Atomically apply one exact definition migration plan.
     */
    public function applyDefinitionMigrations(
        ContentDefinitionMigrationPlanData $plan,
        ContentActorData $actor,
    ): ContentDefinitionMigrationResultData {
        return $this->applyDefinitionMigrations->execute($plan, $actor);
    }

    /**
     * Create one reusable draft block through the canonical mutation boundary.
     */
    public function createBlock(
        CreateContentBlockData $data,
        ContentActorData $actor,
    ): ContentBlock {
        return $this->createBlock->execute($data, $actor);
    }

    /**
     * Update one reusable block with optimistic concurrency.
     */
    public function updateBlock(
        ContentBlock|string $block,
        UpdateContentBlockData $data,
        ContentActorData $actor,
    ): ContentBlock {
        return $this->updateBlock->execute($block, $data, $actor);
    }

    /**
     * Publish one exact reusable block revision.
     */
    public function publishBlock(
        ContentBlock|string $block,
        int $expectedRevision,
        ContentActorData $actor,
    ): ContentBlock {
        return $this->publishBlock->execute($block, $expectedRevision, $actor);
    }

    /**
     * Archive one exact reusable block revision.
     */
    public function archiveBlock(
        ContentBlock|string $block,
        int $expectedRevision,
        ContentActorData $actor,
    ): ContentBlock {
        return $this->archiveBlock->execute($block, $expectedRevision, $actor);
    }

    /**
     * Soft-delete one exact unplaced block revision.
     */
    public function deleteBlock(
        ContentBlock|string $block,
        int $expectedRevision,
        ContentActorData $actor,
    ): void {
        $this->deleteBlock->execute($block, $expectedRevision, $actor);
    }

    /**
     * Restore one exact deleted block revision as a draft.
     */
    public function restoreBlock(
        ContentBlock|string $block,
        int $expectedRevision,
        ContentActorData $actor,
    ): ContentBlock {
        return $this->restoreBlock->execute($block, $expectedRevision, $actor);
    }

    /**
     * Return every existing composition group on the owner.
     *
     * @return Collection<int, string>
     */
    public function groups(
        Model&ContentOwner $owner,
        ContentActorData $actor,
    ): Collection {
        return $this->listGroups->execute($owner, $actor);
    }

    /**
     * Return every editable placement in one owner composition group.
     *
     * @return Collection<int, ContentPlacement>
     */
    public function placements(
        Model&ContentOwner $owner,
        string $group,
        ContentActorData $actor,
    ): Collection {
        return $this->listPlacements->execute($owner, $group, $actor);
    }

    /**
     * Return the complete typed bootstrap payload for a consumer-owned editor.
     */
    public function editor(
        Model&ContentOwner $owner,
        string $group,
        ContentActorData $actor,
    ): ContentEditorData {
        $ownerType = $this->owners->type($owner);
        $ownerId = $this->owners->id($owner);

        return new ContentEditorData(
            ownerType: $ownerType,
            ownerId: $ownerId,
            group: $group,
            definitions: array_values($this->definitions($actor)->all()),
            presets: array_values($this->presets($actor)->all()),
            groups: array_values($this->groups($owner, $actor)->all()),
            placements: array_values($this->placements($owner, $group, $actor)
                ->map(ContentPlacementData::fromModel(...))
                ->all()),
        );
    }

    /**
     * Place one reusable block in an owner composition group.
     */
    public function place(
        ContentBlock|string $block,
        Model&ContentOwner $owner,
        string $group,
        PlaceContentBlockData $data,
        ContentActorData $actor,
    ): ContentPlacement {
        return $this->placeBlock->execute($block, $owner, $group, $data, $actor);
    }

    /**
     * Update one revision-safe placement.
     */
    public function updatePlacement(
        ContentPlacement|string $placement,
        UpdateContentPlacementData $data,
        ContentActorData $actor,
    ): ContentPlacement {
        return $this->updatePlacement->execute($placement, $data, $actor);
    }

    /**
     * Remove one revision-safe leaf placement.
     */
    public function deletePlacement(
        ContentPlacement|string $placement,
        int $expectedRevision,
        ContentActorData $actor,
    ): void {
        $this->deletePlacement->execute($placement, $expectedRevision, $actor);
    }

    /**
     * Render one live owner composition group.
     */
    public function render(
        Model&ContentOwner $owner,
        string $group,
        string $locale,
        ContentActorData $actor,
        bool $publicOnly = true,
    ): RenderedContentCompositionData {
        return $this->renderer->render($owner, $group, $locale, $actor, $publicOnly);
    }

    /**
     * Capture one owner composition group as an immutable snapshot.
     */
    public function capture(
        Model&ContentOwner $owner,
        string $group,
        ContentActorData $actor,
        bool $publishing = false,
    ): ContentCompositionSnapshotData {
        return $this->snapshots->capture($owner, $group, $actor, $publishing);
    }

    /**
     * Render one verified immutable composition snapshot.
     */
    public function renderSnapshot(
        ContentCompositionSnapshotData $snapshot,
        string $locale,
        ContentActorData $actor,
    ): RenderedContentCompositionData {
        return $this->snapshots->render($snapshot, $locale, $actor);
    }
}
