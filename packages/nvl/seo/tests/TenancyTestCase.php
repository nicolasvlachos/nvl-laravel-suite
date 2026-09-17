<?php

declare(strict_types=1);

namespace Nvl\Seo\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Seo\Providers\SeoServiceProvider;
use Nvl\Seo\Tests\Fixtures\SeoTenancyFixtureServiceProvider;
use Nvl\Seo\Tests\Fixtures\TenantScenario;
use Nvl\Seo\Tests\Fixtures\TenantSeoOwner;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;

/** Boots SEO with real tenant/site identity and coordinator activation. */
abstract class TenancyTestCase extends Orchestra
{
    use DatabaseMigrations;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            SupportServiceProvider::class,
            DataServiceProvider::class,
            TenancyServiceProvider::class,
            TranslatableServiceProvider::class,
            SeoTenancyFixtureServiceProvider::class,
            SeoServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.url' => 'https://untrusted-global.test',
            'cache.default' => 'array',
            'filesystems.default' => 'local',
            'seo.site.base_url' => 'https://untrusted-global.test',
            'seo.owners' => ['tenant-owner' => TenantSeoOwner::class],
            'seo.sitemap.cache_seconds' => 0,
            'translatable.locales' => ['en', 'bg'],
            'translatable.fallback_locales' => ['en'],
            'tenancy.enabled' => true,
            'tenancy.profile' => 'application',
            'tenancy.resources' => ['seo' => 'tenant', 'seo-test-owners' => 'tenant'],
        ]);
        TenantScenario::bind($app);
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $provider = new ReflectionClass(TenancyServiceProvider::class);
        $this->loadMigrationsFrom(dirname($provider->getFileName()).'/../../database/migrations/tenancy');
    }
}
