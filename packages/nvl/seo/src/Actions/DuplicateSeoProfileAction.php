<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Seo\Data\Mutations\SeoProfilePayload;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Models\SeoProfileTranslation;
use Nvl\Translatable\Enums\TranslationSyncMode;

/**
 * Duplicates one profile through the canonical synchronization orchestration.
 *
 * Delegation to SyncSeoProfileAction is deliberate so duplicate writes cannot bypass
 * path conflict, translation, revision, event, or sitemap invalidation invariants.
 */
final readonly class DuplicateSeoProfileAction
{
    public function __construct(private SyncSeoProfileAction $sync) {}

    public function execute(
        SeoProfile|string $source,
        Model $target,
        ?string $scope = null,
        bool $copyPaths = false,
    ): SeoProfile {
        $sourceId = $source instanceof SeoProfile ? $source->id : $source;
        $source = SeoProfile::query()
            ->with('translations')
            ->findOrFail($sourceId);

        /** @var array<string, array<string, mixed>> $translations */
        $translations = $source->translations->sortBy('locale', SORT_STRING)->mapWithKeys(
            static fn (SeoProfileTranslation $translation): array => [
                $translation->locale => [
                    'path' => $copyPaths ? $translation->path : null,
                    'title' => $translation->title,
                    'description' => $translation->description,
                    'canonicalUrl' => $copyPaths ? $translation->canonical_url : null,
                    'imageUrl' => $translation->image_url,
                    'imageReference' => $translation->image_reference,
                    'imageAlt' => $translation->image_alt,
                    'openGraphTitle' => $translation->open_graph_title,
                    'openGraphDescription' => $translation->open_graph_description,
                    'twitterTitle' => $translation->twitter_title,
                    'twitterDescription' => $translation->twitter_description,
                    'twitterCard' => $translation->twitter_card,
                    'structuredData' => $translation->structured_data,
                    'metadata' => $translation->metadata,
                ],
            ],
        )->all();

        return $this->sync->execute(
            $target,
            SeoProfilePayload::from([
                'isIndexable' => $source->is_indexable,
                'isFollowable' => $source->is_followable,
                'maxSnippet' => $source->max_snippet,
                'maxImagePreview' => $source->max_image_preview,
                'maxVideoPreview' => $source->max_video_preview,
                'sitemapIncluded' => $source->sitemap_included,
                'sitemapPriority' => $source->sitemap_priority,
                'sitemapChangeFrequency' => $source->sitemap_change_frequency,
                'metadata' => $source->metadata,
                'translations' => $translations,
                'expectedRevision' => 0,
            ]),
            $scope,
            TranslationSyncMode::Replace,
        );
    }
}
