<?php

declare(strict_types=1);

namespace Nvl\Suite;

use Illuminate\Support\ServiceProvider;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Auth\Providers\AuthServiceProvider;
use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\Content\Providers\ContentServiceProvider;
use Nvl\Csv\Providers\CsvServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Forms\Providers\FormsServiceProvider;
use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Pages\Providers\PagesServiceProvider;
use Nvl\Primitives\Providers\PrimitivesServiceProvider;
use Nvl\Seo\Providers\SeoServiceProvider;
use Nvl\Settings\Providers\SettingsServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Taxonomy\Providers\TaxonomyServiceProvider;
use Nvl\Templates\Providers\TemplatesServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Nvl\Translations\Providers\TranslationsServiceProvider;

final class SuiteServiceProvider extends ServiceProvider
{
    /**
     * @var list<class-string<ServiceProvider>>
     */
    private const array PROVIDERS = [
        SupportServiceProvider::class,
        DataServiceProvider::class,
        FilterableServiceProvider::class,
        TranslatableServiceProvider::class,
        ActivityServiceProvider::class,
        AuthServiceProvider::class,
        CommentsServiceProvider::class,
        ContentServiceProvider::class,
        CsvServiceProvider::class,
        FormsServiceProvider::class,
        MailNotificationsServiceProvider::class,
        MediaServiceProvider::class,
        MetafieldsServiceProvider::class,
        PagesServiceProvider::class,
        PrimitivesServiceProvider::class,
        SeoServiceProvider::class,
        SettingsServiceProvider::class,
        TaxonomyServiceProvider::class,
        TemplatesServiceProvider::class,
        TranslationsServiceProvider::class,
    ];

    public function register(): void
    {
        foreach (self::PROVIDERS as $provider) {
            $this->app->register($provider);
        }
    }
}
