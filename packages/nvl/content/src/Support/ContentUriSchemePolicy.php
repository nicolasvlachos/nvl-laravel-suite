<?php

declare(strict_types=1);

namespace Nvl\Content\Support;

use InvalidArgumentException;

/**
 * Enforces the package-wide hard boundary for URI schemes.
 */
final class ContentUriSchemePolicy
{
    private const array DENIED = [
        'data',
        'file',
        'javascript',
        'vbscript',
    ];

    /**
     * Validate one configured allowlist without weakening the hard-denied boundary.
     *
     * @param  array<array-key, mixed>  $schemes
     * @return list<string>
     */
    public static function validateAllowedSchemes(array $schemes, string $context): array
    {
        $allowed = [];

        foreach ($schemes as $scheme) {
            if (! is_string($scheme)
                || ! self::isValid($scheme)
                || self::isDenied($scheme)) {
                throw new InvalidArgumentException(
                    "{$context} must contain only safe lowercase URI schemes.",
                );
            }

            $allowed[$scheme] = true;
        }

        return array_keys($allowed);
    }

    /**
     * Retain only safe schemes when configuration is changed after package boot.
     *
     * @param  array<array-key, mixed>  $schemes
     * @return list<string>
     */
    public static function runtimeAllowedSchemes(array $schemes): array
    {
        $allowed = [];

        foreach ($schemes as $scheme) {
            if (! is_string($scheme)
                || ! self::isValid($scheme)
                || self::isDenied($scheme)) {
                continue;
            }

            $allowed[$scheme] = true;
        }

        return array_keys($allowed);
    }

    /**
     * Determine whether a parsed scheme remains available at runtime.
     *
     * @param  array<array-key, mixed>  $allowed
     */
    public static function allows(string $scheme, array $allowed): bool
    {
        $scheme = mb_strtolower($scheme);

        return ! self::isDenied($scheme)
            && in_array($scheme, self::runtimeAllowedSchemes($allowed), true);
    }

    private static function isDenied(string $scheme): bool
    {
        return in_array(mb_strtolower($scheme), self::DENIED, true);
    }

    private static function isValid(string $scheme): bool
    {
        return preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) === 1;
    }
}
