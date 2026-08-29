<?php

declare(strict_types=1);

namespace Nvl\Pages\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Normalizes portable Page identities and bounded selector input.
 */
final class PageIdentityGuard
{
    /**
     * Normalize one site identifier.
     */
    public function site(string $site): string
    {
        $site = trim($site);

        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $site) !== 1) {
            throw new InvalidArgumentException('Page site identifiers are invalid.');
        }

        return $site;
    }

    /**
     * Normalize one globally unique Page key.
     */
    public function key(string $key): string
    {
        $key = trim($key);

        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Page keys are invalid.');
        }

        return $key;
    }

    /**
     * Normalize one UUID Page identifier.
     */
    public function id(string $id): string
    {
        $id = trim($id);

        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('Page identifiers must be UUIDs.');
        }

        return strtolower($id);
    }

    /**
     * Normalize one optional bounded selector search.
     */
    public function search(?string $search): ?string
    {
        $search = (string) $search;

        if (! mb_check_encoding($search, 'UTF-8') || str_contains($search, "\0")) {
            throw new InvalidArgumentException(
                'Page option search must be valid UTF-8 without NUL bytes.',
            );
        }

        $search = trim($search);

        if (mb_strlen($search) > 160) {
            throw new InvalidArgumentException(
                'Page option search may not exceed 160 characters.',
            );
        }

        return $search !== '' ? $search : null;
    }
}
