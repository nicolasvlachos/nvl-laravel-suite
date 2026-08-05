<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Nvl\Seo\Data\SeoProfileData;
use Nvl\Seo\Models\SeoProfile;

/**
 * Shapes privileged SEO profile management data with stable owner aliases.
 */
final readonly class SeoProfilePresenter
{
    /**
     * Create the profile presenter.
     */
    public function __construct(private SeoOwnerRegistry $owners) {}

    /**
     * Shape one profile for a management consumer.
     */
    public function present(SeoProfile $profile): SeoProfileData
    {
        return SeoProfileData::fromModel(
            $profile,
            $this->owners->aliasForMorphType($profile->seoable_type),
        );
    }
}
