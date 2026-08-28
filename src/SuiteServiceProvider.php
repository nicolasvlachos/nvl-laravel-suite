<?php

declare(strict_types=1);

namespace Nvl\Suite;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Nvl\Suite\Console\Commands\SuiteConfigurationCommand;
use Nvl\Suite\Console\Commands\SuiteConsumerAuditCommand;
use Nvl\Suite\Console\Commands\SuiteDoctorCommand;
use Nvl\Suite\Console\Commands\SuiteSkillsDoctorCommand;
use Nvl\Suite\Console\Commands\SuiteSkillsPublishCommand;
use Nvl\Suite\Services\ConsumerAudit\ComposerSourceRootLocator;
use Nvl\Suite\Services\ConsumerAudit\PhpConsumerBoundaryScanner;
use Nvl\Suite\Services\ConsumerAudit\SuiteRuntimeConsumerScanner;
use Nvl\Suite\Services\SuiteConfigurationInspector;
use Nvl\Suite\Services\SuiteConsumerAuditor;
use Nvl\Suite\Services\SuiteSkillManager;
use Nvl\Suite\Support\SuiteModuleCatalog;

/**
 * Registers selected suite modules in dependency-safe order.
 */
final class SuiteServiceProvider extends ServiceProvider
{
    /**
     * Register enabled modules and their required dependencies.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/nvl-suite.php', 'nvl-suite');
        $this->app->singleton(
            SuiteModuleCatalog::class,
            static fn (Application $app): SuiteModuleCatalog => new SuiteModuleCatalog(
                $app->make(Repository::class),
            ),
        );
        $this->app->singleton(SuiteConfigurationInspector::class);
        $this->app->singleton(ComposerSourceRootLocator::class);
        $this->app->singleton(PhpConsumerBoundaryScanner::class);
        $this->app->singleton(SuiteRuntimeConsumerScanner::class);
        $this->app->singleton(SuiteConsumerAuditor::class);
        $this->app->singleton(
            SuiteSkillManager::class,
            static fn (Application $app): SuiteSkillManager => new SuiteSkillManager(
                filesystem: $app->make(Filesystem::class),
                catalog: $app->make(SuiteModuleCatalog::class),
                suiteRoot: dirname(__DIR__),
                applicationRoot: $app->basePath(),
                suiteVersion: self::installedSuiteVersion(),
            ),
        );

        foreach ($this->app->make(SuiteModuleCatalog::class)->effectiveProviders() as $provider) {
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

        $skillPublications = [];

        foreach ($this->app->make(SuiteModuleCatalog::class)->effectiveModules() as $module) {
            $skill = 'nvl-'.$module;
            $skillPublications[dirname(__DIR__).'/resources/boost/skills/'.$skill]
                = base_path('.agents/skills/'.$skill);
        }

        $this->publishes($skillPublications, 'suite-skills');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SuiteConfigurationCommand::class,
                SuiteConsumerAuditCommand::class,
                SuiteDoctorCommand::class,
                SuiteSkillsDoctorCommand::class,
                SuiteSkillsPublishCommand::class,
            ]);
        }
    }

    /**
     * Resolve the installed Composer version recorded in published manifests.
     */
    private static function installedSuiteVersion(): string
    {
        $rootPackage = InstalledVersions::getRootPackage();

        if ($rootPackage['name'] === SuiteSkillManager::OWNER) {
            return $rootPackage['pretty_version'];
        }

        if (InstalledVersions::isInstalled(SuiteSkillManager::OWNER)) {
            return InstalledVersions::getPrettyVersion(SuiteSkillManager::OWNER)
                ?? InstalledVersions::getVersion(SuiteSkillManager::OWNER)
                ?? 'unknown';
        }

        return 'unknown';
    }
}
