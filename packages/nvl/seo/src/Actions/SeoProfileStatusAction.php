<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Nvl\Seo\Data\SeoProfileStatusData;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Support\SeoScope;

/**
 * Returns bounded aggregate status for management dashboards and imports.
 */
final class SeoProfileStatusAction
{
    /**
     * Return aggregate profile status for one optional normalized scope.
     */
    public function execute(?string $scope = null): SeoProfileStatusData
    {
        $scope = $scope === null ? null : SeoScope::normalize($scope);
        $query = SeoProfile::query()->when(
            $scope !== null,
            static fn ($builder) => $builder->where('scope', $scope),
        );
        $active = (clone $query)->where('status', 'active')->count();
        $archived = (clone $query)->where('status', 'archived')->count();

        return new SeoProfileStatusData(
            active: $active,
            archived: $archived,
            total: $active + $archived,
        );
    }
}
