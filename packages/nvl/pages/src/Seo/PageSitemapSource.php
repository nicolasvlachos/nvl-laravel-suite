<?php

declare(strict_types=1);

namespace Nvl\Pages\Seo;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Pages\Contracts\PageUrlGenerator;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageResourceRegistry;
use Nvl\Seo\Contracts\SitemapSource;
use Nvl\Seo\Data\SitemapEntry;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Models\SeoProfileTranslation;
use Nvl\Seo\Services\EloquentSeoSitemapSource;

/**
 * Owns Page sitemap eligibility and composes SEO metadata or dynamic handler entries.
 */
final readonly class PageSitemapSource implements SitemapSource
{
    /**
     * Create the static and dynamic page sitemap source.
     */
    public function __construct(
        private PageUrlGenerator $urls,
        private PageResourceRegistry $resources,
        private EloquentSeoSitemapSource $seo,
    ) {}

    /**
     * @return iterable<SitemapEntry>
     */
    public function entries(string $scope): iterable
    {
        $pages = Page::query()
            ->where('site', $scope)
            ->where('sitemap_included', true)
            ->publiclyVisible()
            ->with([
                'translations',
                'seoProfiles' => static function (Relation $profiles) use ($scope): void {
                    $profiles->getBaseQuery()->where('scope', $scope)->where('status', 'active')->whereNull('archived_at');
                },
                'seoProfiles.translations',
            ])
            ->lazyById(500, column: 'id');

        foreach ($pages as $page) {
            $profile = $page->seoProfiles->first(
                static fn (SeoProfile $profile): bool => $profile->scope === $scope
                    && $profile->status === 'active'
                    && $profile->archived_at === null,
            );

            if ($profile instanceof SeoProfile
                && (! $profile->is_indexable || ! $profile->sitemap_included)) {
                continue;
            }

            if ($page->kind === PageKind::Resource) {
                foreach ($this->resourceEntries($page, $scope) as $entry) {
                    yield $entry;
                }

                continue;
            }

            if ($profile instanceof SeoProfile
                && $profile->translations->contains(
                    static fn (SeoProfileTranslation $translation): bool => $translation->canonical_url !== null
                        || $translation->path !== null,
                )) {
                foreach ($this->seo->entriesForProfile($profile) as $entry) {
                    yield $entry;
                }

                continue;
            }

            foreach ($this->staticEntries($page) as $entry) {
                yield $entry;
            }
        }
    }

    /**
     * @return iterable<SitemapEntry>
     */
    private function resourceEntries(Page $page, string $scope): iterable
    {
        if ($page->resource === null || ! $this->resources->has($page->resource)) {
            return [];
        }

        foreach ($this->resources->get($page->resource)->sitemapEntries($page, $scope) as $entry) {
            yield $entry;
        }
    }

    /**
     * @return iterable<SitemapEntry>
     */
    private function staticEntries(Page $page): iterable
    {
        $localePrefix = (bool) config('pages.urls.locale_prefix', false);
        $defaultLocale = config('pages.urls.default_locale', config('app.locale', 'en'));
        $locales = $page->translations->pluck('locale')->all();
        $locales = array_values(array_filter($locales, 'is_string'));

        if (! $localePrefix) {
            $locale = is_string($defaultLocale) ? $defaultLocale : null;

            yield $this->entry($page, $locale, []);

            return;
        }

        if ($locales === []) {
            $locale = is_string($defaultLocale) ? $defaultLocale : null;

            yield $this->entry($page, $locale, []);

            return;
        }

        $alternates = [];

        foreach ($locales as $locale) {
            $alternates[$locale] = $this->urls->url($page, $locale);
        }

        if (is_string($defaultLocale) && isset($alternates[$defaultLocale])) {
            $alternates['x-default'] = $alternates[$defaultLocale];
        }

        foreach ($locales as $locale) {
            yield $this->entry($page, $locale, $alternates);
        }
    }

    /**
     * @param  array<string, string>  $alternates
     */
    private function entry(Page $page, ?string $locale, array $alternates): SitemapEntry
    {
        $translation = $locale !== null
            ? $page->translations->firstWhere('locale', $locale)
            : null;
        $lastModified = $translation?->updated_at;

        if (! $lastModified instanceof DateTimeInterface
            || $page->updated_at->greaterThan($lastModified)) {
            $lastModified = $page->updated_at;
        }

        return new SitemapEntry(
            url: $this->urls->url($page, $locale),
            lastModified: $lastModified,
            changeFrequency: $page->sitemap_change_frequency,
            priority: $page->sitemap_priority,
            alternates: $alternates,
        );
    }
}
