<?php

declare(strict_types=1);

namespace Nvl\Seo\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Seo\Console\ClearSeoSitemapCommand;
use Nvl\Seo\Console\PruneSeoRedirectsCommand;
use Nvl\Seo\Console\SeoDoctorCommand;
use Nvl\Seo\Console\WarmSeoSitemapCommand;
use Nvl\Seo\Contracts\SeoAuthorization;
use Nvl\Seo\Contracts\SeoImageResolver;
use Nvl\Seo\Contracts\SitemapArtifactStore;
use Nvl\Seo\Contracts\SitemapSource;
use Nvl\Seo\Contracts\StructuredDataProvider;
use Nvl\Seo\Http\Controllers\RobotsController;
use Nvl\Seo\Http\Controllers\SitemapChunkController;
use Nvl\Seo\Http\Controllers\SitemapController;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Services\ConfiguredSeoAuthorization;
use Nvl\Seo\Services\DirectSeoImageResolver;
use Nvl\Seo\Services\EloquentSeoSitemapSource;
use Nvl\Seo\Services\FilesystemSitemapArtifactStore;
use Nvl\Seo\Services\SitemapRegistry;
use Nvl\Seo\Services\StructuredDataRegistry;
use Nvl\Seo\Support\SeoRouteConfiguration;
use Nvl\Translatable\Services\TranslationResourceRegistry;

/**
 * Registers the localized SEO runtime and its optional public routes.
 */
final class SeoServiceProvider extends ServiceProvider
{
    /**
     * Register configuration and package boundary contracts.
     */
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../../config/seo.php', 'seo');

        $imageResolver = config('seo.image_resolver', DirectSeoImageResolver::class);

        if (
            ! is_string($imageResolver)
            || ! is_a($imageResolver, SeoImageResolver::class, true)
        ) {
            throw new InvalidArgumentException(
                'seo.image_resolver must implement SeoImageResolver.',
            );
        }

        $this->app->bind(SeoImageResolver::class, $imageResolver);
        $artifactStore = config(
            'seo.sitemap.artifact_store',
            FilesystemSitemapArtifactStore::class,
        );

        if (! is_string($artifactStore)
            || ! is_a($artifactStore, SitemapArtifactStore::class, true)) {
            throw new InvalidArgumentException(
                'seo.sitemap.artifact_store must implement SitemapArtifactStore.',
            );
        }

        $this->app->bind(SitemapArtifactStore::class, $artifactStore);
        $this->app->bindIf(SeoAuthorization::class, ConfiguredSeoAuthorization::class);
        $this->app->singleton(SitemapRegistry::class);
        $this->app->singleton(StructuredDataRegistry::class);
    }

    /**
     * Load package resources and register integrations.
     */
    public function boot(
        TypeScriptSourceRegistry $typeScriptSources,
        TranslationResourceRegistry $translationResources,
        SitemapRegistry $sitemaps,
        StructuredDataRegistry $structuredData,
    ): void {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/seo');

        if ((bool) config('seo.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        $translationResources->register(
            key: 'seo.profiles',
            modelClass: SeoProfile::class,
            label: 'SEO profiles',
            searchableColumns: ['seoable_type', 'seoable_id', 'scope'],
            displayColumns: ['seoable_type', 'seoable_id', 'scope', 'is_indexable'],
            orderColumn: 'updated_at',
        );
        $sitemaps->register($this->app->make(EloquentSeoSitemapSource::class));
        $this->registerConfiguredSitemapSources($sitemaps);
        $this->registerConfiguredStructuredDataProviders($structuredData);
        Blade::directive(
            'seo',
            static fn (string $expression): string => "<?php echo app(\\Nvl\\Seo\\Services\\SeoManager::class)->for({$expression})->toHtml(); ?>",
        );
        $this->registerRoutes();
        $this->registerManagementRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearSeoSitemapCommand::class,
                PruneSeoRedirectsCommand::class,
                SeoDoctorCommand::class,
                WarmSeoSitemapCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../../config/seo.php' => config_path('seo.php'),
        ], 'seo-config');
        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'seo-migrations');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'seo-skills');
    }

    /**
     * Resolve configured sitemap source classes through the container.
     */
    private function registerConfiguredSitemapSources(SitemapRegistry $sitemaps): void
    {
        $sources = config('seo.sitemap.sources', []);

        if (! is_array($sources)) {
            throw new InvalidArgumentException(
                'seo.sitemap.sources must be an array of SitemapSource classes.',
            );
        }

        foreach ($sources as $index => $source) {
            if (! is_string($source)
                || ! is_a($source, SitemapSource::class, true)) {
                throw new InvalidArgumentException(
                    "Configured sitemap source [{$index}] must implement SitemapSource.",
                );
            }

            $resolved = $this->app->make($source);

            if (! $resolved instanceof SitemapSource) {
                throw new InvalidArgumentException(
                    "Configured sitemap source [{$source}] must implement SitemapSource.",
                );
            }

            $sitemaps->register($resolved);
        }
    }

    /**
     * Resolve configured resource-aware structured-data providers.
     */
    private function registerConfiguredStructuredDataProviders(
        StructuredDataRegistry $structuredData,
    ): void {
        $providers = config('seo.structured_data.providers', []);

        if (! is_array($providers)) {
            throw new InvalidArgumentException(
                'seo.structured_data.providers must be an array.',
            );
        }

        foreach ($providers as $index => $configuration) {
            if (! is_array($configuration)) {
                throw new InvalidArgumentException(
                    "Structured-data provider configuration [{$index}] must be an array.",
                );
            }

            $resourceClass = $configuration['resource'] ?? null;
            $providerClass = $configuration['provider'] ?? null;
            $key = $configuration['key'] ?? $providerClass;
            $priority = $configuration['priority'] ?? 0;

            if (! is_string($resourceClass)
                || ! is_a($resourceClass, Model::class, true)
                || ! is_string($providerClass)
                || ! is_a($providerClass, StructuredDataProvider::class, true)
                || ! is_string($key)
                || ! is_int($priority)) {
                throw new InvalidArgumentException(
                    "Structured-data provider configuration [{$index}] is invalid.",
                );
            }

            $provider = $this->app->make($providerClass);

            if (! $provider instanceof StructuredDataProvider) {
                throw new InvalidArgumentException(
                    "Structured-data provider [{$providerClass}] could not be resolved.",
                );
            }

            /** @var class-string<Model> $resourceClass */
            $structuredData->register($key, $resourceClass, $provider, $priority);
        }
    }

    /**
     * Register opt-in sitemap and robots endpoints.
     */
    private function registerRoutes(): void
    {
        if (! (bool) config('seo.routes.enabled', false)) {
            return;
        }

        $middleware = config('seo.routes.middleware', ['web']);
        $middleware = is_array($middleware) ? $middleware : ['web'];

        Route::middleware($middleware)
            ->name(SeoRouteConfiguration::publicName())
            ->group(function (): void {
                Route::get(
                    SeoRouteConfiguration::sitemapPath(),
                    SitemapController::class,
                )->name('sitemap');
                Route::get(
                    SeoRouteConfiguration::sitemapChunkPath(),
                    SitemapChunkController::class,
                )->whereNumber('chunk')->name('sitemap.chunk');
                Route::get(
                    SeoRouteConfiguration::robotsPath(),
                    RobotsController::class,
                )->name('robots');
            });
    }

    /**
     * Register the disabled-by-default authorized management API.
     */
    private function registerManagementRoutes(): void
    {
        if (! (bool) config('seo.management.enabled', false)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../../routes/management.php');
    }
}
