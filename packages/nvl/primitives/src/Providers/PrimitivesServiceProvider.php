<?php

declare(strict_types=1);

namespace Nvl\Primitives\Providers;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Primitives\Contracts\ExchangeRateProvider;
use Nvl\Primitives\Services\ConfiguredExchangeRateProvider;
use Nvl\Support\Traits\MergesPackageConfiguration;

/**
 * Registers primitive configuration, contracts, type sources, and package resources.
 */
final class PrimitivesServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;

    /**
     * Register package configuration and default boundary implementations.
     */
    public function register(): void
    {
        $this->mergePackageConfiguration(
            __DIR__.'/../../config/primitives.php',
            'primitives',
        );

        $implementation = config(
            'primitives.exchange_rates.implementation',
            ConfiguredExchangeRateProvider::class,
        );

        if (
            ! is_string($implementation)
            || ! is_a($implementation, ExchangeRateProvider::class, true)
        ) {
            throw new InvalidArgumentException(
                'primitives.exchange_rates.implementation must implement ExchangeRateProvider.',
            );
        }

        $this->app->bind(ExchangeRateProvider::class, $implementation);
    }

    /**
     * Publish configuration and agent guidance, and register generated types.
     */
    public function boot(TypeScriptSourceRegistry $typeScriptSources): void
    {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/primitives');
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'primitives');

        $this->publishes([
            __DIR__.'/../../config/primitives.php' => config_path('primitives.php'),
        ], 'primitives-config');

        $this->publishes([
            __DIR__.'/../../lang' => lang_path('vendor/primitives'),
        ], 'primitives-translations');

        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'primitives-skills');
    }
}
