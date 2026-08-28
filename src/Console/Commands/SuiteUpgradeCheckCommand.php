<?php

declare(strict_types=1);

namespace Nvl\Suite\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Nvl\Suite\Services\SuiteConfigurationRenderer;
use Nvl\Suite\Services\SuiteUpgradeInspector;
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
        {--format=table : Output format: table or json}
        {--strict : Fail when any operational review warning is present}';

    /** @var string */
    protected $description = 'Report module and operational reviews required before upgrading the NVL suite';

    /**
     * Inspect a published configuration without modifying it.
     */
    public function handle(
        SuiteConfigurationRenderer $renderer,
        SuiteUpgradeInspector $inspector,
        Filesystem $filesystem,
    ): int {
        $format = $this->option('format');

        if (! in_array($format, ['table', 'json'], true)) {
            $this->components->error('The --format option must be table or json.');

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

            $findings = $inspector->inspect($configuration);
        } catch (Throwable) {
            $this->components->error('The suite configuration could not be loaded as an array.');

            return self::INVALID;
        }

        $strict = (bool) $this->option('strict');
        $failed = $inspector->fails($findings, $strict);
        $report = [
            'healthy' => ! $failed,
            'strict' => $strict,
            'path' => $renderer->relativePath($path),
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
