<?php

declare(strict_types=1);

namespace Nvl\Seo\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers the canonical owner resource used by SEO tenancy tests. */
final class SeoTenancyFixtureServiceProvider extends ServiceProvider
{
    public function boot(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoptions): void
    {
        $resources->register(new TenantResourceDefinition(
            'test.seo-owners',
            'seo-test-owners',
            TenantSeoOwner::class,
        ));
        $adoptions->register('seo-test-owners', TenantSeoOwnerAdoptionAdapter::class);
    }
}
