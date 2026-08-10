<?php

declare(strict_types=1);

namespace Nvl\Pages\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentOwnerRegistrar;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Pages\Console\PagesDoctorCommand;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Contracts\PageRequestContextResolver;
use Nvl\Pages\Contracts\PageResourceHandler;
use Nvl\Pages\Contracts\PageUrlGenerator;
use Nvl\Pages\Events\PageChanged;
use Nvl\Pages\Listeners\InvalidatePageSitemap;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Seo\PageSitemapSource;
use Nvl\Pages\Services\ConfiguredPageAuthorization;
use Nvl\Pages\Services\ConfiguredPageRequestContextResolver;
use Nvl\Pages\Services\ConfiguredPageUrlGenerator;
use Nvl\Pages\Services\PageResourceRegistry;
use Nvl\Pages\Support\PagesConfiguration;
use Nvl\Seo\Services\SitemapRegistry;
use Nvl\Translatable\Services\TranslationResourceRegistry;

/**
 * Registers the standalone Pages runtime and its package integrations.
 */
final class PagesServiceProvider extends ServiceProvider
{
    /**
     * Register validated Pages contracts and singleton registries.
     */
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../../config/pages.php', 'pages');
        $this->registerOwnerConfigurations();
        $authorization = config(
            'pages.authorization.class',
            ConfiguredPageAuthorization::class,
        );
        $urlGenerator = config(
            'pages.urls.generator',
            ConfiguredPageUrlGenerator::class,
        );
        $contextResolver = config(
            'pages.public.context_resolver',
            ConfiguredPageRequestContextResolver::class,
        );

        if (! is_string($authorization)
            || ! is_a($authorization, PageAuthorization::class, true)) {
            throw new InvalidArgumentException(
                'pages.authorization.class must implement PageAuthorization.',
            );
        }

        if (! is_string($urlGenerator)
            || ! is_a($urlGenerator, PageUrlGenerator::class, true)) {
            throw new InvalidArgumentException(
                'pages.urls.generator must implement PageUrlGenerator.',
            );
        }

        if (! is_string($contextResolver)
            || ! is_a($contextResolver, PageRequestContextResolver::class, true)) {
            throw new InvalidArgumentException(
                'pages.public.context_resolver must implement PageRequestContextResolver.',
            );
        }

        $this->app->bindIf(PageAuthorization::class, $authorization);
        $this->app->bindIf(PageRequestContextResolver::class, $contextResolver);
        $this->app->bindIf(PageUrlGenerator::class, $urlGenerator);
        $this->app->singleton(PageResourceRegistry::class);
    }

    /**
     * Boot package integrations, resources, migrations, routes, and commands.
     */
    public function boot(
        TypeScriptSourceRegistry $typeScriptSources,
        TranslationResourceRegistry $translationResources,
        ContentOwnerRegistrar $contentOwners,
        SitemapRegistry $sitemaps,
        PageResourceRegistry $resources,
    ): void {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/pages');
        $this->registerResources($resources);
        $contentAlias = Page::CONTENT_OWNER_TYPE;

        $registeredContentOwner = $contentOwners->registered($contentAlias);

        if ($registeredContentOwner === null) {
            $contentOwners->register($contentAlias, Page::class);
        } elseif ($registeredContentOwner !== Page::class) {
            throw new InvalidArgumentException(
                "Content owner alias [{$contentAlias}] must resolve to Page.",
            );
        }

        $translationResources->register(
            key: 'pages.pages',
            modelClass: Page::class,
            label: 'Pages',
            searchableColumns: ['key', 'site', 'slug', 'path', 'status', 'resource'],
            displayColumns: ['key', 'site', 'path', 'kind', 'status', 'revision'],
            orderColumn: 'path',
        );
        $sitemaps->register($this->app->make(PageSitemapSource::class), 'nvl/pages');
        Event::listen(PageChanged::class, InvalidatePageSitemap::class);

        if ((bool) config('pages.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([PagesDoctorCommand::class]);
        }

        $this->publishes([
            __DIR__.'/../../config/pages.php' => config_path('pages.php'),
        ], 'pages-config');
        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'pages-migrations');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'pages-skills');
    }

    private function registerOwnerConfigurations(): void
    {
        $seoAlias = PagesConfiguration::alias('seo_owner_alias', 'page');
        $seoOwners = config('seo.owners', []);

        if (! is_array($seoOwners)) {
            throw new InvalidArgumentException('seo.owners must be an array.');
        }

        if (isset($seoOwners[$seoAlias]) && $seoOwners[$seoAlias] !== Page::class) {
            throw new InvalidArgumentException(
                "SEO owner alias [{$seoAlias}] is already assigned to another model.",
            );
        }

        $seoOwners[$seoAlias] = Page::class;
        config()->set('seo.owners', $seoOwners);
        $metafieldAlias = PagesConfiguration::alias('metafield_owner_alias', 'page');
        $metafieldOwners = config('metafields.owners', []);

        if (! is_array($metafieldOwners)) {
            throw new InvalidArgumentException('metafields.owners must be an array.');
        }

        if (isset($metafieldOwners[$metafieldAlias])) {
            $model = is_array($metafieldOwners[$metafieldAlias])
                ? ($metafieldOwners[$metafieldAlias]['model'] ?? null)
                : null;

            if ($model !== Page::class) {
                throw new InvalidArgumentException(
                    "Metafield owner alias [{$metafieldAlias}] is already assigned to another model.",
                );
            }
        } else {
            $sections = config('pages.integrations.metafield_sections', ['general']);
            $metafieldOwners[$metafieldAlias] = [
                'model' => Page::class,
                'label' => 'Pages',
                'sections' => is_array($sections) ? $sections : ['general'],
                'runtime_status' => 'live',
            ];
        }

        config()->set('metafields.owners', $metafieldOwners);
    }

    private function registerResources(PageResourceRegistry $registry): void
    {
        $configured = config('pages.resources', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('pages.resources must be an alias-to-handler map.');
        }

        foreach ($configured as $alias => $handler) {
            if (! is_string($alias)
                || ! is_string($handler)
                || ! is_a($handler, PageResourceHandler::class, true)) {
                throw new InvalidArgumentException(
                    'Every configured page resource handler is invalid.',
                );
            }

            $registry->register($alias, $handler);
        }
    }
}
