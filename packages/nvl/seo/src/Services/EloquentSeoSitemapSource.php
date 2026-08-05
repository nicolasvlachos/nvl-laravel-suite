<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use LogicException;
use Nvl\Seo\Contracts\SitemapSource;
use Nvl\Seo\Data\SitemapEntry;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Support\SeoRouteConfiguration;

/**
 * Streams all indexable profile translations from bounded Eloquent chunks.
 */
final readonly class EloquentSeoSitemapSource implements SitemapSource
{
    public function __construct(
        private AbsoluteUrl $urls,
        private ?SitemapLocationPolicy $locations = null,
    ) {}

    /**
     * @return iterable<SitemapEntry>
     */
    public function entries(string $scope): iterable
    {
        $sitemapUrl = $this->urls->resolve(SeoRouteConfiguration::sitemapPath());

        if ($sitemapUrl === null) {
            throw new LogicException('The configured sitemap URL cannot be resolved.');
        }

        $locations = $this->locationPolicy();
        $profiles = SeoProfile::query()
            ->select([
                'id',
                'scope',
                'sitemap_priority',
                'sitemap_change_frequency',
            ])
            ->where('scope', $scope)
            ->active()
            ->where('is_indexable', true)
            ->where('sitemap_included', true)
            ->with([
                'translations:id,seo_profile_id,locale,path,canonical_url',
            ])
            ->lazyById(500, column: 'id');

        foreach ($profiles as $profile) {
            $alternates = [];
            $entryUrls = [];
            $translations = $profile->translations->sortBy('locale', SORT_STRING);

            foreach ($translations as $translation) {
                $url = $this->urls->resolve($translation->canonical_url ?? $translation->path);

                if ($url !== null) {
                    $alternates[$translation->locale] = $url;

                    if ($locations->allows($url, $sitemapUrl)) {
                        $entryUrls[$translation->locale] = $url;
                    }
                }
            }

            $fallbackLocale = config('translatable.fallback_locales.0');
            if (is_string($fallbackLocale) && isset($alternates[$fallbackLocale])) {
                $alternates['x-default'] = $alternates[$fallbackLocale];
            }

            foreach ($translations as $translation) {
                $url = $entryUrls[$translation->locale] ?? null;

                if ($url === null) {
                    continue;
                }

                yield new SitemapEntry(
                    url: $url,
                    changeFrequency: $profile->sitemap_change_frequency,
                    priority: $profile->sitemap_priority,
                    alternates: $alternates,
                );
            }
        }
    }

    /**
     * Return the shared sitemap location policy.
     */
    private function locationPolicy(): SitemapLocationPolicy
    {
        return $this->locations ?? new SitemapLocationPolicy;
    }
}
