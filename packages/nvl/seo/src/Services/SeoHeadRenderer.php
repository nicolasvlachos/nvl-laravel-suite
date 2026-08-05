<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Support\HtmlString;
use JsonException;
use Nvl\Seo\Data\ResolvedSeoData;

/**
 * Renders escaped, deterministic head markup from resolved metadata.
 */
final class SeoHeadRenderer
{
    /**
     * @throws JsonException
     */
    public function render(ResolvedSeoData $seo): HtmlString
    {
        $tags = [];

        if ($seo->title !== null) {
            $tags[] = '<title>'.$this->escape($seo->title).'</title>';
        }

        if ($seo->description !== null) {
            $tags[] = $this->meta('name', 'description', $seo->description);
        }

        $tags[] = $this->meta('name', 'robots', $seo->robots);

        if ($seo->canonicalUrl !== null) {
            $tags[] = $this->link('canonical', $seo->canonicalUrl);
        }

        foreach ($seo->alternates as $locale => $url) {
            $tags[] = '<link rel="alternate" hreflang="'.$this->escape($locale).'" href="'.$this->escape($url).'">';
        }

        foreach ($seo->openGraph as $property => $content) {
            $tags[] = $this->meta('property', $property, $content);
        }

        foreach ($seo->openGraphLocales as $locale) {
            $tags[] = $this->meta('property', 'og:locale:alternate', $locale);
        }

        foreach ($seo->twitter as $name => $content) {
            $tags[] = $this->meta('name', $name, $content);
        }

        foreach ($seo->structuredData as $structuredData) {
            $tags[] = '<script type="application/ld+json">'
                .json_encode(
                    $structuredData,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
                )
                .'</script>';
        }

        return new HtmlString(implode("\n", $tags));
    }

    private function meta(string $attribute, string $key, string $content): string
    {
        return '<meta '.$attribute.'="'.$this->escape($key).'" content="'.$this->escape($content).'">';
    }

    private function link(string $relation, string $url): string
    {
        return '<link rel="'.$this->escape($relation).'" href="'.$this->escape($url).'">';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
