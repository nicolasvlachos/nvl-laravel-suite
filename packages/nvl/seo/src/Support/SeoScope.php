<?php

declare(strict_types=1);

namespace Nvl\Seo\Support;

use Illuminate\Container\Container;
use LogicException;
use Nvl\Seo\Exceptions\InvalidSeoMutationException;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;

/**
 * Normalizes a stable site/storefront SEO identity.
 */
final class SeoScope
{
    /**
     * Return the normalized public sitemap scope allowlist.
     *
     * The configured site scope is always public when routes are enabled.
     *
     * @return list<string>
     */
    public static function publicSitemapScopes(): array
    {
        $tenantSite = self::tenantSite();
        if ($tenantSite instanceof TenantSiteContext) {
            return [self::normalize($tenantSite->site)];
        }

        $configured = config('seo.routes.sitemap_scopes', []);

        if (! is_array($configured)) {
            throw new LogicException(
                'seo.routes.sitemap_scopes must be an array of SEO scopes.',
            );
        }

        $scopes = [self::normalize()];

        foreach ($configured as $scope) {
            if (! is_string($scope)) {
                throw new LogicException(
                    'Every seo.routes.sitemap_scopes value must be a string.',
                );
            }

            $scopes[] = self::normalize($scope);
        }

        $scopes = array_values(array_unique($scopes));
        sort($scopes);

        return $scopes;
    }

    public static function normalize(?string $scope = null): string
    {
        $tenantSite = self::tenantSite();
        if ($tenantSite instanceof TenantSiteContext) {
            if ($scope !== null && mb_strtolower(trim($scope)) !== mb_strtolower(trim($tenantSite->site))) {
                throw InvalidSeoMutationException::forField('scope', 'The SEO scope differs from the verified tenant site.');
            }
            $scope = $tenantSite->site;
        }

        $configuredScope = config('seo.site.scope');
        $scope ??= is_string($configuredScope)
            ? $configuredScope
            : 'default';
        $scope = mb_strtolower(trim($scope));

        if (
            $scope === ''
            || mb_strlen($scope) > 100
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $scope) !== 1
        ) {
            throw InvalidSeoMutationException::forField(
                'scope',
                'An SEO scope must contain 1–100 lowercase letters, numbers, dots, underscores, or hyphens.',
            );
        }

        return $scope;
    }

    /** Resolve public site identity only in adopted mode. */
    private static function tenantSite(): ?TenantSiteContext
    {
        $container = Container::getInstance();
        if (! $container->bound('config') || $container->make('config')->get('tenancy.enabled') !== true) {
            return null;
        }

        return $container->make(TenantSiteContext::class);
    }
}
