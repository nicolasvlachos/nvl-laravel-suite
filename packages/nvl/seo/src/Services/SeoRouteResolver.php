<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Models\SeoProfileTranslation;
use Nvl\Seo\Support\SeoPath;
use Nvl\Seo\Support\SeoScope;
use Nvl\Translatable\Support\LocaleCode;

/**
 * Resolves one SEO profile by its unique site, locale, and normalized path.
 */
final class SeoRouteResolver
{
    public function resolve(
        string $path,
        string $locale,
        ?string $scope = null,
    ): ?SeoProfile {
        $scope = SeoScope::normalize($scope);
        $locale = (new LocaleCode($locale))->value;
        $hash = SeoPath::hash($scope, $locale, $path);

        if ($hash === null) {
            return null;
        }

        $translation = SeoProfileTranslation::query()
            ->where('scope', $scope)
            ->where('locale', $locale)
            ->where('path_hash', $hash)
            ->whereIn(
                'seo_profile_id',
                SeoProfile::query()
                    ->active()
                    ->select('id'),
            )
            ->with(['profile.translations'])
            ->first();

        return $translation?->profile;
    }
}
