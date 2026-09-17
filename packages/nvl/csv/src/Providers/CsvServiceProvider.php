<?php

declare(strict_types=1);

namespace Nvl\Csv\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Container\Container;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Csv\Services\CSVAsyncProcessor;
use Nvl\Csv\Services\CSVHandlerRegistry;
use Nvl\Csv\Services\CSVWorkStore;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Services\TenantQueueContext;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Csv\Tenancy\CsvAdoptionAdapter;

/**
 * Registers generated TypeScript discovery and publishable agent guidance.
 */
final class CsvServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(TenancyServiceProvider::class);
        $this->app->make(TenantAdoptionRegistry::class)->register('csv', CsvAdoptionAdapter::class);
        $this->app->singleton(CSVHandlerRegistry::class);
        $this->app->bind(CSVAsyncProcessor::class, fn (Container $app): CSVAsyncProcessor => new CSVAsyncProcessor(
            $app->make(TenantContext::class),
            $app->make(CSVWorkStore::class),
            $app->make(CSVHandlerRegistry::class),
            $app->make(TenantQueueContext::class),
        ));
    }

    /**
     * Register package TypeScript sources and publishing.
     */
    public function boot(TypeScriptSourceRegistry $typeScriptSources): void
    {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/csv');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'csv-skills');
    }
}
