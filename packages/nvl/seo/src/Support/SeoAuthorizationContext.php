<?php

declare(strict_types=1);

namespace Nvl\Seo\Support;

use Illuminate\Database\Eloquent\Model;
use Nvl\Seo\Enums\SeoAbility;
use Nvl\Seo\Models\SeoProfile;

/**
 * Carries complete source and target context to consumer-owned SEO authorization.
 */
final readonly class SeoAuthorizationContext
{
    /**
     * Create one authorization decision context.
     */
    public function __construct(
        public SeoAbility $ability,
        public ?SeoProfile $profile = null,
        public ?Model $owner = null,
        public ?string $ownerAlias = null,
        public ?Model $targetOwner = null,
        public ?string $targetOwnerAlias = null,
        public ?string $scope = null,
    ) {}
}
