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
        {--remove=* : Exclude a module when no retained root requires it}
        {--minimal : Render only profile, include, and exclude}
        {--full : Render every resolved module boolean}
        {--path= : Destination inside the application; defaults to config/nvl-suite.php}
        {--write : Atomically write the destination instead of previewing it}
        {--force : Allow --write to replace an existing file after showing its diff}
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
        $removals = $this->option('remove');
        $minimal = (bool) $this->option('minimal');
        $full = (bool) $this->option('full');
        $write = (bool) $this->option('write');
        $force = (bool) $this->option('force');

        if (! in_array($format, ['table', 'json'], true)) {
            $this->components->error('The --format option must be table or json.');

            return self::INVALID;
        }

        if ($minimal && $full) {
            $this->components->error('Choose either --minimal or --full, not both.');

            return self::INVALID;
        }

        if ($force && ! $write) {
            $this->components->error('The --force option may only be used with --write.');

            return self::INVALID;
        }

        if ($profile !== null && ! isset($catalog->profiles()[$profile])) {
            $this->components->error(sprintf(
                'The --profile option must be one of: %s.',
                implode(', ', array_keys($catalog->profiles())),
            ));

            return self::INVALID;
        }

        if ($this->invalidModules($additions)) {
            $this->components->error('Every --add option must be a non-empty module name.');

            return self::INVALID;
        }

        if ($this->invalidModules($removals)) {
            $this->components->error('Every --remove option must be a non-empty module name.');

            return self::INVALID;
        }

        try {
            /** @var list<string> $additions */
            /** @var list<string> $removals */
            $selection = $renderer->selection($profile, $additions, $removals);
            $modules = $selection->modules();
            $pathOption = $this->option('path');
            $path = $renderer->resolvePath($pathOption);
            $mode = $minimal ? 'minimal' : 'full';
            $contents = $minimal
                ? $renderer->renderMinimal($selection)
                : $renderer->render($modules);
            $diff = $renderer->diff($path, $contents);
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return self::INVALID;
        }

        if ($write && $diff !== null && ! $force) {
            $this->components->error('The suite configuration already exists; pass --force to replace it.');

            return self::INVALID;
        }

        $written = false;
        $backup = null;

        if ($write && $diff !== '') {
            try {
                $backupPath = $renderer->write($path, $contents, $force);
                $backup = is_string($backupPath)
                    ? $renderer->relativePath($backupPath)
                    : null;
                $written = true;
            } catch (Throwable) {
                $this->components->error('The suite configuration could not be written.');

                return self::FAILURE;
            }
        }

        $report = [
            'mode' => $mode,
            'profile' => $selection->profile,
            'additions' => $selection->include,
            'removals' => $selection->exclude,
            'path' => $renderer->relativePath($path),
            'write_requested' => $write,
            'written' => $written,
            'backup' => $backup,
            'modules' => $modules,
            'contents' => $contents,
            'diff' => $diff,
        ];

        if ($format === 'json') {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->components->info(match (true) {
            $written => sprintf('Wrote [%s].', $report['path']),
            $write => sprintf('No write needed; [%s] already matches.', $report['path']),
            default => sprintf('Dry run for [%s]; pass --write to create it.', $report['path']),
        });

        if (is_string($backup)) {
            $this->components->info(sprintf('Backed up the replaced configuration to [%s].', $backup));
        }

        if ($force && $diff !== null && $diff !== '') {
            $this->line($diff);
        }

        $this->line($contents);
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

    /**
     * Return whether a repeatable module option contains an invalid value.
     *
     * @param  array<mixed>  $modules
     */
    private function invalidModules(array $modules): bool
    {
        return array_filter(
            $modules,
            static fn (mixed $module): bool => ! is_string($module) || $module === '',
        ) !== [];
    }
}
