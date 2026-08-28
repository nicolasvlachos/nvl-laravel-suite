<?php

declare(strict_types=1);

namespace Nvl\Suite\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Suite\Services\SuiteConfigurationInspector;
use Nvl\Suite\Support\SuiteModuleCatalog;
use Throwable;

/**
 * Displays the suite's effective, secret-free adoption configuration.
 *
 * @phpstan-import-type SuiteConfigurationReport from SuiteConfigurationInspector
 */
final class SuiteConfigurationCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:suite:configuration
        {--profile= : Compare effective modules with an installation profile}
        {--format=text : Output format: text or json}';

    /** @var string */
    protected $description = 'Explain enabled NVL modules, ownership, contracts, aliases, queues, and schedules';

    /**
     * Render the effective suite configuration without exposing configuration values.
     */
    public function handle(
        SuiteConfigurationInspector $inspector,
        SuiteModuleCatalog $catalog,
    ): int {
        $format = $this->option('format');
        $profile = $this->option('profile');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->components->error('The --format option must be text or json.');

            return self::INVALID;
        }

        if ($profile !== null && ! isset($catalog->profiles()[$profile])) {
            $this->components->error(sprintf(
                'The --profile option must be one of: %s.',
                implode(', ', array_keys($catalog->profiles())),
            ));

            return self::INVALID;
        }

        try {
            $report = $inspector->inspect($profile);
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return self::FAILURE;
        }

        if ($format === 'json') {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        if ($report['profile'] !== null) {
            $this->components->info(sprintf(
                'Profile [%s]: %s (%s)',
                $report['profile']['name'],
                $report['profile']['description'],
                $report['profile']['matches'] ? 'MATCH' : 'DIFFERS',
            ));
        }

        $this->table(
            ['Module', 'Selection', 'Provider', 'Migrations', 'Doctor', 'TypeScript'],
            array_map(
                static fn (string $module, array $definition): array => [
                    $module,
                    match (true) {
                        ! $definition['explicit'] => 'implicit (enabled)',
                        ! $definition['enabled'] => 'disabled',
                        $definition['dependency'] => 'dependency',
                        default => 'enabled',
                    },
                    $definition['provider_loaded'] ? 'loaded' : 'not loaded',
                    $definition['migration']['owner'],
                    $definition['doctor'] ?? 'N/A',
                    $definition['typescript'] ? 'yes' : 'no',
                ],
                array_keys($report['modules']),
                array_values($report['modules']),
            ),
        );

        $implementationRows = [];
        $aliasRows = [];
        $scheduleRows = [];

        foreach ($report['modules'] as $module => $definition) {
            foreach ($definition['implementations'] as $contract => $implementation) {
                $implementationRows[] = [$module, $contract, $implementation];
            }

            foreach ($definition['registered_aliases'] as $alias) {
                $aliasRows[] = [$module, $alias];
            }

            foreach ($definition['schedules'] as $schedule) {
                $scheduleRows[] = [
                    $module,
                    $schedule['command'],
                    $schedule['condition'] ?? 'optional',
                    $schedule['registered'] ? 'yes' : 'no',
                    $schedule['required'] ? 'required' : 'optional',
                ];
            }
        }

        if ($implementationRows !== []) {
            $this->newLine();
            $this->components->info('Resolved boundary implementations');
            $this->table(['Module', 'Contract', 'Implementation'], $implementationRows);
        }

        if ($aliasRows !== [] || $report['morph_aliases'] !== []) {
            $this->newLine();
            $this->components->info('Registered aliases');
            $this->table(
                ['Source', 'Alias'],
                [
                    ...$aliasRows,
                    ...array_map(
                        static fn (string $alias): array => ['eloquent-morph-map', $alias],
                        $report['morph_aliases'],
                    ),
                ],
            );
        }

        if ($scheduleRows !== []) {
            $this->newLine();
            $this->components->info('Scheduler entries');
            $this->table(
                ['Module', 'Command', 'Condition', 'Registered', 'Requirement'],
                $scheduleRows,
            );
        }

        return self::SUCCESS;
    }
}
