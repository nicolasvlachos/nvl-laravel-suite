<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Seo\Contracts\SeoAuthorization;
use Nvl\Seo\Data\SeoOwnerRevisionData;
use Nvl\Seo\Enums\SeoAbility;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Services\SeoOwnerRegistry;
use Nvl\Seo\Support\SeoAuthorizationContext;
use Nvl\Seo\Support\SeoModelIdentifier;
use Nvl\Seo\Support\SeoScope;

/**
 * Returns the optimistic revision identity for one owner's scoped SEO profile.
 */
final readonly class GetOwnerSeoRevisionAction
{
    /**
     * Create the owner-centric revision reader.
     */
    public function __construct(
        private SeoAuthorization $authorization,
        private SeoOwnerRegistry $owners,
    ) {}

    /**
     * Return revision zero with no profile identifier when no profile exists.
     */
    public function execute(Model $owner, ?string $scope = null): SeoOwnerRevisionData
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
            ->where('seoable_type', $owner->getMorphClass())
            ->where('seoable_id', $ownerId)
            ->where('scope', $scope)
            ->first(['id', 'revision']);

        return new SeoOwnerRevisionData(
            ownerAlias: $ownerAlias,
            ownerId: $ownerId,
            scope: $scope,
            profileId: $profile instanceof SeoProfile ? $profile->id : null,
            revision: $profile instanceof SeoProfile ? $profile->revision : 0,
        );
    }
}
