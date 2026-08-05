<?php

declare(strict_types=1);

namespace Nvl\Seo\Data;

use Nvl\Seo\Enums\SitemapChangeFrequency;
use Nvl\Seo\Models\SeoProfile;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Privileged management representation of one SEO profile.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class SeoProfileData extends Data
{
    /**
     * @param  array<string, SeoProfileTranslationData>  $translations
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $scope,
        public readonly string $ownerAlias,
        public readonly string $ownerMorphType,
        public readonly string $ownerId,
        #[LiteralTypeScriptType("'active' | 'archived'")]
        public readonly string $status,
        public readonly int $revision,
        public readonly bool $isIndexable,
        public readonly bool $isFollowable,
        public readonly ?int $maxSnippet,
        public readonly ?string $maxImagePreview,
        public readonly ?int $maxVideoPreview,
        public readonly bool $sitemapIncluded,
        public readonly ?string $sitemapPriority,
        public readonly ?SitemapChangeFrequency $sitemapChangeFrequency,
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly ?array $metadata,
        public readonly ?string $archivedAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        #[LiteralTypeScriptType('Record<string, Nvl.Seo.Data.SeoProfileTranslationData>')]
        public readonly array $translations,
    ) {}

    /**
     * Create the complete management representation for one registered owner alias.
     */
    public static function fromModel(SeoProfile $profile, string $ownerAlias): self
    {
        $profile->loadMissing('translations');
        $translations = [];

        foreach ($profile->translations->sortBy('locale', SORT_STRING) as $translation) {
            $translations[$translation->locale] = SeoProfileTranslationData::fromModel($translation);
        }

        return new self(
            id: $profile->id,
            scope: $profile->scope,
            ownerAlias: $ownerAlias,
            ownerMorphType: $profile->seoable_type,
            ownerId: $profile->seoable_id,
            status: $profile->status,
            revision: $profile->revision,
            isIndexable: $profile->is_indexable,
            isFollowable: $profile->is_followable,
            maxSnippet: $profile->max_snippet,
            maxImagePreview: $profile->max_image_preview,
            maxVideoPreview: $profile->max_video_preview,
            sitemapIncluded: $profile->sitemap_included,
            sitemapPriority: $profile->sitemap_priority,
            sitemapChangeFrequency: $profile->sitemap_change_frequency,
            metadata: $profile->metadata,
            archivedAt: $profile->archived_at?->toAtomString(),
            createdAt: $profile->created_at->toAtomString(),
            updatedAt: $profile->updated_at->toAtomString(),
            translations: $translations,
        );
    }
}
