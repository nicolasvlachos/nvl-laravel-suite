<?php

declare(strict_types=1);

namespace Nvl\Content\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nvl\Content\Providers\ContentServiceProvider;
use Nvl\Content\Tests\Fixtures\HeroV1ToV2ContentMigration;
use Nvl\Content\Tests\Fixtures\TestContentOwner;
use Nvl\Content\Tests\Fixtures\TestReferenceResolver;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Content with only its declared NVL foundation dependencies.
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
            FilterableServiceProvider::class,
            SupportServiceProvider::class,
            TranslatableServiceProvider::class,
            MediaServiceProvider::class,
            ContentServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'cache.default' => 'array',
            'filesystems.default' => 'public',
            'media.disk' => 'public',
            'media.routes.api_enabled' => false,
            'media.routes.assets_enabled' => false,
            'content.authorization.callback' => static fn (): bool => true,
            'content.definition_migrations' => [
                HeroV1ToV2ContentMigration::class,
            ],
            'content.locales.available' => ['en', 'bg'],
            'content.locales.required_on_publish' => ['en'],
            'content.scopes' => [
                'site' => ['key_pattern' => '/^[a-z0-9-]{1,50}$/'],
            ],
            'content.owners' => ['page' => TestContentOwner::class],
            'content.references' => ['article' => TestReferenceResolver::class],
            'content.definitions' => [
                'hero' => [
                    'name' => 'Hero',
                    'category' => 'marketing',
                    'version' => 2,
                    'allowed_scopes' => ['site'],
                    'allowed_regions' => ['main', 'sidebar'],
                    'schema' => [
                        'fields' => [
                            [
                                'key' => 'title',
                                'type' => 'text',
                                'label' => 'Title',
                                'localized' => true,
                                'required' => true,
                                'settings' => ['max_length' => 120],
                            ],
                            [
                                'key' => 'body',
                                'type' => 'rich_text',
                                'label' => 'Body',
                                'localized' => true,
                            ],
                            [
                                'key' => 'enabled',
                                'type' => 'boolean',
                                'label' => 'Enabled',
                                'default' => true,
                            ],
                            [
                                'key' => 'image',
                                'type' => 'media',
                                'label' => 'Image',
                                'settings' => ['mime_types' => ['image/jpeg']],
                            ],
                            [
                                'key' => 'links',
                                'type' => 'repeater',
                                'label' => 'Links',
                                'settings' => ['max_items' => 3],
                                'fields' => [
                                    ['key' => 'label', 'type' => 'text', 'label' => 'Label'],
                                    ['key' => 'url', 'type' => 'url', 'label' => 'URL'],
                                ],
                            ],
                            [
                                'key' => 'layout',
                                'type' => 'json',
                                'label' => 'Layout',
                                'settings' => [
                                    'schema' => [
                                        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                                        'type' => 'object',
                                        'properties' => [
                                            'columns' => ['type' => 'integer', 'minimum' => 1],
                                        ],
                                        'required' => ['columns'],
                                        'additionalProperties' => false,
                                    ],
                                ],
                            ],
                            [
                                'key' => 'metrics',
                                'type' => 'table',
                                'label' => 'Metrics',
                                'fields' => [
                                    ['key' => 'label', 'type' => 'text', 'label' => 'Label'],
                                    ['key' => 'value', 'type' => 'number', 'label' => 'Value'],
                                ],
                            ],
                            [
                                'key' => 'article',
                                'type' => 'reference',
                                'label' => 'Article',
                                'settings' => ['reference_type' => 'article'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('content_test_owners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('content_integer_test_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
