<?php

declare(strict_types=1);

namespace Nvl\Suite\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Suite\Services\SuiteConfigurationRenderer;
use Nvl\Suite\Support\SuiteModuleCatalog;
use Throwable;

/**
 * Generates a dependency-complete suite configuration for a consumer profile.
 */
final class SuiteConfigureCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:suite:configure
        {--profile= : Start with a documented installation profile}
        {--add=* : Add a module and its transitive dependencies}
        {--path= : Destination inside the application; defaults to config/nvl-suite.php}
        {--write : Atomically replace the destination instead of previewing it}
        {--format=table : Output format: table or json}';

    /** @var string */
    protected $description = 'Preview or write a canonical dependency-complete NVL suite configuration';

    /**
     * Preview or write the selected suite configuration.
     */
    public function handle(
        SuiteConfigurationRenderer $renderer,
        SuiteModuleCatalog $catalog,
    ): int {
        $format = $this->option('format');
        $profile = $this->option('profile');
        $additions = $this->option('add');

        if (! in_array($format, ['table', 'json'], true)) {
            $this->components->error('The --format option must be table or json.');

            return self::INVALID;
        }

        if ($profile !== null && ! isset($catalog->profiles()[$profile])) {
            $this->components->error(sprintf(
                'The --profile option must be one of: %s.',
                implode(', ', array_keys($catalog->profiles())),
            ));

            return self::INVALID;
        }

        if (array_filter(
            $additions,
            static fn (mixed $module): bool => ! is_string($module),
        ) !== []) {
            $this->components->error('Every --add option must be a module name.');

            return self::INVALID;
        }

        try {
            /** @var list<string> $additions */
            $modules = $renderer->modules($profile, $additions);
            $pathOption = $this->option('path');
            $path = $renderer->resolvePath($pathOption);
            $contents = $renderer->render($modules);
            $write = (bool) $this->option('write');
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return self::INVALID;
        }

        if ($write) {
            try {
                $renderer->write($path, $contents);
            } catch (Throwable) {
                $this->components->error('The suite configuration could not be written.');

                return self::FAILURE;
            }
        }

        $report = [
            'profile' => $profile,
            'additions' => $additions,
            'path' => $renderer->relativePath($path),
            'write_requested' => $write,
            'written' => $write,
            'modules' => $modules,
        ];

        if ($format === 'json') {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->components->info($write
            ? sprintf('Wrote [%s].', $report['path'])
            : sprintf('Dry run for [%s]; pass --write to replace it.', $report['path']));
        $this->table(
            ['Module', 'Decision'],
            array_map(
                static fn (string $module, bool $enabled): array => [
                    $module,
                    $enabled ? 'enabled' : 'disabled',
                ],
                array_keys($modules),
                array_values($modules),
            ),
        );

        return self::SUCCESS;
    }
}
