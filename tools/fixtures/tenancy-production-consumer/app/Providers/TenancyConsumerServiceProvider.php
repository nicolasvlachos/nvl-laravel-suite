<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\Authorization\ConsumerAuthAccess;
use App\Console\Commands\TenancyConsumerConfigurationCommand;
use App\Console\Commands\TenancyConsumerLifecycleCommand;
use App\Console\Commands\TenancyConsumerQueryPlanCommand;
use App\Console\Commands\TenancyConsumerRaceCommand;
use App\Console\Commands\TenancyConsumerSmokeCommand;
use App\Consumers\AuthMediaConsumerWorkflow;
use App\Contracts\TenancyConsumerWorkflow;
use App\Mail\ConsumerScheduledMessageFactory;
use App\Models\TenantArticle;
use App\Tenancy\TenantArticleAdoptionAdapter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Nvl\Auth\Contracts\AuthManagementAccess;
use Nvl\Auth\Contracts\SystemMutationAccess;
use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Services\NullMediaContentScanner;
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
        $this->app->bind(MediaContentScanner::class, NullMediaContentScanner::class);

        $this->app->singleton(TenantArticleAdoptionAdapter::class);
        $this->app->singleton(ConsumerScheduledMessageFactory::class);
        $this->app->tag(ConsumerScheduledMessageFactory::class, ScheduledMessageFactory::TAG);
    }

    /** Register the fixture ownership graph and CLI after package providers boot. */
    public function boot(
        TenantResourceRegistry $resources,
        TenantAdoptionRegistry $adoptions,
    ): void {
        Gate::define('tenancy-consumer.metafields.manage', static fn (?Authenticatable $user = null): bool => true);
        Gate::define('tenancy-consumer.metafields.reference', static fn (?Authenticatable $user = null): bool => true);
        $resources->register(new TenantResourceDefinition(
            key: 'consumer.articles',
            family: 'consumer-articles',
            model: TenantArticle::class,
        ));
        $adoptions->register('consumer-articles', TenantArticleAdoptionAdapter::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                TenancyConsumerSmokeCommand::class,
                TenancyConsumerLifecycleCommand::class,
                TenancyConsumerConfigurationCommand::class,
                TenancyConsumerRaceCommand::class,
                TenancyConsumerQueryPlanCommand::class,
            ]);
        }
    }
}
