<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\TenancyConsumerSmokeCommand;
use App\Consumers\TaxonomyOnlyConsumerWorkflow;
use App\Contracts\TenancyConsumerWorkflow;
use App\Models\TenantTaxonomyRecord;
use App\Tenancy\HostMembershipAccess;
use App\Tenancy\TenantTaxonomyRecordAdoptionAdapter;
use Illuminate\Support\ServiceProvider;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers the standalone Taxonomy profile without Auth or Media. */
final class TaxonomyOnlyTenancyConsumerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HostMembershipAccess::class);
        $this->app->bind(TenancyConsumerWorkflow::class, TaxonomyOnlyConsumerWorkflow::class);
        $this->app->singleton(TenantTaxonomyRecordAdoptionAdapter::class);
    }

    public function boot(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoptions): void
    {
        $resources->register(new TenantResourceDefinition('consumer.taxonomy-records', 'consumer-taxonomy-records', TenantTaxonomyRecord::class));
        $adoptions->register('consumer-taxonomy-records', TenantTaxonomyRecordAdoptionAdapter::class);
        if ($this->app->runningInConsole()) {
            $this->commands([TenancyConsumerSmokeCommand::class]);
        }
    }
}
