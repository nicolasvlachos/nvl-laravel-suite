<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Nvl\Seo\Models\SeoProfile;

/**
 * Loads one complete profile for management inspection.
 */
final class GetSeoProfileAction
{
    public function execute(SeoProfile|string $profile): SeoProfile
    {
        $profileId = $profile instanceof SeoProfile ? $profile->id : $profile;

        return SeoProfile::query()
            ->with('translations')
            ->findOrFail($profileId);
    }
}
