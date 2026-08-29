<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Nvl\Seo\Data\SeoProfileData;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Services\SeoProfilePresenter;

/**
 * Loads one complete profile for management inspection.
 */
final readonly class GetSeoProfileAction
{
    /**
     * Create the complete management profile reader.
     */
    public function __construct(private SeoProfilePresenter $presenter) {}

    /**
     * Return one complete stable management profile.
     */
    public function execute(SeoProfile|string $profile): SeoProfileData
    {
        $profileId = $profile instanceof SeoProfile ? $profile->id : $profile;
        $profile = SeoProfile::query()
            ->with('translations')
            ->findOrFail($profileId);

        return $this->presenter->present($profile);
    }
}
