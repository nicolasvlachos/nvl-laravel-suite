<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentCompositionSnapshotBlockData;
use Nvl\Content\Data\ContentCompositionSnapshotData;
use Nvl\Content\Data\ContentSchemaData;
use Nvl\Content\Data\RenderedContentBlockData;
use Nvl\Content\Data\RenderedContentCompositionData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Content\Validation\ContentValueValidator;

/**
 * Captures and re-renders immutable compositions for Templates and other versioned consumers.
 */
final readonly class ContentSnapshotService
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentOwnerRegistry $owners,
        private ContentValueValidator $values,
        private ContentPatch $patch,
        private CanonicalJson $json,
        private Factory $views,
        private ContentPlacementTree $tree,
        private ContentMediaReferences $mediaReferences,
        private ContentLocalePolicy $locales,
        private ContentPayloadGuard $guard,
        private ContentIdentityGuard $identities,
        private ContentLocalizedValues $localizedValues,
    ) {}

    /**
     * Capture one authorized owner group as a bounded immutable snapshot.
     */
    public function capture(
        Model&ContentOwner $owner,
        string $group,
        ContentActorData $actor,
        bool $publishing = false,
    ): ContentCompositionSnapshotData {
        $ownerType = $this->owners->type($owner);
        $ownerId = $this->owners->id($owner);
        $this->owners->assertGroup($owner, $group);
        $this->authorization->authorize(
            ContentAbility::Render,
            $actor,
            owner: $owner,
            context: [
                'group' => $group,
                'snapshot' => 'capture',
                'publishing' => $publishing,
            ],
        );
        $placements = $this->tree->eligible(
            $this->tree->load($owner, $group),
            publicOnly: false,
        );
        $blocks = [];

        foreach ($placements as $placement) {
            $block = $placement->block;
            $translations = [];

            foreach ($block->translations as $translation) {
                $locale = $translation->getAttribute('locale');
                $values = $translation->getAttribute('values');

                if (is_string($locale) && is_array($values)) {
                    $translations[$locale] = ContentArrays::stringMap(
                        $values,
                        "content snapshot translation {$locale}",
                    );
                }
            }

            ksort($translations);
            $baseValues = is_array($block->values)
                ? ContentArrays::stringMap(
                    $block->values,
                    "content snapshot block {$block->id} values",
                )
                : [];

            if ($publishing) {
                $validated = $this->values->validate(
                    $block->definition_schema,
                    $baseValues,
                    $translations,
                    $actor,
                    $block->visibility,
                    publishing: true,
                    owner: $owner,
                    group: $group,
                );
                $baseValues = $validated->values;
                $translations = $validated->translations;
            }

            $blocks[] = new ContentCompositionSnapshotBlockData(
                placementId: $placement->id,
                parentId: $placement->parent_id,
                key: $placement->key,
                region: $placement->region,
                sortOrder: $placement->sort_order,
                blockId: $block->id,
                definitionKey: $block->definition->key,
                definitionSchema: ContentSchemaData::fromSchema($block->definition_schema),
                definitionView: $block->definition_view,
                visibility: $block->visibility,
                values: $baseValues,
                translations: $translations,
                overrides: is_array($placement->overrides) ? $placement->overrides : [],
                blockRevision: $block->revision,
                placementRevision: $placement->revision,
            );
        }

        $this->assertSize($ownerType, $ownerId, $group, $blocks);

        return new ContentCompositionSnapshotData(
            ownerType: $ownerType,
            ownerId: $ownerId,
            group: $group,
            blocks: $blocks,
            version: $this->version($ownerType, $ownerId, $group, $blocks),
        );
    }

    /**
     * Verify and render one immutable owner group snapshot.
     */
    public function render(
        ContentCompositionSnapshotData $snapshot,
        string $locale,
        ContentActorData $actor,
    ): RenderedContentCompositionData {
        $this->identities->group($snapshot->group);
        $this->assertSize(
            $snapshot->ownerType,
            $snapshot->ownerId,
            $snapshot->group,
            $snapshot->blocks,
        );

        if (! hash_equals(
            $snapshot->version,
            $this->version(
                $snapshot->ownerType,
                $snapshot->ownerId,
                $snapshot->group,
                $snapshot->blocks,
            ),
        )) {
            throw new InvalidArgumentException('Content composition snapshot integrity check failed.');
        }

        $locale = $this->locales->assertSupported($locale);
        $owner = $this->owners->resolve($snapshot->ownerType, $snapshot->ownerId);
        $this->owners->assertGroup($owner, $snapshot->group);
        $this->authorization->authorize(
            ContentAbility::Render,
            $actor,
            owner: $owner,
            context: ['group' => $snapshot->group, 'snapshot' => 'render'],
        );
        $records = [];

        foreach ($snapshot->blocks as $record) {
            if (isset($records[$record->placementId])) {
                throw new InvalidArgumentException(
                    'Content snapshot placement IDs must be unique strings.',
                );
            }

            $records[$record->placementId] = $record;
        }

        $this->assertTree($records);
        $resources = new ContentRenderResources;
        $resources->preloadMedia($this->snapshotMediaIds($records, $locale));
        $children = [];

        foreach ($records as $record) {
            $parentKey = $record->parentId ?? '*';
            $children[$parentKey] ??= [];
            $children[$parentKey][] = $record;
        }

        $visited = [];
        $roots = [];

        foreach ($children['*'] ?? [] as $record) {
            $roots[] = $this->renderNode(
                $record,
                $children,
                $locale,
                $actor,
                $resources,
                $owner,
                $snapshot->group,
                $visited,
                1,
            );
        }

        usort(
            $roots,
            static fn (RenderedContentBlockData $left, RenderedContentBlockData $right): int => [
                $left->region,
                $left->sortOrder,
                $left->placementId,
            ] <=> [
                $right->region,
                $right->sortOrder,
                $right->placementId,
            ],
        );
        $regions = [];

        foreach ($roots as $block) {
            $regions[$block->region] ??= [];
            $regions[$block->region][] = $block;
        }

        ksort($regions);

        return new RenderedContentCompositionData(
            ownerType: $snapshot->ownerType,
            ownerId: $snapshot->ownerId,
            group: $snapshot->group,
            locale: $locale,
            blocks: $roots,
            regions: $regions,
            version: $snapshot->version,
        );
    }

    /**
     * @param  array<string, list<ContentCompositionSnapshotBlockData>>  $children
     * @param  array<string, true>  $visited
     */
    private function renderNode(
        ContentCompositionSnapshotBlockData $record,
        array $children,
        string $locale,
        ContentActorData $actor,
        ContentRenderResources $resources,
        Model $owner,
        string $group,
        array &$visited,
        int $depth,
    ): RenderedContentBlockData {
        $placementId = $record->placementId;

        $maximumDepth = ContentConfiguration::positiveInteger(
            'content.placements.maximum_depth',
            50,
        );

        if ($depth > $maximumDepth
            || isset($visited[$placementId])) {
            throw new InvalidArgumentException(
                'Content snapshot tree contains an invalid placement or cycle.',
            );
        }

        $visited[$placementId] = true;
        $schema = $record->definitionSchema->toSchema();
        $base = ContentArrays::stringMap(
            $record->values,
            "content snapshot {$placementId} values",
        );
        $translations = ContentArrays::translations(
            $record->translations,
            "content snapshot {$placementId} translations",
        );
        $overrides = ContentArrays::stringMap(
            $record->overrides,
            "content snapshot {$placementId} overrides",
        );
        $merged = $this->patch->merge($base, $overrides);
        $merged = $this->localizedValues->overlay(
            $schema,
            $merged,
            $this->localizedValues->resolve($schema, $translations, $locale),
        );

        $view = $record->definitionView !== null && $record->definitionView !== ''
            ? $record->definitionView
            : config('content.rendering.default_view', 'nvl-content::blocks.default');

        if (! is_string($view) || $view === '') {
            throw new InvalidArgumentException('Content snapshot has no renderable view.');
        }

        if ((bool) config('content.rendering.strict_views', true) && ! $this->views->exists($view)) {
            throw new InvalidArgumentException("Content block view [{$view}] does not exist.");
        }

        $renderedChildren = [];

        foreach ($children[$placementId] ?? [] as $child) {
            $renderedChildren[] = $this->renderNode(
                $child,
                $children,
                $locale,
                $actor,
                $resources,
                $owner,
                $group,
                $visited,
                $depth + 1,
            );
        }

        unset($visited[$placementId]);

        return new RenderedContentBlockData(
            id: $record->blockId,
            placementId: $placementId,
            definitionKey: $record->definitionKey,
            key: $record->key,
            region: $record->region,
            sortOrder: $record->sortOrder,
            view: $view,
            locale: $locale,
            values: $this->values->render(
                $schema,
                $merged,
                $actor,
                $locale,
                $record->visibility,
                $resources,
                $owner,
                group: $group,
            ),
            fieldTypes: $schema->fieldTypes(),
            children: $renderedChildren,
        );
    }

    /**
     * @param  list<ContentCompositionSnapshotBlockData>  $blocks
     */
    private function assertSize(
        string $ownerType,
        string $ownerId,
        string $group,
        array $blocks,
    ): void {
        $maximumPlacements = ContentConfiguration::positiveInteger(
            'content.placements.maximum_per_group',
            1_000,
        );

        if (count($blocks) > $maximumPlacements) {
            throw new InvalidArgumentException(
                "Content snapshot exceeds the {$maximumPlacements} placement limit.",
            );
        }

        $maximum = ContentConfiguration::positiveInteger(
            'content.validation.maximum_snapshot_bytes',
            2_097_152,
        );
        $payload = [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'group' => $group,
            'blocks' => $this->serializeBlocks($blocks),
        ];

        $this->guard->json(
            $payload,
            'Content composition snapshot',
            $maximum,
            ContentConfiguration::positiveInteger(
                'content.validation.maximum_snapshot_depth',
                32,
            ),
        );
    }

    /**
     * @param  list<ContentCompositionSnapshotBlockData>  $blocks
     */
    private function version(
        string $ownerType,
        string $ownerId,
        string $group,
        array $blocks,
    ): string {
        return $this->json->hash([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'group' => $group,
            'blocks' => $this->serializeBlocks($blocks),
        ]);
    }

    /**
     * @param  array<string, ContentCompositionSnapshotBlockData>  $records
     * @return list<string>
     */
    private function snapshotMediaIds(array $records, string $locale): array
    {
        $identifiers = [];

        foreach ($records as $placementId => $record) {
            $schema = $record->definitionSchema->toSchema();
            $values = $this->patch->merge(
                ContentArrays::stringMap(
                    $record->values,
                    "content snapshot {$placementId} values",
                ),
                ContentArrays::stringMap(
                    $record->overrides,
                    "content snapshot {$placementId} overrides",
                ),
            );
            $translations = ContentArrays::translations(
                $record->translations,
                "content snapshot {$placementId} translations",
            );
            $values = $this->localizedValues->overlay(
                $schema,
                $values,
                $this->localizedValues->resolve($schema, $translations, $locale),
            );

            foreach ($this->mediaReferences->extract(
                $schema,
                $values,
                $locale,
            ) as $reference) {
                $identifiers[] = $reference['id'];
            }
        }

        return array_values(array_unique($identifiers));
    }

    /**
     * @param  array<string, ContentCompositionSnapshotBlockData>  $records
     */
    private function assertTree(array $records): void
    {
        $maximumDepth = ContentConfiguration::positiveInteger(
            'content.placements.maximum_depth',
            50,
        );

        foreach ($records as $placementId => $record) {
            $parentId = $record->parentId;
            $region = $record->region;
            $visited = [$placementId => true];

            for ($depth = 1; $parentId !== null; $depth++) {
                if ($depth >= $maximumDepth) {
                    throw new InvalidArgumentException(
                        "Content snapshot depth exceeds {$maximumDepth} levels.",
                    );
                }

                $parent = $records[$parentId] ?? null;

                if (! $parent instanceof ContentCompositionSnapshotBlockData) {
                    throw new InvalidArgumentException(
                        "Content snapshot placement [{$placementId}] references a missing parent.",
                    );
                }

                if ($parent->region !== $region) {
                    throw new InvalidArgumentException(
                        'Nested content snapshot placements must remain in their parent region.',
                    );
                }

                if (isset($visited[$parentId])) {
                    throw new InvalidArgumentException(
                        'Content snapshot placement cycles are not allowed.',
                    );
                }

                $visited[$parentId] = true;
                $parentId = $parent->parentId;
            }
        }
    }

    /**
     * Return the canonical JSON-safe snapshot block representation.
     *
     * @param  list<ContentCompositionSnapshotBlockData>  $blocks
     * @return list<array<string, mixed>>
     */
    private function serializeBlocks(array $blocks): array
    {
        return array_map(
            static fn (ContentCompositionSnapshotBlockData $block): array => $block->toArray(),
            $blocks,
        );
    }
}
