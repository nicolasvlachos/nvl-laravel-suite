<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers the consumer-owned composition root and adoption adapter. */
final class TenantResourceCompositionServiceProvider extends ServiceProvider
{
    /** Register the host root before resource packages validate composition. */
    public function boot(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoptions): void
    {
        $resources->register(new TenantResourceDefinition(
            'test.resource-owners',
            'test.resource-owners',
            TenantResourceCompositionOwner::class,
        ));
        $adoptions->register('resource-composition-owners', TenantResourceCompositionOwnerAdapter::class);
    }
}
