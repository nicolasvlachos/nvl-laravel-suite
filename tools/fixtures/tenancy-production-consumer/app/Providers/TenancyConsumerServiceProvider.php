<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\Authorization\ConsumerAuthAccess;
use App\Console\Commands\TenancyConsumerSmokeCommand;
use App\Consumers\AuthMediaConsumerWorkflow;
use App\Contracts\TenancyConsumerWorkflow;
use App\Models\TenantArticle;
use App\Tenancy\TenantArticleAdoptionAdapter;
use Illuminate\Support\ServiceProvider;
use Nvl\Auth\Contracts\AuthManagementAccess;
use Nvl\Auth\Contracts\SystemMutationAccess;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers the sealed consumer's explicit host and package extension boundaries. */
final class TenancyConsumerServiceProvider extends ServiceProvider
{
    /** Bind the full profile's explicit Auth extension boundaries. */
    public function register(): void
    {
        $this->app->singleton(ConsumerAuthAccess::class);
        $this->app->alias(ConsumerAuthAccess::class, AuthManagementAccess::class);
        $this->app->alias(ConsumerAuthAccess::class, SystemMutationAccess::class);
        $this->app->bind(TenancyConsumerWorkflow::class, AuthMediaConsumerWorkflow::class);

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
