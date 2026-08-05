<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Nvl\Seo\Models\SeoRedirect;

/**
 * Resolves active redirects with localized-first global fallback semantics.
 */
final class SeoRedirectLookup
{
    /**
     * Find an exact localized redirect, then a locale-neutral fallback.
     */
    public function findActive(
        string $scope,
        ?string $locale,
        string $source,
        ?string $ignoreId = null,
    ): ?SeoRedirect {
        $redirect = $this->findExact($scope, $locale, $source, $ignoreId);

        if ($redirect instanceof SeoRedirect || $locale === null) {
            return $redirect;
        }

        return $this->findExact($scope, null, $source, $ignoreId);
    }

    /**
     * Find one exact active, non-expired redirect identity.
     */
    private function findExact(
        string $scope,
        ?string $locale,
        string $source,
        ?string $ignoreId,
    ): ?SeoRedirect {
        return SeoRedirect::query()
            ->where('source_hash', SeoRedirect::sourceHash($scope, $locale, $source))
            ->where('is_active', true)
            ->where(static function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->when(
                $ignoreId !== null,
                static fn ($query) => $query->whereKeyNot($ignoreId),
            )
            ->first();
    }
}
