<?php

declare(strict_types=1);

namespace Nvl\Translations\Support;

use Nvl\Translations\Exceptions\TranslationsException;

/**
 * Normalizes values crossing Laravel's untyped configuration boundary.
 */
final class TranslationConfiguration
{
    /**
     * Return a configured string or its typed default.
     */
    public static function string(string $key, string $default): string
    {
        $value = config($key, $default);

        if (! is_string($value)) {
            throw new TranslationsException("Translation config [{$key}] must be a string.");
        }

        return $value;
    }

    /**
     * Return a configured positive integer or its typed default.
     */
    public static function positiveInteger(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value <= 0) {
            throw new TranslationsException("Translation config [{$key}] must be a positive integer.");
        }

        return $value;
    }

    /**
     * Return a configured non-negative integer or its typed default.
     */
    public static function nonNegativeInteger(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value < 0) {
            throw new TranslationsException("Translation config [{$key}] must be a non-negative integer.");
        }

        return $value;
    }

    private function __construct() {}
}
