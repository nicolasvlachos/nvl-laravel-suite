<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Loads, validates, and prunes bounded owner placement trees.
 */
final readonly class ContentPlacementTree
{
    public function __construct(
        private ContentIdentityGuard $identities,
        private ContentOwnerRegistry $owners,
    ) {}

    /**
     * Load and validate one complete owner group tree.
     *
     * @return Collection<int, ContentPlacement>
     */
    public function load(Model&ContentOwner $owner, string $group): Collection
    {
        $this->identities->group($group);
        $ownerType = $this->owners->type($owner);
        $ownerId = $this->owners->id($owner);
        $maximum = ContentConfiguration::positiveInteger(
            'content.placements.maximum_per_group',
            1_000,
        );
        /** @var Collection<int, ContentPlacement> $placements */
        $placements = $owner->contentPlacements()
            ->with(['block.definition', 'block.translations'])
            ->whereHas('block')
            ->where('group', $group)
            ->orderBy('region')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit($maximum + 1)
            ->get();

        if ($placements->count() > $maximum) {
            throw new InvalidArgumentException(
                "Content composition exceeds the {$maximum} placement limit.",
            );
        }

        $this->assertValid($placements, $ownerType, $ownerId, $group);

        return $placements;
    }

    /**
     * Remove a placement and its descendants when any ancestor is hidden or
     * fails the requested public publication policy.
     *
     * @param  Collection<int, ContentPlacement>  $placements
     * @return Collection<int, ContentPlacement>
     */
    public function eligible(Collection $placements, bool $publicOnly): Collection
    {
        /** @var Collection<string, ContentPlacement> $byId */
        $byId = $placements->keyBy('id');
        /** @var array<string, bool> $memo */
        $memo = [];

        return $placements->filter(
            fn (ContentPlacement $placement): bool => $this->isEligible(
                $placement,
                $byId,
                $memo,
                $publicOnly,
            ),
        )->values();
    }

    /**
     * @param  Collection<int, ContentPlacement>  $placements
     */
    private function assertValid(
        Collection $placements,
        string $ownerType,
        string $ownerId,
        string $group,
    ): void {
        /** @var Collection<string, ContentPlacement> $byId */
        $byId = $placements->keyBy('id');
        $maximumDepth = ContentConfiguration::positiveInteger(
            'content.placements.maximum_depth',
            50,
        );

        if ($byId->count() !== $placements->count()) {
            throw new InvalidArgumentException('Content placement IDs must be unique.');
        }

        foreach ($placements as $placement) {
            if ($placement->owner_type !== $ownerType
                || $placement->owner_id !== $ownerId
                || $placement->group !== $group) {
                throw new InvalidArgumentException(
                    'Content placement tree contains a foreign owner group.',
                );
            }

            $visited = [$placement->id => true];
            $cursor = $placement;

            for ($depth = 1; $cursor->parent_id !== null; $depth++) {
                if ($depth >= $maximumDepth) {
                    throw new InvalidArgumentException(
                        "Content placement depth exceeds {$maximumDepth} levels.",
                    );
                }

                $parent = $byId->get($cursor->parent_id);

                if (! $parent instanceof ContentPlacement) {
                    throw new InvalidArgumentException(
                        "Content placement [{$cursor->id}] references a missing parent.",
                    );
                }

                if ($parent->region !== $placement->region) {
                    throw new InvalidArgumentException(
                        'Nested content placements must remain in their parent region.',
                    );
                }

                if (isset($visited[$parent->id])) {
                    throw new InvalidArgumentException('Content placement cycles are not allowed.');
                }

                $visited[$parent->id] = true;
                $cursor = $parent;
            }
        }
    }

    /**
     * @param  Collection<string, ContentPlacement>  $byId
     * @param  array<string, bool>  $memo
     */
    private function isEligible(
        ContentPlacement $placement,
        Collection $byId,
        array &$memo,
        bool $publicOnly,
    ): bool {
        if (array_key_exists($placement->id, $memo)) {
            return $memo[$placement->id];
        }

        $block = $placement->block;
        $eligible = $placement->is_visible
            && (! $publicOnly
                || $block->status === ContentStatus::Published
                && $block->visibility === ContentVisibility::Public);

        if ($eligible && $placement->parent_id !== null) {
            $parent = $byId->get($placement->parent_id);
            $eligible = $parent instanceof ContentPlacement
                && $this->isEligible($parent, $byId, $memo, $publicOnly);
        }

        return $memo[$placement->id] = $eligible;
    }
}
