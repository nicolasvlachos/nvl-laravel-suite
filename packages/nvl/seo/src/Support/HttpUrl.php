<?php

declare(strict_types=1);

namespace Nvl\Seo\Support;

/**
 * Validates absolute crawler-facing HTTP and HTTPS URLs.
 */
final class HttpUrl
{
    /**
     * Determine whether a value is a safe absolute crawler-facing URL.
     */
    public static function isAbsolute(string $value): bool
    {
        if ($value === ''
            || $value !== trim($value)
            || mb_strlen($value) > 2048
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            return false;
        }

        $parts = parse_url($value);

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && is_string($parts['scheme'] ?? null)
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    /**
     * Determine whether a value is suitable for canonical and sitemap identity.
     */
    public static function isCanonical(string $value): bool
    {
        return self::isAbsolute($value)
            && parse_url($value, PHP_URL_FRAGMENT) === null;
    }

    /**
     * Determine whether a value can safely prefix application-relative URLs.
     */
    public static function isBase(string $value): bool
    {
        return self::isAbsolute($value)
            && parse_url($value, PHP_URL_QUERY) === null
            && parse_url($value, PHP_URL_FRAGMENT) === null;
    }

    /**
     * Determine whether two absolute URLs share a normalized origin.
     */
    public static function hasSameOrigin(string $left, string $right): bool
    {
        $leftOrigin = self::origin($left);
        $rightOrigin = self::origin($right);

        return $leftOrigin !== null && $leftOrigin === $rightOrigin;
    }

    /**
     * Return the normalized scheme, host, and effective port for one URL.
     */
    private static function origin(string $value): ?string
    {
        if (! self::isAbsolute($value)) {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts)
            || ! is_string($parts['scheme'] ?? null)
            || ! is_string($parts['host'] ?? null)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.strtolower($parts['host']).':'.$port;
    }
}
