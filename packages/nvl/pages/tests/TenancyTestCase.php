<?php

declare(strict_types=1);

namespace Nvl\Pages\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Content\Providers\ContentServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Pages\Providers\PagesServiceProvider;
use Nvl\Pages\Tests\Fixtures\PagesTenancyFixtureServiceProvider;
use Nvl\Pages\Tests\Fixtures\TenantPageResourceHandler;
use Nvl\Pages\Tests\Fixtures\TenantScenario;
use Nvl\Seo\Providers\SeoServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;

/** Boots the adopted Pages publication dependency graph. */
abstract class TenancyTestCase extends Orchestra
{
    use DatabaseMigrations;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            SupportServiceProvider::class,
            DataServiceProvider::class,
            FilterableServiceProvider::class,
            TenancyServiceProvider::class,
            TranslatableServiceProvider::class,
            MediaServiceProvider::class,
            PagesTenancyFixtureServiceProvider::class,
            ContentServiceProvider::class,
            MetafieldsServiceProvider::class,
            SeoServiceProvider::class,
            PagesServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'app.url' => 'https://untrusted-global.test',
            'cache.default' => 'array',
            'filesystems.default' => 'local',
            'media.disk' => 'local',
            'media.routes.assets_enabled' => false,
            'content.authorization.callback' => static fn (): bool => true,
            'content.locales.available' => ['en', 'bg'],
            'content.locales.required_on_publish' => ['en'],
            'content.scopes' => ['site' => ['key_pattern' => '/^[a-z0-9-]{1,50}$/']],
            'content.definitions.hero' => [
                'name' => 'Hero',
                'category' => 'marketing',
                'version' => 1,
                'allowed_scopes' => ['site'],
                'allowed_regions' => ['main'],
                'schema' => ['fields' => [
                    ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'localized' => true, 'required' => true],
                    ['key' => 'image', 'type' => 'media', 'label' => 'Image'],
                ]],
            ],
            'pages.authorization.callback' => static fn (): bool => true,
            'pages.resources' => ['tenant-records.detail' => TenantPageResourceHandler::class],
            'pages.routes.public.enabled' => true,
            'pages.routes.public.middleware' => ['web'],
            'pages.public.default_site' => 'global-default',
            'pages.urls.base_url' => 'https://untrusted-global.test',
            'seo.routes.enabled' => true,
            'seo.routes.middleware' => ['web'],
            'seo.site.base_url' => 'https://untrusted-global.test',
            'translatable.locales' => ['en', 'bg'],
            'translatable.fallback_locales' => ['en'],
            'tenancy.enabled' => true,
            'tenancy.profile' => 'application',
            'tenancy.resources' => [
                'media' => 'tenant',
                'content' => 'tenant',
                'metafields' => 'tenant',
                'seo' => 'tenant',
                'pages' => 'tenant',
                'page-test-resources' => 'tenant',
            ],
            'tenancy.sharing' => ['media' => 'none', 'metafields' => 'none', 'templates' => 'none'],
        ]);
        TenantScenario::bind($app);
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $provider = new ReflectionClass(TenancyServiceProvider::class);
        $this->loadMigrationsFrom(dirname($provider->getFileName()).'/../../database/migrations/tenancy');
    }
}
