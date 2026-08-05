<?php

declare(strict_types=1);

namespace Nvl\Filterable\Providers;

use Illuminate\Support\ServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Filterable\Http\QueryFilterSetFactory;
use Nvl\Filterable\Services\EloquentFilterApplier;
use Nvl\Filterable\Services\FilterCriterionNormalizer;

/**
 * Registers generated TypeScript discovery and publishable agent guidance.
 */
final class FilterableServiceProvider extends ServiceProvider
{
    /**
     * Register stateless filter services.
     */
    public function register(): void
    {
        $this->app->singleton(FilterCriterionNormalizer::class);
        $this->app->singleton(EloquentFilterApplier::class);
        $this->app->singleton(QueryFilterSetFactory::class);
    }

    /**
     * Register package TypeScript sources and publishing.
     */
    public function boot(TypeScriptSourceRegistry $typeScriptSources): void
    {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/filterable');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'filterable-skills');
    }
}
