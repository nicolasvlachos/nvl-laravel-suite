<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use LogicException;
use Nvl\Seo\Support\SeoConfiguration;
use Nvl\Seo\Support\SeoRouteConfiguration;

/**
 * Renders a configurable robots.txt without conflating crawl and index rules.
 */
final readonly class RobotsGenerator
{
    public function __construct(
        private AbsoluteUrl $urls,
    ) {}

    public function generate(): string
    {
        $userAgent = SeoConfiguration::string('seo.robots.user_agent', '*');

        if (preg_match('/^(?:\*|[A-Za-z][A-Za-z0-9_-]*)$/', $userAgent) !== 1) {
            throw new LogicException('seo.robots.user_agent must be a valid crawler product token.');
        }

        $lines = ['User-agent: '.$userAgent];

        foreach ($this->pathList(config('seo.robots.allow', ['/']), 'allow') as $path) {
            $lines[] = 'Allow: '.$path;
        }

        foreach ($this->pathList(config('seo.robots.disallow', []), 'disallow') as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        if ((bool) config('seo.robots.include_sitemap', true)) {
            $sitemap = $this->urls->resolve(
                SeoRouteConfiguration::sitemapPath(),
            );

            if ($sitemap !== null) {
                $lines[] = '';
                $lines[] = 'Sitemap: '.$sitemap;
            }
        }

        $content = implode("\n", $lines)."\n";
        $maximumBytes = min(
            512_000,
            SeoConfiguration::positiveInteger('seo.robots.maximum_bytes', 512_000),
        );

        if (strlen($content) > $maximumBytes) {
            throw new LogicException(
                "The generated robots.txt exceeds the configured {$maximumBytes}-byte limit.",
            );
        }

        return $content;
    }

    /**
     * @return list<string>
     */
    private function pathList(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            throw new LogicException("seo.robots.{$key} must be an array of paths.");
        }

        $paths = [];

        foreach ($value as $path) {
            if (! is_string($path)
                || $path === ''
                || mb_strlen($path) > 2048
                || ! str_starts_with($path, '/')
                || str_contains($path, '#')
                || preg_match('/[\x00-\x20\x7F]/', $path) === 1) {
                throw new LogicException(
                    "Every seo.robots.{$key} value must be a non-empty crawler path without whitespace, controls, or fragment markers.",
                );
            }

            $paths[] = $path;
        }

        return $paths;
    }
}
