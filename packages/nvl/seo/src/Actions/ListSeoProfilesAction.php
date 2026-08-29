<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Seo\Contracts\SeoAuthorization;
use Nvl\Seo\Data\SeoProfileData;
use Nvl\Seo\Data\SeoProfileQuery;
use Nvl\Seo\Enums\SeoAbility;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Services\SeoOwnerRegistry;
use Nvl\Seo\Services\SeoProfilePresenter;
use Nvl\Seo\Support\SeoAuthorizationContext;
use Nvl\Seo\Support\SeoScope;

/**
 * Lists profiles through a fixed, allowlisted query surface.
 */
final class ListSeoProfilesAction
{
    /**
     * Create the profile-list action.
     */
    public function __construct(
        private readonly SeoAuthorization $authorization,
        private readonly SeoOwnerRegistry $owners,
        private readonly SeoProfilePresenter $presenter,
    ) {}

    /**
     * Return one authorized, bounded page of management profiles.
     *
     * @return LengthAwarePaginator<int, SeoProfileData>
     */
    public function execute(SeoProfileQuery $query): LengthAwarePaginator
    {
        $scope = $query->scope === null ? null : SeoScope::normalize($query->scope);
        $this->authorization->authorize(new SeoAuthorizationContext(
            ability: SeoAbility::List,
            ownerAlias: $query->ownerAlias,
            scope: $scope,
        ));
        $ownerMorphType = $query->ownerAlias === null
            ? null
            : $this->owners->morphTypeForAlias($query->ownerAlias);

        $profiles = SeoProfile::query()
            ->with('translations')
            ->when(
                $scope !== null,
                static fn ($builder) => $builder->where('scope', $scope),
            )
            ->when(
                $query->status !== null,
                static fn ($builder) => $builder->where('status', $query->status),
            )
            ->when(
                $ownerMorphType !== null,
                fn ($builder) => $builder->where(
                    'seoable_type',
                    $ownerMorphType,
                ),
            )
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->paginate(
                perPage: min(200, max(1, $query->perPage)),
                page: max(1, $query->page),
            );

        return $profiles->through(
            fn (SeoProfile $profile): SeoProfileData => $this->presenter->present($profile),
        );
    }
}
