<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Seo\Contracts\SeoImageResolver;
use Nvl\Seo\Data\ResolvedSeoData;
use Nvl\Seo\Data\StructuredDataContextData;
use Nvl\Seo\Enums\TwitterCard;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Models\SeoProfileTranslation;
use Nvl\Seo\Support\SeoConfiguration;
use Nvl\Seo\Support\SeoImageContext;
use Nvl\Seo\Support\SeoModelIdentifier;
use Nvl\Translatable\Services\ContentLocale;

/**
 * Resolves persisted SEO, deterministic locale fallbacks, and site defaults.
 */
final readonly class SeoMetadataResolver
{
    public function __construct(
        private ContentLocale $contentLocale,
        private SeoImageResolver $images,
        private AbsoluteUrl $urls,
        private StructuredDataResolver $structuredData,
    ) {}

    public function resolve(
        Model|SeoProfile $owner,
        ?string $locale = null,
        ?string $scope = null,
    ): ResolvedSeoData {
        $locale ??= $this->contentLocale->get();
        $profile = $owner instanceof SeoProfile
            ? $owner
            : SeoProfile::query()
                ->forOwner($owner, $scope)
                ->active()
                ->with('translations')
                ->first();

        if (! $profile instanceof SeoProfile || $profile->status !== 'active') {
            return $this->defaults($locale);
        }

        $profile->loadMissing('translations');
        $translation = $profile->getTranslation($locale);

        if (! $translation instanceof SeoProfileTranslation) {
            return $this->defaults($locale, $this->robots($profile));
        }

        $canonical = $this->urls->resolve($translation->canonical_url ?? $translation->path);
        $plainTitle = $this->translatedString($profile, 'title', $locale);
        $description = $this->translatedString($profile, 'description', $locale);
        $plainTitle = $plainTitle === null
            ? $this->stringConfig('seo.defaults.title')
            : $plainTitle;
        $description = $description === null
            ? $this->stringConfig('seo.defaults.description')
            : ($description !== '' ? $description : null);
        $image = $this->images->resolve(new SeoImageContext(
            profile: $profile,
            translation: $translation,
            locale: $locale,
            url: $this->translatedString($profile, 'image_url', $locale),
            reference: $this->translatedString($profile, 'image_reference', $locale),
            alt: $this->translatedString($profile, 'image_alt', $locale),
        ));
        $alternates = [];
        $translations = $profile->translations->sortBy('locale', SORT_STRING);

        foreach ($translations as $alternate) {
            $url = $this->urls->resolve($alternate->canonical_url ?? $alternate->path);

            if ($url !== null) {
                $alternates[$alternate->locale] = $url;
            }
        }

        $fallbackLocale = config('translatable.fallback_locales.0');
        if (is_string($fallbackLocale) && isset($alternates[$fallbackLocale])) {
            $alternates['x-default'] = $alternates[$fallbackLocale];
        }
        $openGraphLocales = [];

        foreach (array_keys($alternates) as $alternateLocale) {
            if ($alternateLocale !== 'x-default' && $alternateLocale !== $translation->locale) {
                $openGraphLocales[] = str_replace('-', '_', $alternateLocale);
            }
        }

        $openGraphTitle = $this->translatedString($profile, 'open_graph_title', $locale);
        $openGraphDescription = $this->translatedString(
            $profile,
            'open_graph_description',
            $locale,
        );
        $openGraph = array_filter([
            'og:type' => SeoConfiguration::string('seo.site.open_graph_type', 'website'),
            'og:locale' => str_replace('-', '_', $translation->locale),
            'og:site_name' => SeoConfiguration::string('seo.site.name', ''),
            'og:title' => $openGraphTitle ?? $plainTitle,
            'og:description' => $openGraphDescription ?? $description,
            'og:url' => $canonical,
            'og:image' => $image?->url,
            'og:image:alt' => $image?->alt,
            'og:image:width' => $image?->width !== null ? (string) $image->width : null,
            'og:image:height' => $image?->height !== null ? (string) $image->height : null,
            'og:image:type' => $image?->mimeType,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
        $twitterCard = $profile->translated('twitter_card', $locale);
        $twitter = array_filter([
            'twitter:card' => $twitterCard instanceof TwitterCard
                ? $twitterCard->value
                : (is_string($twitterCard) && $twitterCard !== ''
                    ? $twitterCard
                    : SeoConfiguration::string(
                        'seo.defaults.twitter_card',
                        'summary_large_image',
                    )),
            'twitter:site' => $this->stringConfig('seo.site.twitter_site'),
            'twitter:creator' => $this->stringConfig('seo.site.twitter_creator'),
            'twitter:title' => $this->translatedString($profile, 'twitter_title', $locale)
                ?? $openGraphTitle
                ?? $plainTitle,
            'twitter:description' => $this->translatedString(
                $profile,
                'twitter_description',
                $locale,
            )
                ?? $openGraphDescription
                ?? $description,
            'twitter:image' => $image?->url,
            'twitter:image:alt' => $image?->alt,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
        $resource = $owner instanceof SeoProfile
            ? $profile->seoable()->first()
            : $owner;
        $resource = $resource instanceof Model ? $resource : $profile;
        $siteUrl = $this->urls->resolve('/') ?? SeoConfiguration::string(
            'seo.site.base_url',
            'http://localhost',
        );

        return new ResolvedSeoData(
            locale: $translation->locale,
            title: $this->brandedTitle($plainTitle),
            description: $description,
            canonicalUrl: $canonical,
            robots: $this->robots($profile),
            alternates: $alternates,
            openGraph: $openGraph,
            openGraphLocales: $openGraphLocales,
            twitter: $twitter,
            structuredData: $this->structuredData->resolve(
                resource: $resource,
                context: new StructuredDataContextData(
                    resourceType: $resource->getMorphClass(),
                    resourceId: SeoModelIdentifier::required($resource),
                    profileId: $profile->id,
                    locale: $translation->locale,
                    scope: $profile->scope,
                    canonicalUrl: $canonical,
                    title: $plainTitle,
                    description: $description,
                    imageUrl: $image?->url,
                    siteName: SeoConfiguration::string('seo.site.name', ''),
                    siteUrl: rtrim($siteUrl, '/'),
                ),
                persisted: $this->translatedArray($profile, 'structured_data', $locale),
            ),
        );
    }

    private function defaults(string $locale, ?string $robots = null): ResolvedSeoData
    {
        return new ResolvedSeoData(
            locale: $locale,
            title: $this->brandedTitle($this->stringConfig('seo.defaults.title')),
            description: $this->stringConfig('seo.defaults.description'),
            canonicalUrl: null,
            robots: $robots ?? $this->defaultRobots(),
            alternates: [],
            openGraph: [],
            openGraphLocales: [],
            twitter: [],
            structuredData: [],
        );
    }

    private function brandedTitle(?string $title): ?string
    {
        if ($title === '') {
            return null;
        }

        $siteName = $this->stringConfig('seo.site.name');

        if ($title === null) {
            return $siteName;
        }

        if ($siteName === null || $siteName === '' || $siteName === $title) {
            return $title;
        }

        $separator = SeoConfiguration::string('seo.site.title_separator', ' | ');

        return config('seo.site.title_position', 'suffix') === 'prefix'
            ? $siteName.$separator.$title
            : $title.$separator.$siteName;
    }

    private function robots(SeoProfile $profile): string
    {
        $directives = [
            $profile->is_indexable ? 'index' : 'noindex',
            $profile->is_followable ? 'follow' : 'nofollow',
        ];

        if ($profile->max_snippet !== null) {
            $directives[] = 'max-snippet:'.$profile->max_snippet;
        }

        if ($profile->max_image_preview !== null) {
            $directives[] = 'max-image-preview:'.$profile->max_image_preview;
        }

        if ($profile->max_video_preview !== null) {
            $directives[] = 'max-video-preview:'.$profile->max_video_preview;
        }

        return implode(', ', $directives);
    }

    private function defaultRobots(): string
    {
        $index = (bool) config('seo.defaults.robots.index', true);
        $follow = (bool) config('seo.defaults.robots.follow', true);
        $directives = [$index ? 'index' : 'noindex', $follow ? 'follow' : 'nofollow'];
        $maxImage = $this->stringConfig('seo.defaults.robots.max_image_preview');
        $maxSnippet = config('seo.defaults.robots.max_snippet');
        $maxVideo = config('seo.defaults.robots.max_video_preview');

        if (is_int($maxSnippet)) {
            $directives[] = 'max-snippet:'.$maxSnippet;
        }

        if ($maxImage !== null) {
            $directives[] = 'max-image-preview:'.$maxImage;
        }

        if (is_int($maxVideo)) {
            $directives[] = 'max-video-preview:'.$maxVideo;
        }

        return implode(', ', $directives);
    }

    private function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function translatedString(SeoProfile $profile, string $field, string $locale): ?string
    {
        $value = $profile->translated($field, $locale);

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function translatedArray(SeoProfile $profile, string $field, string $locale): ?array
    {
        $value = $profile->translated($field, $locale);

        return is_array($value) ? $value : null;
    }
}
