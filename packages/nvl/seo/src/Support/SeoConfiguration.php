<?php

declare(strict_types=1);

namespace Nvl\Seo\Support;

/**
 * Normalizes values crossing Laravel's untyped configuration boundary.
 */
final class SeoConfiguration
{
    public static function string(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    public static function positiveInteger(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }

    public static function nonNegativeInteger(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) && $value >= 0 ? $value : $default;
    }

    private function __construct() {}
}
