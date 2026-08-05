<?php

declare(strict_types=1);

namespace Nvl\Templates\Support;

use InvalidArgumentException;
use Nvl\Templates\Definitions\Tables\TemplatesTables;

/**
 * Provides validated access to Templates configuration.
 */
final class TemplatesConfiguration
{
    /**
     * Return the configured database connection.
     */
    public static function connection(): ?string
    {
        $connection = config('templates.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * Return one configured package table.
     */
    public static function table(string $key): string
    {
        return TemplatesTables::get($key);
    }

    /**
     * Return one positive configured limit.
     */
    public static function limit(string $key, int $default): int
    {
        $value = config("templates.limits.{$key}", $default);

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("templates.limits.{$key} must be a positive integer.");
        }

        return $value;
    }

    /**
     * Return one positive integer from an absolute configuration key.
     */
    public static function positiveInteger(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("{$key} must be a positive integer.");
        }

        return $value;
    }

    /**
     * Return a non-empty configured string.
     */
    public static function string(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function __construct() {}
}
