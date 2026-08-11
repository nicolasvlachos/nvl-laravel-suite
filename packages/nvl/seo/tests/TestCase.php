<?php

declare(strict_types=1);

namespace Nvl\Seo\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Seo\Providers\SeoServiceProvider;
use Nvl\Seo\Tests\Fixtures\TestIntegerSeoOwner;
use Nvl\Seo\Tests\Fixtures\TestSeoOwner;
use Nvl\Seo\Tests\Fixtures\TestStructuredDataProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots SEO and its explicit runtime dependencies in isolation.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DataServiceProvider::class,
            TranslatableServiceProvider::class,
            SeoServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.url' => 'https://example.test',
            'app.locale' => 'en',
            'app.fallback_locale' => 'en',
            'translatable.locales' => ['en', 'bg'],
            'translatable.fallback_locales' => ['en'],
            'seo.site.base_url' => 'https://example.test',
            'seo.site.name' => 'Example',
            'seo.owners' => [
                'article' => TestSeoOwner::class,
                'integer-article' => TestIntegerSeoOwner::class,
            ],
            'seo.routes.enabled' => true,
            'seo.routes.middleware' => [],
            'seo.sitemap.cache_seconds' => 3600,
            'seo.structured_data.providers' => [
                [
                    'key' => 'test.configured-resource',
                    'resource' => TestSeoOwner::class,
                    'provider' => TestStructuredDataProvider::class,
                    'priority' => 10,
                ],
            ],
        ]);
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::create('seo_test_owners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('seo_test_integer_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
