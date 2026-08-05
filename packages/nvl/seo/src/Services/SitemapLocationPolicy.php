<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use LogicException;
use Nvl\Seo\Support\HttpUrl;

/**
 * Enforces crawler protocol origin and path ownership for sitemap URLs.
 */
final class SitemapLocationPolicy
{
    /**
     * Determine whether one URL belongs in the configured sitemap.
     */
    public function allows(string $url, string $sitemapUrl): bool
    {
        if ((bool) config('seo.sitemap.enforce_same_origin', true)
            && ! HttpUrl::hasSameOrigin($url, $sitemapUrl)) {
            return false;
        }

        if (! (bool) config('seo.sitemap.enforce_path_scope', true)) {
            return true;
        }

        $sitemapPath = parse_url($sitemapUrl, PHP_URL_PATH);
        $entryPath = parse_url($url, PHP_URL_PATH);

        if (! is_string($sitemapPath) || ! is_string($entryPath)) {
            return false;
        }

        $directory = str_replace('\\', '/', dirname($sitemapPath));
        $directory = $directory === '.' ? '/' : '/'.trim($directory, '/');

        return $directory === '/'
            || $entryPath === $directory
            || str_starts_with($entryPath, $directory.'/');
    }

    /**
     * Reject a source entry that violates sitemap location constraints.
     */
    public function assertAllowed(string $url, string $sitemapUrl): void
    {
        if ((bool) config('seo.sitemap.enforce_same_origin', true)
            && ! HttpUrl::hasSameOrigin($url, $sitemapUrl)) {
            throw new LogicException(
                "Sitemap entry [{$url}] does not share the sitemap origin.",
            );
        }

        if (! (bool) config('seo.sitemap.enforce_path_scope', true)) {
            return;
        }

        $sitemapPath = parse_url($sitemapUrl, PHP_URL_PATH);
        $entryPath = parse_url($url, PHP_URL_PATH);

        if (! is_string($sitemapPath) || ! is_string($entryPath)) {
            throw new LogicException('Sitemap and entry URLs require valid paths.');
        }

        $directory = str_replace('\\', '/', dirname($sitemapPath));
        $directory = $directory === '.' ? '/' : '/'.trim($directory, '/');

        if ($directory !== '/'
            && $entryPath !== $directory
            && ! str_starts_with($entryPath, $directory.'/')) {
            throw new LogicException(
                "Sitemap entry [{$url}] is outside sitemap path scope [{$directory}].",
            );
        }
    }
}
