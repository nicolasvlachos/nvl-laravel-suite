<?php

declare(strict_types=1);

namespace Nvl\Seo\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Normalizes and fingerprints route paths consistently across writes and reads.
 */
final class SeoPath
{
    /**
     * Normalize a relative path while preserving a root path.
     */
    public static function normalize(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim($path);

        if ($path === '') {
            return null;
        }

        if (mb_strlen($path) > 2048
            || preg_match('/[\x00-\x20\x7F\\\\]/', $path) === 1
            || str_contains($path, '?')
            || str_contains($path, '#')) {
            throw new InvalidArgumentException(
                'An SEO route path must contain only a path without whitespace, controls, query, or fragment.',
            );
        }

        if (preg_match('/%(?![A-Fa-f0-9]{2})/', $path) === 1) {
            throw new InvalidArgumentException(
                'An SEO route path cannot contain malformed percent encoding.',
            );
        }

        if (preg_match('/%(?:25|2f|5c)/i', $path) === 1) {
            throw new InvalidArgumentException(
                'An SEO route path cannot contain encoded percent signs or separators.',
            );
        }

        $path = preg_replace_callback(
            '/%([A-Fa-f0-9]{2})/',
            static function (array $matches): string {
                $character = chr((int) hexdec($matches[1]));

                return preg_match('/^[A-Za-z0-9._~-]$/', $character) === 1
                    ? $character
                    : '%'.strtoupper($matches[1]);
            },
            $path,
        ) ?? $path;
        $decodedPath = rawurldecode($path);

        if (preg_match('/[\x00-\x20\x7F\\\\]/', $decodedPath) === 1) {
            throw new InvalidArgumentException('An SEO route path cannot contain encoded controls or separators.');
        }

        foreach (explode('/', $decodedPath) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('An SEO route path cannot contain dot segments.');
            }
        }

        $path = '/'.ltrim(preg_replace('#/+#', '/', $path) ?? $path, '/');

        return $path !== '/' ? rtrim($path, '/') : '/';
    }

    /**
     * Create a fixed-width route uniqueness key.
     */
    public static function hash(string $scope, string $locale, ?string $path): ?string
    {
        $normalized = self::normalize($path);

        if ($normalized === null) {
            return null;
        }

        return hash('sha256', Str::lower($scope).'|'.Str::lower($locale).'|'.$normalized);
    }
}
