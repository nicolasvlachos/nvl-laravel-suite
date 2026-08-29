<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\Activity\UserActivityMapping;
use App\Auth\Authorization\AuthConsumerAccess;
use App\Auth\Authorization\AuthConsumerMailReadAuthorization;
use App\Auth\Authorization\AuthConsumerSettingsAuthorization;
use App\Console\Commands\AuthConsumerSmokeCommand;
use Illuminate\Support\ServiceProvider;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Auth\Contracts\AuthManagementAccess;
use Nvl\Auth\Contracts\SystemMutationAccess;
use Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization;
use Nvl\Settings\Contracts\SettingsAuthorization;

/** Registers the proof consumer's explicit package extension boundaries. */
final class AuthConsumerServiceProvider extends ServiceProvider
{
    /** Register deny-by-default application authorization adapters. */
    public function register(): void
    {
        $this->app->singleton(AuthConsumerAccess::class);
        $this->app->alias(AuthConsumerAccess::class, AuthManagementAccess::class);
        $this->app->alias(AuthConsumerAccess::class, SystemMutationAccess::class);
        $this->app->singleton(
            SettingsAuthorization::class,
            AuthConsumerSettingsAuthorization::class,
        );
        $this->app->singleton(
            MailNotificationReadAuthorization::class,
            AuthConsumerMailReadAuthorization::class,
        );

        $this->app->afterResolving(
            MappingRegistry::class,
            static function (MappingRegistry $registry): void {
                $registry->register(new UserActivityMapping);
            },
        );

        if ($this->app->runningInConsole()) {
            $this->commands([AuthConsumerSmokeCommand::class]);
        }
    }
}
