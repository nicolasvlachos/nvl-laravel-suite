<?php

declare(strict_types=1);

namespace Nvl\Translations\Support;

use JsonException;

/**
 * Builds case-sensitive, length-safe identities for translation catalog records.
 */
final class TranslationIdentity
{
    /**
     * Build an identity for one translation entry.
     *
     * @throws JsonException
     */
    public static function entry(
        string $scopeType,
        string $scopeName,
        string $locale,
        string $format,
        string $group,
        string $key,
    ): string {
        return self::hash([$scopeType, $scopeName, $locale, $format, $group, $key]);
    }

    /**
     * Build an identity for one source-code usage hit.
     *
     * @throws JsonException
     */
    public static function usage(
        ?string $scopeType,
        ?string $scopeName,
        string $format,
        string $fullKey,
        string $filePath,
        int $line,
    ): string {
        return self::hash([$scopeType, $scopeName, $format, $fullKey, $filePath, $line]);
    }

    /**
     * Hash an ordered identity tuple without delimiter ambiguity.
     *
     * @param  list<int|string|null>  $parts
     *
     * @throws JsonException
     */
    private static function hash(array $parts): string
    {
        return hash('sha256', json_encode(
            $parts,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function __construct() {}
}
