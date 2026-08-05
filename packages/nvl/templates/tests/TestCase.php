<?php

declare(strict_types=1);

namespace Nvl\Templates\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nvl\Content\Providers\ContentServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Templates\Providers\TemplatesServiceProvider;
use Nvl\Templates\Rendering\BladeTemplateRenderer;
use Nvl\Templates\Rendering\MpdfTemplateRenderer;
use Nvl\Templates\Tests\Fixtures\TestTemplateOwnerResolver;
use Nvl\Templates\Tests\Fixtures\TestTemplateRenderer;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Templates and only its declared runtime dependencies.
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
            TranslatableServiceProvider::class,
            MediaServiceProvider::class,
            ContentServiceProvider::class,
            TemplatesServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'cache.default' => 'array',
            'filesystems.default' => 'public',
            'media.disk' => 'public',
            'media.routes.assets_enabled' => false,
            'content.authorization.callback' => static fn (): bool => true,
            'content.locales.available' => ['en', 'bg'],
            'content.locales.required_on_publish' => ['en'],
            'content.definitions' => [
                'template-copy' => [
                    'name' => 'Template copy',
                    'category' => 'templates',
                    'version' => 1,
                    'allowed_regions' => ['main', 'header', 'footer'],
                    'schema' => [
                        'fields' => [
                            [
                                'key' => 'text',
                                'type' => 'text',
                                'label' => 'Text',
                                'localized' => true,
                            ],
                            [
                                'key' => 'heading',
                                'type' => 'text',
                                'label' => 'Heading',
                                'localized' => true,
                            ],
                            [
                                'key' => 'subject',
                                'type' => 'text',
                                'label' => 'Subject',
                                'localized' => true,
                            ],
                            [
                                'key' => 'logo',
                                'type' => 'media',
                                'label' => 'Logo',
                                'settings' => ['mime_types' => ['image/png']],
                            ],
                        ],
                    ],
                ],
            ],
            'templates.renderers' => [
                'blade' => BladeTemplateRenderer::class,
                'test' => TestTemplateRenderer::class,
                'pdf' => MpdfTemplateRenderer::class,
            ],
            'templates.definitions' => [
                'welcome' => [
                    'renderer' => 'test',
                    'view' => 'template-tests::core',
                    'profiles' => ['default'],
                    'schema' => ['name' => ['type' => 'string']],
                    'subject_path' => 'body.subject',
                    'required_regions' => ['main'],
                    'allowed_content_definitions' => ['template-copy'],
                ],
                'pdf-report' => [
                    'renderer' => 'pdf',
                    'view' => 'template-tests::pdf',
                    'profiles' => ['default'],
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'minLength' => 1,
                                'maxLength' => 100,
                            ],
                        ],
                        'required' => ['name'],
                        'additionalProperties' => false,
                    ],
                    'renderer_options' => [
                        'page_size' => 'A4',
                        'orientation' => 'portrait',
                        'filename' => 'report.pdf',
                    ],
                    'subject_path' => 'body.subject',
                    'required_regions' => ['main'],
                    'allowed_content_definitions' => ['template-copy'],
                ],
            ],
            'templates.owners' => ['member' => TestTemplateOwnerResolver::class],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['view']->addNamespace(
            'template-tests',
            __DIR__.'/Fixtures/views',
        );
        Schema::create('template_test_owners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
    }
}
