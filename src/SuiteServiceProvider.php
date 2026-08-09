<?php

declare(strict_types=1);

namespace Nvl\Suite;

use Illuminate\Contracts\Config\Repository;
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
use RuntimeException;

/**
 * Registers selected suite modules in dependency-safe order.
 */
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
        CsvServiceProvider::class,
        MailNotificationsServiceProvider::class,
        MediaServiceProvider::class,
        CommentsServiceProvider::class,
        ContentServiceProvider::class,
        MetafieldsServiceProvider::class,
        PrimitivesServiceProvider::class,
        SeoServiceProvider::class,
        SettingsServiceProvider::class,
        TaxonomyServiceProvider::class,
        TemplatesServiceProvider::class,
        TranslationsServiceProvider::class,
        FormsServiceProvider::class,
        PagesServiceProvider::class,
    ];

    /**
     * @var array<string, class-string<ServiceProvider>>
     */
    private const array MODULE_PROVIDERS = [
        'support' => SupportServiceProvider::class,
        'data' => DataServiceProvider::class,
        'filterable' => FilterableServiceProvider::class,
        'translatable' => TranslatableServiceProvider::class,
        'activity' => ActivityServiceProvider::class,
        'auth' => AuthServiceProvider::class,
        'csv' => CsvServiceProvider::class,
        'mail-notifications' => MailNotificationsServiceProvider::class,
        'media' => MediaServiceProvider::class,
        'comments' => CommentsServiceProvider::class,
        'content' => ContentServiceProvider::class,
        'metafields' => MetafieldsServiceProvider::class,
        'primitives' => PrimitivesServiceProvider::class,
        'seo' => SeoServiceProvider::class,
        'settings' => SettingsServiceProvider::class,
        'taxonomy' => TaxonomyServiceProvider::class,
        'templates' => TemplatesServiceProvider::class,
        'translations' => TranslationsServiceProvider::class,
        'forms' => FormsServiceProvider::class,
        'pages' => PagesServiceProvider::class,
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array DEPENDENCIES = [
        'support' => [],
        'data' => [],
        'filterable' => ['data'],
        'translatable' => ['data'],
        'activity' => ['data', 'support'],
        'auth' => ['data'],
        'csv' => ['data'],
        'mail-notifications' => [],
        'media' => ['data', 'filterable', 'support', 'translatable'],
        'comments' => ['data', 'filterable', 'media'],
        'content' => ['data', 'filterable', 'media', 'support', 'translatable'],
        'metafields' => ['data', 'support', 'translatable'],
        'primitives' => ['data'],
        'seo' => ['data', 'translatable'],
        'settings' => ['data'],
        'taxonomy' => ['data', 'translatable'],
        'templates' => ['content', 'data', 'filterable', 'media', 'translatable'],
        'translations' => ['data', 'filterable', 'support'],
        'forms' => ['data', 'filterable', 'support', 'translatable'],
        'pages' => ['content', 'data', 'filterable', 'metafields', 'seo', 'translatable'],
    ];

    /**
     * Register enabled modules and their required dependencies.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/nvl-suite.php', 'nvl-suite');

        foreach ($this->selectedProviders($this->app->make(Repository::class)) as $provider) {
            $this->app->register($provider);
        }
    }

    /**
     * Publish the canonical staged-adoption configuration.
     */
    public function boot(): void
    {
        $this->publishes([
            dirname(__DIR__).'/config/nvl-suite.php' => config_path('nvl-suite.php'),
        ], 'suite-config');
    }

    /**
     * Resolve selected providers in the suite's canonical dependency order.
     *
     * @return list<class-string<ServiceProvider>>
     */
    private function selectedProviders(Repository $configuration): array
    {
        $configured = $configuration->get('nvl-suite.modules', []);

        if (! is_array($configured)) {
            throw new RuntimeException('nvl-suite.modules must be an array of module boolean flags.');
        }

        $unknownModules = array_diff(array_keys($configured), array_keys(self::MODULE_PROVIDERS));

        if ($unknownModules !== []) {
            throw new RuntimeException(sprintf(
                'Unknown suite module configuration: %s.',
                implode(', ', $unknownModules),
            ));
        }

        $selected = [];

        foreach (self::MODULE_PROVIDERS as $module => $provider) {
            $enabled = array_key_exists($module, $configured) ? $configured[$module] : true;

            if (! is_bool($enabled)) {
                throw new RuntimeException("Suite module [{$module}] must be configured with a boolean flag.");
            }

            if ($enabled) {
                $this->selectModule($module, $selected);
            }
        }

        return array_values(array_filter(
            self::PROVIDERS,
            static fn (string $provider): bool => isset($selected[$provider]),
        ));
    }

    /**
     * Select one module and its transitive dependencies.
     *
     * @param  array<class-string<ServiceProvider>, true>  $selected
     */
    private function selectModule(string $module, array &$selected): void
    {
        foreach (self::DEPENDENCIES[$module] as $dependency) {
            $this->selectModule($dependency, $selected);
        }

        $selected[self::MODULE_PROVIDERS[$module]] = true;
    }
}
