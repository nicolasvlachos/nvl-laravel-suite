<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\ContentConsumerSmokeCommand;
use App\Content\Authorization\ContentConsumerAccess;
use App\Content\Authorization\ContentConsumerContentAuthorization;
use App\Content\Authorization\ContentConsumerMediaAuthorization;
use App\Content\Authorization\ContentConsumerMetafieldAuthorization;
use App\Content\Authorization\ContentConsumerPageAuthorization;
use App\Content\Authorization\ContentConsumerSeoAuthorization;
use App\Content\Authorization\ContentConsumerTranslationsAuthorization;
use App\Content\Media\ContentConsumerMediaScanner;
use Illuminate\Support\ServiceProvider;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Schema\ContentDefinitionSource;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Contracts\MetafieldReferenceAuthorization;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Seo\Contracts\SeoAuthorization;
use Nvl\Translations\Contracts\TranslationsAuthorization;

/** Registers the proof consumer's application-owned extension boundaries. */
final class ContentConsumerServiceProvider extends ServiceProvider
{
    /** Register explicit typed authorization and scanner adapters. */
    public function register(): void
    {
        $this->app->singleton(ContentConsumerAccess::class);

        $this->app->singleton(ContentConsumerContentAuthorization::class);
        $this->app->alias(
            ContentConsumerContentAuthorization::class,
            ContentAuthorization::class,
        );

        $this->app->singleton(ContentConsumerMediaAuthorization::class);
        $this->app->alias(
            ContentConsumerMediaAuthorization::class,
            MediaAuthorization::class,
        );

        $this->app->singleton(ContentConsumerMetafieldAuthorization::class);
        $this->app->alias(
            ContentConsumerMetafieldAuthorization::class,
            MetafieldAuthorization::class,
        );
        $this->app->alias(
            ContentConsumerMetafieldAuthorization::class,
            MetafieldReferenceAuthorization::class,
        );

        $this->app->singleton(ContentConsumerPageAuthorization::class);
        $this->app->alias(
            ContentConsumerPageAuthorization::class,
            PageAuthorization::class,
        );

        $this->app->singleton(ContentConsumerSeoAuthorization::class);
        $this->app->alias(
            ContentConsumerSeoAuthorization::class,
            SeoAuthorization::class,
        );

        $this->app->singleton(ContentConsumerTranslationsAuthorization::class);
        $this->app->alias(
            ContentConsumerTranslationsAuthorization::class,
            TranslationsAuthorization::class,
        );

        $this->app->singleton(ContentConsumerMediaScanner::class);
        $this->app->alias(
            ContentConsumerMediaScanner::class,
            MediaContentScanner::class,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([ContentConsumerSmokeCommand::class]);
        }
    }

    /** Register the source-controlled block schema after package providers boot. */
    public function boot(ContentDefinitionRegistry $definitions): void
    {
        $definitions->register(new ContentDefinitionSource(
            key: 'consumer.section',
            name: 'Consumer section',
            description: 'Bilingual content used by the production proof.',
            category: 'website',
            version: 1,
            view: null,
            schema: [
                'fields' => [[
                    'key' => 'title',
                    'type' => 'text',
                    'label' => 'Title',
                    'localized' => true,
                    'required' => true,
                ]],
            ],
            allowedScopes: ['global'],
            allowedRegions: ['main', 'sidebar'],
        ));
    }
}
