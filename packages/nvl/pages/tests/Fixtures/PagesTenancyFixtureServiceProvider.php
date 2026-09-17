<?php

declare(strict_types=1);

namespace Nvl\Pages\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers the dynamic Page resource fixture with the canonical tenancy graph. */
final class PagesTenancyFixtureServiceProvider extends ServiceProvider
{
    public function boot(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoptions): void
    {
        $resources->register(new TenantResourceDefinition(
            'test.page-resources',
            'page-test-resources',
            TenantPageResource::class,
        ));
        $adoptions->register('page-test-resources', TenantPageResourceAdoptionAdapter::class);
    }
}
