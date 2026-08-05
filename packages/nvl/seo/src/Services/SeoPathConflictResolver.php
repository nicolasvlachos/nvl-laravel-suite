<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Nvl\Seo\Exceptions\SeoPathConflictException;
use Nvl\Seo\Models\SeoProfileTranslation;
use Nvl\Seo\Support\SeoPath;

/**
 * Performs deterministic preflight checks before database uniqueness enforcement.
 */
final class SeoPathConflictResolver
{
    /**
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function assertAvailable(
        string $profileId,
        string $scope,
        array $translations,
    ): void {
        foreach ($translations as $locale => $translation) {
            $path = $translation['path'] ?? null;

            if (! is_string($path) || $path === '') {
                continue;
            }

            $hash = SeoPath::hash($scope, $locale, $path);

            if ($hash === null) {
                continue;
            }

            $conflict = SeoProfileTranslation::query()
                ->where('scope', $scope)
                ->where('locale', $locale)
                ->where('path_hash', $hash)
                ->where('seo_profile_id', '!=', $profileId)
                ->lockForUpdate()
                ->first(['seo_profile_id']);

            if ($conflict instanceof SeoProfileTranslation) {
                throw SeoPathConflictException::forRoute(
                    $scope,
                    $locale,
                    $path,
                    $conflict->seo_profile_id,
                );
            }
        }
    }
}
