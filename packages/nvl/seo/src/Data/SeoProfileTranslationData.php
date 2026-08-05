<?php

declare(strict_types=1);

namespace Nvl\Seo\Data;

use Nvl\Seo\Enums\TwitterCard;
use Nvl\Seo\Models\SeoProfileTranslation;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Privileged management representation of one localized SEO profile row.
 */
#[TypeScript]
final class SeoProfileTranslationData extends Data
{
    /**
     * @param  array<array-key, mixed>|null  $structuredData
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly string $locale,
        public readonly ?string $path,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $canonicalUrl,
        public readonly ?string $imageUrl,
        public readonly ?string $imageReference,
        public readonly ?string $imageAlt,
        public readonly ?string $openGraphTitle,
        public readonly ?string $openGraphDescription,
        public readonly ?string $twitterTitle,
        public readonly ?string $twitterDescription,
        public readonly ?TwitterCard $twitterCard,
        #[LiteralTypeScriptType('unknown[] | Record<string, unknown> | null')]
        public readonly ?array $structuredData,
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly ?array $metadata,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /**
     * Create the management representation from one translation model.
     */
    public static function fromModel(SeoProfileTranslation $translation): self
    {
        return new self(
            locale: $translation->locale,
            path: $translation->path,
            title: $translation->title,
            description: $translation->description,
            canonicalUrl: $translation->canonical_url,
            imageUrl: $translation->image_url,
            imageReference: $translation->image_reference,
            imageAlt: $translation->image_alt,
            openGraphTitle: $translation->open_graph_title,
            openGraphDescription: $translation->open_graph_description,
            twitterTitle: $translation->twitter_title,
            twitterDescription: $translation->twitter_description,
            twitterCard: $translation->twitter_card,
            structuredData: $translation->structured_data,
            metadata: $translation->metadata,
            createdAt: $translation->created_at->toAtomString(),
            updatedAt: $translation->updated_at->toAtomString(),
        );
    }
}
