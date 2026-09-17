<?php

declare(strict_types=1);

namespace Nvl\Content\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Content\Providers\ContentServiceProvider;
use Nvl\Content\Tests\Fixtures\ContentTenancyFixtureServiceProvider;
use Nvl\Content\Tests\Fixtures\TenantContentOwner;
use Nvl\Content\Tests\Fixtures\TenantScenario;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;

/** Boots Content with its complete adopted dependency graph. */
abstract class TenancyTestCase extends Orchestra
{
    use DatabaseMigrations;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            DataServiceProvider::class,
            FilterableServiceProvider::class,
            SupportServiceProvider::class,
            TenancyServiceProvider::class,
            TranslatableServiceProvider::class,
            MediaServiceProvider::class,
            ContentTenancyFixtureServiceProvider::class,
            ContentServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'cache.default' => 'array',
            'content.authorization.callback' => static fn (): bool => true,
            'content.locales.available' => ['en'],
            'content.locales.required_on_publish' => ['en'],
            'content.scopes' => ['site' => ['key_pattern' => '/^[a-z0-9-]{1,50}$/']],
            'content.owners' => ['tenant-owner' => TenantContentOwner::class],
            'content.definitions.hero' => [
                'name' => 'Hero',
                'category' => 'marketing',
                'version' => 1,
                'allowed_scopes' => ['site'],
                'allowed_regions' => ['main'],
                'schema' => ['fields' => [[
                    'key' => 'title',
                    'type' => 'text',
                    'label' => 'Title',
                    'localized' => true,
                    'required' => true,
                ]]],
            ],
            'media.routes.api_enabled' => false,
            'media.routes.assets_enabled' => false,
            'tenancy.enabled' => true,
            'tenancy.profile' => 'application',
            'tenancy.resources' => [
                'media' => 'tenant',
                'content' => 'tenant',
                'content-test-owners' => 'tenant',
            ],
            'tenancy.sharing.media' => 'none',
        ]);
        TenantScenario::bind($app);
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $provider = new ReflectionClass(TenancyServiceProvider::class);
        $this->loadMigrationsFrom(dirname($provider->getFileName()).'/../../database/migrations/tenancy');
    }
}
