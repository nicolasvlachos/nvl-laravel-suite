<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentPlacementEvent;
use Nvl\Content\Events\ContentPlacementChanged;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Support\ContentArrays;

/**
 * Revalidates dependent placements and their Media references inside a locked block mutation.
 */
final readonly class ContentBlockPlacementSynchronizer
{
    public function __construct(
        private ContentOwnerRegistry $owners,
        private ContentPlacementValidator $placements,
        private ContentMediaSynchronizer $media,
    ) {}

    /**
     * Validate and synchronize every placement of the caller's transaction-locked block.
     */
    public function synchronize(ContentBlock $block, ContentActorData $actor): void
    {
        $placements = ContentPlacement::query()
            ->where('content_block_id', $block->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($placements as $placement) {
            $owner = $this->owners->resolveRetained($placement->owner_type, $placement->owner_id);
            $this->owners->assertGroup($owner, $placement->group);
            $overrides = ContentArrays::stringMap(
                is_array($placement->overrides) ? $placement->overrides : [],
                "content placement {$placement->id} overrides",
            );
            $normalized = $this->placements->validateDefinition(
                $block,
                $owner,
                $placement->group,
                $placement->region,
                $overrides,
                $actor,
            );
            $this->media->synchronizePlacement(
                $placement,
                $block->definition_schema,
                $normalized,
                $actor,
                $owner,
            );

            if ($normalized === $overrides) {
                continue;
            }

            $placement->forceFill([
                'overrides' => $normalized === [] ? null : $normalized,
                'revision' => $placement->revision + 1,
            ])->save();
            ContentPlacementChanged::dispatch(
                $placement->id,
                ContentPlacementEvent::Updated,
                $placement->revision,
                $actor,
                $placement->owner_type,
                $placement->owner_id,
                $placement->group,
                $block->id,
            );
        }
    }
}
