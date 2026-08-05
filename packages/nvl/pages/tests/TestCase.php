<?php

declare(strict_types=1);

namespace Nvl\Pages\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nvl\Content\Providers\ContentServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Pages\Providers\PagesServiceProvider;
use Nvl\Pages\Tests\Fixtures\TestPageResourceHandler;
use Nvl\Seo\Providers\SeoServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Pages with only its declared runtime dependency graph.
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
            SupportServiceProvider::class,
            DataServiceProvider::class,
            FilterableServiceProvider::class,
            TranslatableServiceProvider::class,
            MediaServiceProvider::class,
            ContentServiceProvider::class,
            MetafieldsServiceProvider::class,
            SeoServiceProvider::class,
            PagesServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.locale' => 'en',
            'app.fallback_locale' => 'en',
            'app.url' => 'https://pages.test',
            'cache.default' => 'array',
            'filesystems.default' => 'local',
            'media.disk' => 'local',
            'media.routes.assets_enabled' => false,
            'content.authorization.callback' => static fn (): bool => true,
            'translatable.locales' => ['en', 'bg'],
            'translatable.fallback_locales' => ['en'],
            'pages.urls.base_url' => 'https://pages.test',
            'pages.resources' => [
                'records.detail' => TestPageResourceHandler::class,
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('page_test_resources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });
    }
}
