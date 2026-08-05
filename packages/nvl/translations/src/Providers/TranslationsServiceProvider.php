<?php

declare(strict_types=1);

namespace Nvl\Translations\Providers;

use Illuminate\Support\ServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Translations\Actions\Entries\UpdateTranslationEntryAction;
use Nvl\Translations\Actions\Sync\ImportTranslationsAction;
use Nvl\Translations\Actions\Sync\ScanTranslationsAction;
use Nvl\Translations\Console\Commands\TranslationsDoctorCommand;
use Nvl\Translations\Console\Commands\TranslationsExportCommand;
use Nvl\Translations\Console\Commands\TranslationsImportCommand;
use Nvl\Translations\Console\Commands\TranslationsPruneCommand;
use Nvl\Translations\Console\Commands\TranslationsScanCommand;
use Nvl\Translations\Console\Commands\TranslationsStatusCommand;
use Nvl\Translations\Console\Commands\TranslationsUnusedCommand;
use Nvl\Translations\Contracts\ImportTranslationsContract;
use Nvl\Translations\Contracts\ScanTranslationsContract;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Contracts\UpdateTranslationEntryContract;
use Nvl\Translations\Services\ConfiguredTranslationsAuthorization;

/**
 * Registers the translation workspace package and its optional management API.
 */
final class TranslationsServiceProvider extends ServiceProvider
{
    /**
     * Boot the application events.
     */
    public function boot(TypeScriptSourceRegistry $typeScriptSources): void
    {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/translations');

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
        }

        $this->registerTranslations();
        $this->registerConfig();
        $this->publishes([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'translations-migrations');
        if ((bool) config('translations.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }
        if ((bool) config('translations.routes.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        }

        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'translations-skills');
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../../config/translations.php', 'translations');

        $this->app->bind(
            UpdateTranslationEntryContract::class,
            UpdateTranslationEntryAction::class
        );
        $this->app->bind(
            ImportTranslationsContract::class,
            ImportTranslationsAction::class
        );
        $this->app->bind(
            ScanTranslationsContract::class,
            ScanTranslationsAction::class
        );
        $this->app->bindIf(
            TranslationsAuthorization::class,
            ConfiguredTranslationsAuthorization::class,
        );
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            TranslationsImportCommand::class,
            TranslationsExportCommand::class,
            TranslationsScanCommand::class,
            TranslationsUnusedCommand::class,
            TranslationsStatusCommand::class,
            TranslationsPruneCommand::class,
            TranslationsDoctorCommand::class,
        ]);
    }

    /**
     * Register translations.
     */
    protected function registerTranslations(): void
    {
        $langPath = __DIR__.'/../../lang';

        $this->loadTranslationsFrom($langPath, 'translations');
        $this->loadJsonTranslationsFrom($langPath);
        $this->publishes([
            $langPath => lang_path('vendor/translations'),
        ], 'translations-translations');
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([
            __DIR__.'/../../config/translations.php' => config_path('translations.php'),
        ], 'translations-config');
    }
}
