<?php

declare(strict_types=1);

namespace Nvl\Suite;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Nvl\Suite\Console\Commands\SuiteConfigurationCommand;
use Nvl\Suite\Console\Commands\SuiteDoctorCommand;
use Nvl\Suite\Services\SuiteConfigurationInspector;
use Nvl\Suite\Support\SuiteModuleCatalog;

/**
 * Registers selected suite modules in dependency-safe order.
 */
final class SuiteServiceProvider extends ServiceProvider
{
    /**
     * Register enabled modules and their required dependencies.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/nvl-suite.php', 'nvl-suite');
        $this->app->singleton(
            SuiteModuleCatalog::class,
            static fn (Application $app): SuiteModuleCatalog => new SuiteModuleCatalog(
                $app->make(Repository::class),
            ),
        );
        $this->app->singleton(SuiteConfigurationInspector::class);

        foreach ($this->app->make(SuiteModuleCatalog::class)->effectiveProviders() as $provider) {
            $this->app->register($provider);
        }
    }

    /**
     * Publish the canonical staged-adoption configuration.
     */
    public function boot(): void
    {
        $this->publishes([
            dirname(__DIR__).'/config/nvl-suite.php' => config_path('nvl-suite.php'),
        ], 'suite-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SuiteConfigurationCommand::class,
                SuiteDoctorCommand::class,
            ]);
        }
    }
}
