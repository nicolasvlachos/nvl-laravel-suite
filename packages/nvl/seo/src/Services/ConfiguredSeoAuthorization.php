<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Nvl\Seo\Contracts\SeoAuthorization;
use Nvl\Seo\Support\SeoAuthorizationContext;

/**
 * Gate-backed default that denies access until explicitly configured.
 */
final class ConfiguredSeoAuthorization implements SeoAuthorization
{
    /**
     * Authorize through the configured consumer Gate ability.
     */
    public function authorize(SeoAuthorizationContext $context): void
    {
        $configuredAbility = config('seo.authorization.ability');

        if (! is_string($configuredAbility) || $configuredAbility === '') {
            throw new AuthorizationException(
                'SEO management requires an authorization binding or configured Gate ability.',
            );
        }

        Gate::authorize($configuredAbility, [$context]);
    }
}
