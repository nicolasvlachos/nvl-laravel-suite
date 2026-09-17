<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\TenancyConsumerSmokeCommand;
use App\Consumers\MediaOnlyConsumerWorkflow;
use App\Contracts\TenancyConsumerWorkflow;
use App\Models\TenantArticle;
use App\Tenancy\HostMembershipAccess;
use App\Tenancy\TenantArticleAdoptionAdapter;
use Illuminate\Support\ServiceProvider;
use Nvl\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers the standalone Media profile without importing NVL Auth. */
final class MediaOnlyTenancyConsumerServiceProvider extends ServiceProvider
{
    /** Bind only fixture-owned host adapters and the standalone workflow. */
    public function register(): void
    {
        $this->app->singleton(HostMembershipAccess::class);
        $this->app->alias(HostMembershipAccess::class, TenantMembershipAccess::class);
        $this->app->bind(TenancyConsumerWorkflow::class, MediaOnlyConsumerWorkflow::class);
        $this->app->singleton(TenantArticleAdoptionAdapter::class);
    }

    /** Register the fixture ownership graph and CLI after package providers boot. */
    public function boot(
        TenantResourceRegistry $resources,
        TenantAdoptionRegistry $adoptions,
    ): void {
        $resources->register(new TenantResourceDefinition(
            key: 'consumer.articles',
            family: 'consumer-articles',
            model: TenantArticle::class,
        ));
        $adoptions->register('consumer-articles', TenantArticleAdoptionAdapter::class);

        if ($this->app->runningInConsole()) {
            $this->commands([TenancyConsumerSmokeCommand::class]);
        }
    }
}
