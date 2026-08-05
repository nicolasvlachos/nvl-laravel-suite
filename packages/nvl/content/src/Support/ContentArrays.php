<?php

declare(strict_types=1);

namespace Nvl\Content\Support;

use InvalidArgumentException;

/**
 * Runtime array-shape normalization for decoded JSON and Eloquent array casts.
 */
final class ContentArrays
{
    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    public static function stringMap(array $value, string $label): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException("{$label} must be a JSON object.");
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, array<string, mixed>>
     */
    public static function translations(array $value, string $label): array
    {
        $normalized = [];

        foreach ($value as $locale => $values) {
            if (! is_string($locale) || ! is_array($values)) {
                throw new InvalidArgumentException(
                    "{$label} must map locale strings to JSON objects.",
                );
            }

            $normalized[$locale] = self::stringMap($values, "{$label}.{$locale}");
        }

        return $normalized;
    }
}
