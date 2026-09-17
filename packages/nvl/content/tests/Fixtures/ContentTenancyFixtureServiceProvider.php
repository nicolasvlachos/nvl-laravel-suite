<?php

declare(strict_types=1);

namespace Nvl\Content\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers the test-owned Content owner without changing package configuration. */
final class ContentTenancyFixtureServiceProvider extends ServiceProvider
{
    public function boot(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoptions): void
    {
        $resources->register(new TenantResourceDefinition(
            'test.content-owners',
            'content-test-owners',
            TenantContentOwner::class,
        ));
        $adoptions->register('content-test-owners', TenantContentOwnerAdoptionAdapter::class);
    }
}
