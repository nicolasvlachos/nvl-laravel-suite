<?php

declare(strict_types=1);

namespace Nvl\Translations\Support;

/**
 * Builds type-aware hashes for authoritative translation leaf values.
 */
final class TranslationValueHash
{
    /**
     * Hash one string or null value without conflating null with text.
     */
    public static function make(?string $value): string
    {
        return hash('sha256', $value === null ? "null\0" : "string\0{$value}");
    }

    private function __construct() {}
}
