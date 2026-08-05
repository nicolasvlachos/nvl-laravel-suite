<?php

declare(strict_types=1);

namespace Nvl\Content\Support;

use InvalidArgumentException;

/**
 * Typed access to package configuration used by persistence and limits.
 */
final class ContentConfiguration
{
    public static function connection(): ?string
    {
        $connection = config('content.connection');

        if ($connection === null || $connection === '') {
            return null;
        }

        if (! is_string($connection)) {
            throw new InvalidArgumentException('content.connection must be a string or null.');
        }

        return $connection;
    }

    public static function table(string $key): string
    {
        $table = config("content.tables.{$key}");

        if (! is_string($table) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("content.tables.{$key} must be a safe table name.");
        }

        return $table;
    }

    public static function positiveInteger(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("{$key} must be a positive integer.");
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public static function stringList(string $key): array
    {
        $value = config($key, []);

        if (! is_array($value)) {
            throw new InvalidArgumentException("{$key} must be an array.");
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException("{$key} must contain only non-empty strings.");
            }
        }

        return array_values(array_unique($value));
    }
}
