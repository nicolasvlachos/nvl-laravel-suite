<?php

declare(strict_types=1);

namespace Nvl\Pages\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Pages\Models\Page;
use Nvl\Seo\Enums\SitemapChangeFrequency;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stable page row returned by the compatibility management list.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class PageListItemData extends Data
{
    use DataTransform;

    /**
     * Create one list row with the established management serialization shape.
     *
     * @param  array<string, array{title: string, navigationLabel: string|null, summary: string|null}>  $translations
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $parentId,
        public readonly string $key,
        public readonly string $site,
        public readonly string $slug,
        public readonly string $path,
        public readonly PageKind $kind,
        public readonly ?string $resource,
        public readonly PageStatus $status,
        public readonly int $position,
        public readonly bool $isNavigable,
        public readonly bool $sitemapIncluded,
        public readonly ?string $sitemapPriority,
        public readonly ?SitemapChangeFrequency $sitemapChangeFrequency,
        public readonly ?string $publishedAt,
        public readonly ?string $expiresAt,
        public readonly int $revision,
        #[LiteralTypeScriptType('Record<string, { title: string; navigationLabel: string | null; summary: string | null }>')]
        public readonly array $translations,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /**
     * Build one list row from an already eager-loaded page.
     */
    public static function fromModel(Page $page): self
    {
        $data = PageData::fromModel($page);

        return new self(
            id: $data->id,
            parentId: $data->parentId,
            key: $data->key,
            site: $data->site,
            slug: $data->slug,
            path: $data->path,
            kind: $data->kind,
            resource: $data->resource,
            status: $data->status,
            position: $data->position,
            isNavigable: $data->isNavigable,
            sitemapIncluded: $data->sitemapIncluded,
            sitemapPriority: $data->sitemapPriority,
            sitemapChangeFrequency: $data->sitemapChangeFrequency,
            publishedAt: $data->publishedAt,
            expiresAt: $data->expiresAt,
            revision: $data->revision,
            translations: $data->translations,
            createdAt: $data->createdAt,
            updatedAt: $data->updatedAt,
        );
    }
}
