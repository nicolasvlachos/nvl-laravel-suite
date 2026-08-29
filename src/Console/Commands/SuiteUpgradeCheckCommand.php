<?php

declare(strict_types=1);

namespace Nvl\Suite\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Nvl\Suite\Services\SuiteConfigurationRenderer;
use Nvl\Suite\Services\SuitePackageConfigurationInspector;
use Nvl\Suite\Services\SuiteUpgradeInspector;
use Nvl\Suite\Support\SuiteModuleCatalog;
use Throwable;

/**
 * Checks a published suite configuration for upgrade adoption work.
 *
 * @phpstan-import-type UpgradeFinding from SuiteUpgradeInspector
 */
final class SuiteUpgradeCheckCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:suite:upgrade:check
        {--path= : Published suite configuration; defaults to config/nvl-suite.php}
        {--module=* : Limit package configuration inspection to one or more suite modules}
        {--format=table : Output format: table or json}
        {--strict : Enable upgrade enforcement without promoting warnings to failures}';

    /** @var string */
    protected $description = 'Report module and operational reviews required before upgrading the NVL suite';

    /**
     * Inspect a published configuration without modifying it.
     */
    public function handle(
        SuiteConfigurationRenderer $renderer,
        SuiteUpgradeInspector $inspector,
        SuitePackageConfigurationInspector $packageConfiguration,
        SuiteModuleCatalog $catalog,
        Filesystem $filesystem,
    ): int {
        $format = $this->option('format');

        if (! in_array($format, ['table', 'json'], true)) {
            $this->components->error('The --format option must be table or json.');

            return self::INVALID;
        }

        $modules = $this->option('module');
        $modules = array_values(array_unique(array_filter($modules, 'is_string')));
        $unknownModules = array_values(array_diff($modules, array_keys($catalog->modules())));

        if ($unknownModules !== []) {
            $this->components->error(sprintf(
                'Unknown --module selection: %s.',
                implode(', ', $unknownModules),
            ));

            return self::INVALID;
        }

        try {
            $pathOption = $this->option('path');
            $path = $renderer->resolvePath($pathOption, mustExist: true);
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return self::INVALID;
        }

        try {
            $configuration = $filesystem->getRequire($path);

            if (! is_array($configuration)) {
                throw new \RuntimeException('The suite configuration file must return an array.');
            }

            $findings = [
                ...$inspector->inspect($configuration),
                ...$packageConfiguration->inspect($modules),
            ];
        } catch (Throwable) {
            $this->components->error('The suite configuration could not be loaded as an array.');

            return self::INVALID;
        }

        usort($findings, static function (array $left, array $right): int {
            $severity = ['error' => 0, 'warning' => 1];

            return [
                $left['module'] ?? '',
                $severity[$left['severity']],
                $left['path'] ?? $left['symbol'],
                $left['code'],
            ] <=> [
                $right['module'] ?? '',
                $severity[$right['severity']],
                $right['path'] ?? $right['symbol'],
                $right['code'],
            ];
        });

        $strict = (bool) $this->option('strict');
        $failed = $inspector->fails($findings, $strict);
        $report = [
            'healthy' => ! $failed,
            'strict' => $strict,
            'path' => $renderer->relativePath($path),
            'modules' => $modules,
            'findings' => $findings,
        ];

        if ($format === 'json') {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            if ($findings === []) {
                $this->components->info('The published suite configuration matches the current module catalog.');
            } else {
                $this->table(
                    ['Code', 'Severity', 'Module', 'Symbol', 'Message', 'Remediation'],
                    array_map(
                        static fn (array $finding): array => [
                            $finding['code'],
                            $finding['severity'],
                            $finding['module'] ?? 'N/A',
                            $finding['symbol'],
                            $finding['message'],
                            $finding['remediation'],
                        ],
                        $findings,
                    ),
                );
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
