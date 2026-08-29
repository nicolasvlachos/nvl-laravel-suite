<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Nvl\Seo\Contracts\SeoAuthorization;
use Nvl\Seo\Data\SeoProfileData;
use Nvl\Seo\Enums\SeoAbility;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Services\SeoOwnerRegistry;
use Nvl\Seo\Services\SeoProfilePresenter;
use Nvl\Seo\Support\SeoAuthorizationContext;

/**
 * Loads one complete profile for management inspection.
 */
final readonly class GetSeoProfileAction
{
    /**
     * Create the complete management profile reader.
     */
    public function __construct(
        private SeoAuthorization $authorization,
        private SeoOwnerRegistry $owners,
        private SeoProfilePresenter $presenter,
    ) {}

    /**
     * Return one complete stable management profile.
     */
    public function execute(SeoProfile|string $profile): SeoProfileData
    {
        $profileId = $profile instanceof SeoProfile ? $profile->id : $profile;
        $profile = SeoProfile::query()
            ->with('translations')
            ->findOrFail($profileId);
        $owner = $profile->seoable()->firstOrFail();
        $this->authorization->authorize(new SeoAuthorizationContext(
            ability: SeoAbility::View,
            profile: $profile,
            owner: $owner,
            ownerAlias: $this->owners->aliasFor($owner),
            scope: $profile->scope,
        ));

        return $this->presenter->present($profile);
    }
}
