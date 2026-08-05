<?php

declare(strict_types=1);

namespace Nvl\Seo\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Support\SeoScope;

/**
 * Adds scoped SEO profile access to an Eloquent owner.
 *
 * @mixin Model
 */
trait HasSeo
{
    /**
     * Return every site-scoped SEO profile attached to this model.
     *
     * @return MorphMany<SeoProfile, $this>
     */
    public function seoProfiles(): MorphMany
    {
        return $this->morphMany(SeoProfile::class, 'seoable');
    }

    /**
     * Resolve the profile for one site scope.
     */
    public function seoProfile(?string $scope = null): ?SeoProfile
    {
        return $this->seoProfiles()
            ->where('scope', SeoScope::normalize($scope))
            ->first();
    }
}
