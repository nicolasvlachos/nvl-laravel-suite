<?php

declare(strict_types=1);

namespace Nvl\Pages\Data;

use DateTimeInterface;
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
 * Complete sanitized page projection for authorized management consumers.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class PageData extends Data
{
    use DataTransform;

    /**
     * Create one complete authorized management projection.
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
     * Build a complete management projection from one page.
     */
    public static function fromModel(Page $page): self
    {
        $page->loadMissing('translations');
        $translations = [];

        foreach ($page->translations as $translation) {
            $translations[$translation->locale] = [
                'title' => $translation->title,
                'navigationLabel' => $translation->navigation_label,
                'summary' => $translation->summary,
            ];
        }

        return new self(
            id: $page->id,
            parentId: $page->parent_id,
            key: $page->key,
            site: $page->site,
            slug: $page->slug,
            path: $page->path,
            kind: $page->kind,
            resource: $page->resource,
            status: $page->status,
            position: $page->position,
            isNavigable: $page->is_navigable,
            sitemapIncluded: $page->sitemap_included,
            sitemapPriority: $page->sitemap_priority,
            sitemapChangeFrequency: $page->sitemap_change_frequency,
            publishedAt: self::timestamp($page->published_at),
            expiresAt: self::timestamp($page->expires_at),
            revision: $page->revision,
            translations: $translations,
            createdAt: self::timestamp($page->created_at) ?? '',
            updatedAt: self::timestamp($page->updated_at) ?? '',
        );
    }

    private static function timestamp(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }
}
