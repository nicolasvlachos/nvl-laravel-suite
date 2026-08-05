<?php

declare(strict_types=1);

namespace Nvl\Pages\Support;

use InvalidArgumentException;

/**
 * Canonicalizes structural paths and route segments.
 */
final class PagePath
{
    public static function slug(string $slug): string
    {
        $slug = trim($slug);

        if (preg_match('/^[a-z0-9](?:[a-z0-9_-]{0,189}[a-z0-9])?$/D', $slug) !== 1) {
            throw new InvalidArgumentException(
                'Page slugs must be lowercase URL-safe path segments.',
            );
        }

        return $slug;
    }

    public static function normalize(string $path): string
    {
        $path = trim(rawurldecode($path), '/');

        if ($path === ''
            || str_contains($path, '..')
            || str_contains($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\\x00-\\x1F\\x7F]/', $path) === 1
            || strlen($path) > PagesConfiguration::limit('maximum_path_bytes', 768)) {
            throw new InvalidArgumentException('Page path is invalid.');
        }

        foreach (explode('/', $path) as $segment) {
            self::slug($segment);
        }

        return $path;
    }

    public static function request(string $path): string
    {
        $path = trim(rawurldecode($path), '/');

        if ($path === ''
            || str_contains($path, '..')
            || str_contains($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\\x00-\\x1F\\x7F]/', $path) === 1
            || strlen($path) > PagesConfiguration::limit('maximum_path_bytes', 768)) {
            throw new InvalidArgumentException('Page request path is invalid.');
        }

        foreach (explode('/', $path) as $segment) {
            if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._~-]{0,253}[A-Za-z0-9])?$/D', $segment) !== 1) {
                throw new InvalidArgumentException('Page request path contains an invalid segment.');
            }
        }

        return $path;
    }

    public static function hash(string $site, string $path): string
    {
        return hash('sha256', $site."\0".$path);
    }

    private function __construct() {}
}
