<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Seo\Data\StructuredDataContextData;
use Nvl\Seo\Data\StructuredDataNodeData;
use Nvl\Seo\Enums\StructuredDataType;
use Nvl\Seo\Support\StructuredDataLimits;

/**
 * Composes baseline, provider-generated, and persisted JSON-LD into one graph.
 */
final readonly class StructuredDataResolver
{
    /**
     * Create the resource-aware structured-data resolver.
     */
    public function __construct(
        private StructuredDataRegistry $providers,
        private StructuredDataBuilder $builder,
    ) {}

    /**
     * Resolve one bounded JSON-LD graph for a page resource.
     *
     * @param  array<array-key, mixed>|null  $persisted
     * @return list<array<string, mixed>>
     */
    public function resolve(
        Model $resource,
        StructuredDataContextData $context,
        ?array $persisted,
    ): array {
        $mode = config('seo.structured_data.mode', 'merge');

        if (! is_string($mode) || ! in_array($mode, ['persisted', 'generated', 'merge'], true)) {
            throw new InvalidArgumentException(
                'seo.structured_data.mode must be persisted, generated, or merge.',
            );
        }

        $generated = $mode === 'persisted'
            ? []
            : $this->generatedNodes($resource, $context);
        $stored = $mode === 'generated'
            ? []
            : $this->persistedNodes($persisted);
        $nodes = $this->mergeByIdentifier([...$generated, ...$stored]);

        if ($nodes === []) {
            return [];
        }

        $graph = $this->builder->graph($nodes);
        StructuredDataLimits::assert($graph);

        return [$graph];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generatedNodes(
        Model $resource,
        StructuredDataContextData $context,
    ): array {
        $nodes = [];

        if ((bool) config('seo.structured_data.automatic_web_site', true)) {
            $nodes[] = StructuredDataNodeData::make(
                type: StructuredDataType::WebSite,
                id: rtrim($context->siteUrl, '/').'#website',
                properties: array_filter([
                    'url' => $context->siteUrl,
                    'name' => $context->siteName,
                    'inLanguage' => $context->locale,
                ], static fn (string $value): bool => $value !== ''),
            )->toJsonLd();
        }

        if ((bool) config('seo.structured_data.automatic_web_page', true)
            && $context->canonicalUrl !== null) {
            $siteId = rtrim($context->siteUrl, '/').'#website';
            $nodes[] = StructuredDataNodeData::make(
                type: StructuredDataType::WebPage,
                id: $context->canonicalUrl.'#webpage',
                properties: array_filter([
                    'url' => $context->canonicalUrl,
                    'name' => $context->title,
                    'description' => $context->description,
                    'inLanguage' => $context->locale,
                    'isPartOf' => $this->builder->reference($siteId),
                    'primaryImageOfPage' => $context->imageUrl === null
                        ? null
                        : StructuredDataNodeData::make(
                            StructuredDataType::ImageObject,
                            ['contentUrl' => $context->imageUrl],
                            $context->canonicalUrl.'#primaryimage',
                        )->toJsonLd(),
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            )->toJsonLd();
        }

        foreach ($this->providers->resolve($resource, $context) as $node) {
            $nodes[] = $node->toJsonLd();
        }

        return $nodes;
    }

    /**
     * @param  array<array-key, mixed>|null  $persisted
     * @return list<array<string, mixed>>
     */
    private function persistedNodes(?array $persisted): array
    {
        if ($persisted === null || $persisted === []) {
            return [];
        }

        $documents = array_is_list($persisted) ? $persisted : [$persisted];
        $nodes = [];

        foreach ($documents as $document) {
            if (! is_array($document)) {
                continue;
            }

            if (isset($document['@graph']) && is_array($document['@graph'])) {
                foreach ($document['@graph'] as $node) {
                    if (is_array($node)) {
                        $normalized = $this->stringKeyed($node);

                        if ($normalized !== null) {
                            unset($normalized['@context']);
                            $nodes[] = $normalized;
                        }
                    }
                }

                continue;
            }

            $normalized = $this->stringKeyed($document);

            if ($normalized !== null) {
                unset($normalized['@context']);
                $nodes[] = $normalized;
            }
        }

        return $nodes;
    }

    /**
     * Later nodes with the same @id replace earlier generated defaults.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function mergeByIdentifier(array $nodes): array
    {
        $merged = [];
        $positions = [];

        foreach ($nodes as $node) {
            $id = $node['@id'] ?? null;

            if (is_string($id) && isset($positions[$id])) {
                $merged[$positions[$id]] = array_replace(
                    $merged[$positions[$id]],
                    $node,
                );

                continue;
            }

            $position = count($merged);
            $merged[] = $node;

            if (is_string($id) && $id !== '') {
                $positions[$id] = $position;
            }
        }

        return $merged;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>|null
     */
    private function stringKeyed(array $value): ?array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                return null;
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }
}
