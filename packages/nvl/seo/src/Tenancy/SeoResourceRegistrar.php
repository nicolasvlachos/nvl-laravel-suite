<?php

declare(strict_types=1);

namespace Nvl\Seo\Tenancy;

use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Models\SeoProfileTranslation;
use Nvl\Seo\Models\SeoRedirect;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers the SEO ownership graph and package adopter. */
final class SeoResourceRegistrar
{
    public function register(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoption): void
    {
        $resources->registerParentResolver('seo.profiles', SeoTenantParentResolver::class);

        foreach ([
            new TenantResourceDefinition('seo.profiles', 'seo', SeoProfile::class, TenantResourceKind::Inherited, null, 'seoable'),
            new TenantResourceDefinition('seo.translations', 'seo', SeoProfileTranslation::class, TenantResourceKind::Inherited, 'seo.profiles', 'profile'),
            new TenantResourceDefinition('seo.redirects', 'seo', SeoRedirect::class),
        ] as $definition) {
            $resources->register($definition);
        }

        $adoption->register('seo', SeoAdoptionAdapter::class);
    }
}
