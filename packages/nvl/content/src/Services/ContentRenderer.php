<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\RenderedContentBlockData;
use Nvl\Content\Data\RenderedContentCompositionData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Content\Validation\ContentValueValidator;

/**
 * Resolves an owner composition into a deterministic, renderer-neutral block tree.
 */
final readonly class ContentRenderer
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
        private ContentLocalizedValues $localizedValues,
    ) {}

    /**
     * Render one authorized live owner group into a deterministic composition.
     */
    public function render(
        Model&ContentOwner $owner,
        string $group,
        string $locale,
        ContentActorData $actor,
        bool $publicOnly = true,
    ): RenderedContentCompositionData {
        $ownerType = $this->owners->type($owner);
        $ownerId = $this->owners->id($owner);
        $this->owners->assertGroup($owner, $group);
        $this->authorization->authorize(
            ContentAbility::Render,
            $actor,
            owner: $owner,
            context: ['group' => $group, 'public_only' => $publicOnly],
        );
        $locale = $this->locales->assertSupported($locale);
        $placements = $this->tree->eligible(
            $this->tree->load($owner, $group),
            $publicOnly,
        );
        $resources = new ContentRenderResources;
        $mediaIds = [];

        foreach ($placements as $placement) {
            foreach ($this->mediaReferences->extract(
                $placement->block->definition_schema,
                $this->mergedValues($placement, $locale),
                $locale,
            ) as $reference) {
                $mediaIds[] = $reference['id'];
            }
        }

        $resources->preloadMedia(array_values(array_unique($mediaIds)));
        $children = $placements->groupBy(
            static fn (ContentPlacement $placement): string => $placement->parent_id ?? '*',
        );
        $visited = [];
        $roots = [];

        foreach ($placements as $placement) {
            if ($placement->parent_id !== null) {
                continue;
            }

            $roots[] = $this->node(
                $placement,
                $children,
                $locale,
                $actor,
                $resources,
                $owner,
                $group,
                $publicOnly,
                $visited,
                1,
            );
        }

        $regions = [];

        foreach ($roots as $block) {
            $regions[$block->region] ??= [];
            $regions[$block->region][] = $block;
        }

        ksort($regions);
        $version = $this->json->hash([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'group' => $group,
            'locale' => $locale,
            'placements' => $placements->map(
                static fn (ContentPlacement $placement): array => [
                    $placement->id,
                    $placement->revision,
                    $placement->block->revision,
                    $placement->block->definition_hash,
                ],
            )->all(),
        ]);

        return new RenderedContentCompositionData(
            ownerType: $ownerType,
            ownerId: $ownerId,
            group: $group,
            locale: $locale,
            blocks: $roots,
            regions: $regions,
            version: $version,
        );
    }

    /**
     * @param  Collection<string, Collection<int, ContentPlacement>>  $children
     * @param  array<string, true>  $visited
     */
    private function node(
        ContentPlacement $placement,
        Collection $children,
        string $locale,
        ContentActorData $actor,
        ContentRenderResources $resources,
        Model $owner,
        string $group,
        bool $publicOnly,
        array &$visited,
        int $depth,
    ): RenderedContentBlockData {
        $maximumDepth = ContentConfiguration::positiveInteger(
            'content.placements.maximum_depth',
            50,
        );

        if ($depth > $maximumDepth || isset($visited[$placement->id])) {
            throw new InvalidArgumentException('Content placement tree contains a cycle or is too deep.');
        }

        $visited[$placement->id] = true;
        $block = $placement->block;
        $merged = $this->mergedValues($placement, $locale);

        $view = $block->definition_view;

        if (! is_string($view) || $view === '') {
            $configured = config('content.rendering.default_view', 'nvl-content::blocks.default');
            $view = is_string($configured) ? $configured : 'nvl-content::blocks.default';
        }

        if ((bool) config('content.rendering.strict_views', true) && ! $this->views->exists($view)) {
            throw new InvalidArgumentException(
                "Content block view [{$view}] does not exist.",
            );
        }

        $renderedChildren = [];

        foreach ($children->get($placement->id, collect()) as $child) {
            $renderedChildren[] = $this->node(
                $child,
                $children,
                $locale,
                $actor,
                $resources,
                $owner,
                $group,
                $publicOnly,
                $visited,
                $depth + 1,
            );
        }

        unset($visited[$placement->id]);

        return new RenderedContentBlockData(
            id: $block->id,
            placementId: $placement->id,
            definitionKey: $block->definition->key,
            key: $placement->key,
            region: $placement->region,
            sortOrder: $placement->sort_order,
            view: $view,
            locale: $locale,
            values: $this->values->render(
                $block->definition_schema,
                $merged,
                $actor,
                $locale,
                $block->visibility,
                $resources,
                $owner,
                $publicOnly,
                $group,
            ),
            fieldTypes: $block->definition_schema->fieldTypes(),
            children: $renderedChildren,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedValues(ContentPlacement $placement, string $locale): array
    {
        $block = $placement->block;
        $base = is_array($block->values) ? $block->values : [];
        $overrides = is_array($placement->overrides)
            ? ContentArrays::stringMap(
                $placement->overrides,
                "content placement {$placement->id} overrides",
            )
            : [];
        $merged = $this->patch->merge($base, $overrides);
        $translations = [];

        foreach ($block->translations as $translation) {
            $translationLocale = $translation->getAttribute('locale');
            $translationValues = $translation->getAttribute('values');

            if (is_string($translationLocale) && is_array($translationValues)) {
                $translations[$translationLocale] = ContentArrays::stringMap(
                    $translationValues,
                    "content block {$block->id} translation {$translationLocale}",
                );
            }
        }

        return $this->localizedValues->overlay(
            $block->definition_schema,
            $merged,
            $this->localizedValues->resolve(
                $block->definition_schema,
                $translations,
                $locale,
            ),
        );
    }
}
