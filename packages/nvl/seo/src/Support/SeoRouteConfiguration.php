<?php

declare(strict_types=1);

namespace Nvl\Seo\Support;

use InvalidArgumentException;

/**
 * Validates configurable SEO public and management route paths and names.
 */
final class SeoRouteConfiguration
{
    /**
     * Return the normalized management URI prefix.
     */
    public static function managementPath(): string
    {
        return self::path('seo.management.path', 'api/v1/seo');
    }

    /**
     * Return the normalized management route-name prefix.
     */
    public static function managementName(): string
    {
        return self::name('seo.management.name', 'nvl.seo.management.');
    }

    /**
     * Return the normalized public route-name prefix.
     */
    public static function publicName(): string
    {
        return self::name('seo.routes.name', 'nvl.seo.');
    }

    /**
     * Return the validated sitemap URI.
     */
    public static function sitemapPath(): string
    {
        return self::path('seo.routes.sitemap_path', 'sitemap.xml');
    }

    /**
     * Return the validated sitemap chunk URI containing one chunk placeholder.
     */
    public static function sitemapChunkPath(): string
    {
        $path = self::path(
            'seo.routes.sitemap_chunk_path',
            'sitemap-{chunk}.xml',
            true,
        );

        if (substr_count($path, '{chunk}') !== 1) {
            throw new InvalidArgumentException(
                'seo.routes.sitemap_chunk_path must contain exactly one {chunk} placeholder.',
            );
        }

        return $path;
    }

    /**
     * Return the validated robots URI.
     */
    public static function robotsPath(): string
    {
        return self::path('seo.routes.robots_path', 'robots.txt');
    }

    /**
     * Validate one URI path.
     */
    private static function path(string $key, string $default, bool $allowChunk = false): string
    {
        $path = config($key, $default);

        if (! is_string($path)) {
            throw new InvalidArgumentException("{$key} must be a string.");
        }

        $path = trim($path, '/');
        $candidate = $allowChunk ? str_replace('{chunk}', '1', $path) : $path;

        if ($path === ''
            || str_contains($path, '..')
            || str_contains($path, '//')
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9/_.-]*$#', $candidate) !== 1) {
            throw new InvalidArgumentException("{$key} must be a safe, non-empty route path.");
        }

        return $path;
    }

    /**
     * Validate one route-name prefix.
     */
    private static function name(string $key, string $default): string
    {
        $name = config($key, $default);

        if (! is_string($name)) {
            throw new InvalidArgumentException("{$key} must be a string.");
        }

        $name = rtrim(trim($name), '.');

        if ($name === ''
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw new InvalidArgumentException("{$key} must be a safe route-name prefix.");
        }

        return $name.'.';
    }

    private function __construct() {}
}
