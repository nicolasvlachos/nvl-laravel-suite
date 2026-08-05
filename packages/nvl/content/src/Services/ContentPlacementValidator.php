<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Content\Validation\ContentValueValidator;

/**
 * Validates regions, parents, cycles, and sanitized non-localized overrides.
 */
final readonly class ContentPlacementValidator
{
    public function __construct(
        private ContentDefinitionRegistry $definitions,
        private ContentValueValidator $values,
        private ContentPatch $patch,
        private ContentPayloadGuard $guard,
    ) {}

    /**
     * Validate a placement’s region, group tree, and normalized overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function validate(
        ContentBlock $block,
        Model&ContentOwner $owner,
        string $ownerType,
        string $ownerId,
        string $group,
        string $region,
        ?string $parentId,
        array $overrides,
        ContentActorData $actor,
        ?string $placementId = null,
    ): array {
        $overrides = ContentArrays::stringMap(
            $overrides,
            'content placement overrides',
        );
        $this->assertRegion($block, $region);
        $this->guard->metadata($overrides);
        $this->assertChildrenRegion($region, $placementId);
        $targetDepth = $this->assertParent(
            $ownerType,
            $ownerId,
            $group,
            $region,
            $parentId,
            $placementId,
        );
        $this->assertSubtreeDepth(
            $ownerType,
            $ownerId,
            $group,
            $placementId,
            $targetDepth,
        );

        return $this->normalizeOverrides(
            $block,
            $owner,
            $group,
            $overrides,
            $actor,
        );
    }

    /**
     * Validate stored placement state against a block’s current definition contract.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function validateDefinition(
        ContentBlock $block,
        Model&ContentOwner $owner,
        string $group,
        string $region,
        array $overrides,
        ContentActorData $actor,
    ): array {
        $overrides = ContentArrays::stringMap(
            $overrides,
            'content placement overrides',
        );
        $this->assertRegion($block, $region);
        $this->guard->metadata($overrides);

        return $this->normalizeOverrides(
            $block,
            $owner,
            $group,
            $overrides,
            $actor,
        );
    }

    private function assertRegion(ContentBlock $block, string $region): void
    {
        $definition = $this->definitions->get($block->definition->key);

        if (! in_array($region, $definition->allowedRegions, true)) {
            throw new InvalidArgumentException(
                "Content definition [{$definition->key}] cannot be placed in region [{$region}].",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function normalizeOverrides(
        ContentBlock $block,
        Model&ContentOwner $owner,
        string $group,
        array $overrides,
        ContentActorData $actor,
    ): array {
        $translations = [];

        foreach ($block->translations as $translation) {
            $locale = $translation->getAttribute('locale');
            $values = $translation->getAttribute('values');

            if (is_string($locale) && is_array($values)) {
                $translations[$locale] = ContentArrays::stringMap(
                    $values,
                    "content placement translation {$locale}",
                );
            }
        }

        $merged = $this->patch->merge(
            is_array($block->values) ? $block->values : [],
            $overrides,
        );
        $validated = $this->values->validate(
            $block->definition_schema,
            $merged,
            $translations,
            $actor,
            $block->visibility,
            publishing: $block->status === ContentStatus::Published,
            owner: $owner,
            group: $group,
        );

        return $this->projectOverrides($validated->values, $overrides);
    }

    /**
     * Keep normalized values only along paths explicitly supplied by the caller.
     *
     * Lists, empty arrays, and scalar values are atomic merge-patch values.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function projectOverrides(array $validated, array $overrides): array
    {
        $projected = [];

        foreach ($overrides as $key => $override) {
            if (! array_key_exists($key, $validated)) {
                continue;
            }

            $normalized = $validated[$key];
            $projected[$key] = is_array($override)
                && $override !== []
                && ! array_is_list($override)
                && is_array($normalized)
                ? $this->projectOverrides(
                    ContentArrays::stringMap($normalized, "validated content override {$key}"),
                    ContentArrays::stringMap($override, "content override {$key}"),
                )
                : $normalized;
        }

        return $projected;
    }

    private function assertChildrenRegion(string $region, ?string $placementId): void
    {
        if ($placementId === null) {
            return;
        }

        $hasForeignRegion = ContentPlacement::query()
            ->where('parent_id', $placementId)
            ->where('region', '!=', $region)
            ->exists();

        if ($hasForeignRegion) {
            throw new InvalidArgumentException(
                'A placement region cannot change while its children use another region.',
            );
        }
    }

    private function assertParent(
        string $ownerType,
        string $ownerId,
        string $group,
        string $region,
        ?string $parentId,
        ?string $placementId,
    ): int {
        if ($parentId === null) {
            return 1;
        }

        if ($parentId === $placementId) {
            throw new InvalidArgumentException('A content placement cannot be its own parent.');
        }

        $parent = ContentPlacement::query()->findOrFail($parentId);

        if ($parent->owner_type !== $ownerType
            || $parent->owner_id !== $ownerId
            || $parent->group !== $group) {
            throw new InvalidArgumentException(
                'A content placement parent must belong to the same owner group.',
            );
        }

        if ($parent->region !== $region) {
            throw new InvalidArgumentException(
                'A nested content placement must use its parent region.',
            );
        }

        $visited = [$parent->id => true];
        $cursor = $parent;
        $maximum = ContentConfiguration::positiveInteger(
            'content.placements.maximum_depth',
            50,
        );
        $depth = 2;

        if ($depth > $maximum) {
            throw new InvalidArgumentException(
                "Content placement depth exceeds {$maximum} levels.",
            );
        }

        while ($cursor->parent_id !== null) {
            if ($cursor->parent_id === $placementId) {
                throw new InvalidArgumentException('Content placement cycles are not allowed.');
            }

            $cursor = ContentPlacement::query()->findOrFail($cursor->parent_id);

            if ($cursor->owner_type !== $ownerType
                || $cursor->owner_id !== $ownerId
                || $cursor->group !== $group) {
                throw new InvalidArgumentException(
                    'Every content placement ancestor must belong to the same owner group.',
                );
            }

            if (isset($visited[$cursor->id])) {
                throw new InvalidArgumentException('Content placement cycles are not allowed.');
            }

            $visited[$cursor->id] = true;
            $depth++;

            if ($depth > $maximum) {
                throw new InvalidArgumentException(
                    "Content placement depth exceeds {$maximum} levels.",
                );
            }
        }

        return $depth;
    }

    private function assertSubtreeDepth(
        string $ownerType,
        string $ownerId,
        string $group,
        ?string $placementId,
        int $targetDepth,
    ): void {
        if ($placementId === null) {
            return;
        }

        $maximum = ContentConfiguration::positiveInteger(
            'content.placements.maximum_depth',
            50,
        );
        $placements = ContentPlacement::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('group', $group)
            ->get(['id', 'parent_id']);
        /** @var array<string, list<string>> $children */
        $children = [];

        foreach ($placements as $placement) {
            if ($placement->parent_id !== null) {
                $children[$placement->parent_id][] = $placement->id;
            }
        }

        /** @var list<array{id: string, relative_depth: int}> $pending */
        $pending = [['id' => $placementId, 'relative_depth' => 1]];
        /** @var array<string, true> $visited */
        $visited = [];

        while ($pending !== []) {
            $current = array_pop($pending);
            $currentId = $current['id'];

            if (isset($visited[$currentId])) {
                throw new InvalidArgumentException('Content placement cycles are not allowed.');
            }

            $visited[$currentId] = true;
            $relativeDepth = $current['relative_depth'];

            if ($targetDepth + $relativeDepth - 1 > $maximum) {
                throw new InvalidArgumentException(
                    "Content placement depth exceeds {$maximum} levels.",
                );
            }

            foreach ($children[$currentId] ?? [] as $childId) {
                $pending[] = [
                    'id' => $childId,
                    'relative_depth' => $relativeDepth + 1,
                ];
            }
        }
    }
}
