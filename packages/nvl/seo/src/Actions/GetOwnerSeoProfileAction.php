<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Seo\Contracts\SeoAuthorization;
use Nvl\Seo\Data\SeoProfileData;
use Nvl\Seo\Enums\SeoAbility;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Services\SeoOwnerRegistry;
use Nvl\Seo\Services\SeoProfilePresenter;
use Nvl\Seo\Support\SeoAuthorizationContext;
use Nvl\Seo\Support\SeoModelIdentifier;
use Nvl\Seo\Support\SeoScope;

/**
 * Returns one complete authorized SEO profile from its owning model.
 */
final readonly class GetOwnerSeoProfileAction
{
    /**
     * Create the owner-centric profile reader.
     */
    public function __construct(
        private SeoAuthorization $authorization,
        private SeoOwnerRegistry $owners,
        private SeoProfilePresenter $presenter,
    ) {}

    /**
     * Return the complete profile projection, or null when the owner has none.
     */
    public function execute(Model $owner, ?string $scope = null): ?SeoProfileData
    {
        $scope = SeoScope::normalize($scope);
        $ownerAlias = $this->owners->aliasFor($owner);
        $ownerId = SeoModelIdentifier::required($owner);
        $this->authorization->authorize(new SeoAuthorizationContext(
            ability: SeoAbility::View,
            owner: $owner,
            ownerAlias: $ownerAlias,
            scope: $scope,
        ));
        $profile = SeoProfile::query()
            ->with('translations')
            ->where('seoable_type', $owner->getMorphClass())
            ->where('seoable_id', $ownerId)
            ->where('scope', $scope)
            ->first();

        return $profile instanceof SeoProfile
            ? $this->presenter->present($profile)
            : null;
    }
}
