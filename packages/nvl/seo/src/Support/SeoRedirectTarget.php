<?php

declare(strict_types=1);

namespace Nvl\Seo\Support;

use InvalidArgumentException;

/**
 * Validates redirect targets while preserving internal query strings and fragments.
 */
final class SeoRedirectTarget
{
    /**
     * Return one safe absolute HTTP(S) URL or normalized application target.
     */
    public static function normalize(string $target): string
    {
        if (HttpUrl::isAbsolute($target)) {
            return $target;
        }

        if (! str_starts_with($target, '/')
            || str_starts_with($target, '//')
            || preg_match('/[\x00-\x20\x7F]/', $target) === 1) {
            throw new InvalidArgumentException(
                'A redirect target must be an absolute HTTP(S) URL or an application path.',
            );
        }

        $parts = parse_url($target);

        if (! is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])) {
            throw new InvalidArgumentException('The application redirect target is malformed.');
        }

        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '/';
        $normalized = SeoPath::normalize($path) ?? '/';
        $query = $parts['query'] ?? null;
        $fragment = $parts['fragment'] ?? null;

        if (is_string($query) && $query !== '') {
            $normalized .= '?'.$query;
        }

        if (is_string($fragment) && $fragment !== '') {
            $normalized .= '#'.$fragment;
        }

        return $normalized;
    }

    private function __construct() {}
}
