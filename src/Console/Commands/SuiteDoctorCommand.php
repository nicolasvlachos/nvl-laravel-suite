<?php

declare(strict_types=1);

namespace Nvl\Suite\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Nvl\Suite\Services\SuiteConfigurationInspector;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Aggregates every enabled module Doctor and suite-level production checks.
 *
 * @phpstan-import-type SuiteConfigurationReport from SuiteConfigurationInspector
 */
final class SuiteDoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:suite:doctor
        {--strict : Treat package warnings as failures}
        {--production : Enforce production-only deployment requirements}
        {--format=text : Output format: text or json}';

    /** @var string */
    protected $description = 'Run readiness diagnostics for every enabled NVL module';

    /**
     * Execute every enabled package Doctor and suite-level readiness check.
     */
    public function handle(
        Application $application,
        SuiteConfigurationInspector $inspector,
        Repository $configurationRepository,
    ): int {
        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            $this->components->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $strict = (bool) $this->option('strict');
        $production = (bool) $this->option('production');
        $configuration = $inspector->inspect();
        $checks = [];
        $doctors = [];

        foreach ($configuration['modules'] as $module => $definition) {
            $checks[] = $this->check(
                key: "module.{$module}.explicit_decision",
                passed: $definition['explicit'],
                severity: 'warning',
                message: $definition['explicit']
                    ? 'The module has an explicit enabled or disabled decision.'
                    : 'The module is enabled by the 1.x compatibility default because its flag is omitted.',
            );

            if (! $definition['enabled']) {
                continue;
            }

            $checks[] = $this->check(
                key: "module.{$module}.provider",
                passed: $definition['provider_loaded'],
                severity: 'error',
                message: $definition['provider_loaded']
                    ? 'The effective module provider is loaded.'
                    : 'The effective module provider is not loaded.',
            );

            if ($definition['migration']['owner'] === 'invalid') {
                $checks[] = $this->check(
                    key: "module.{$module}.migration_ownership",
                    passed: false,
                    severity: 'error',
                    message: sprintf(
                        'Migration ownership flag [%s] must be a boolean.',
                        $definition['migration']['config'],
                    ),
                );
            }

            foreach ($definition['implementations'] as $contract => $implementation) {
                $resolved = ! str_starts_with($implementation, 'unresolvable:');
                $checks[] = $this->check(
                    key: sprintf(
                        'module.%s.contract.%s',
                        $module,
                        class_basename($contract),
                    ),
                    passed: $resolved,
                    severity: 'error',
                    message: $resolved
                        ? "Contract [{$contract}] resolves to [{$implementation}]."
                        : "Contract [{$contract}] cannot be resolved.",
                );
            }

            foreach ($definition['schedules'] as $schedule) {
                if (! $schedule['required']) {
                    continue;
                }

                $checks[] = $this->check(
                    key: "module.{$module}.schedule.{$schedule['command']}",
                    passed: $schedule['registered'],
                    severity: 'error',
                    message: $schedule['registered']
                        ? 'The required host-owned scheduler entry is registered.'
                        : sprintf(
                            'Feature [%s] is enabled but command [%s] is not registered in the host scheduler.',
                            $schedule['condition'],
                            $schedule['command'],
                        ),
                );
            }

            if (! is_string($definition['doctor'])) {
                continue;
            }

            $doctors[$module] = $this->runDoctor(
                command: $definition['doctor'],
                strict: $strict,
                production: $production,
            );
            $checks[] = $this->check(
                key: "module.{$module}.doctor",
                passed: $doctors[$module]['exit_code'] === self::SUCCESS,
                severity: 'error',
                message: $doctors[$module]['exit_code'] === self::SUCCESS
                    ? "Doctor [{$definition['doctor']}] passed."
                    : "Doctor [{$definition['doctor']}] failed.",
            );
        }

        if ($production) {
            $debug = $application->make('config')->get('app.debug');
            $key = $application->make('config')->get('app.key');
            $checks[] = $this->check(
                key: 'production.debug',
                passed: $debug === false,
                severity: 'error',
                message: $debug === false
                    ? 'Application debug mode is disabled.'
                    : 'Application debug mode must be disabled for production.',
            );
            $checks[] = $this->check(
                key: 'production.application_key',
                passed: is_string($key) && $key !== '',
                severity: 'error',
                message: is_string($key) && $key !== ''
                    ? 'Application encryption key is configured.'
                    : 'Application encryption key is missing.',
            );
        }

        $requireExplicitDecisions = $configurationRepository->get(
            'nvl-suite.adoption.require_explicit_module_decisions',
            false,
        ) === true;
        $healthy = collect($checks)->every(
            static fn (array $check): bool => $check['passed']
                || ($check['severity'] !== 'error'
                    && (! $strict
                        || ! $requireExplicitDecisions
                        || ! str_ends_with($check['key'], '.explicit_decision'))),
        );
        $report = [
            'suite' => 'nvl/laravel-suite',
            'healthy' => $healthy,
            'strict' => $strict,
            'production' => $production,
            'checks' => $checks,
            'doctors' => $doctors,
        ];

        if ($format === 'json') {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->table(
                ['Check', 'Severity', 'Result', 'Message'],
                array_map(static fn (array $check): array => [
                    $check['key'],
                    $check['severity'],
                    $check['passed'] ? 'PASS' : 'FAIL',
                    $check['message'],
                ], $checks),
            );
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{command: string, exit_code: int, healthy: bool, checks: list<array<mixed, mixed>>}
     */
    private function runDoctor(
        string $command,
        bool $strict,
        bool $production,
    ): array {
        $parameters = [
            '--strict' => $strict,
            '--format' => 'json',
        ];

        if ($production && $this->supportsOption($command, 'production')) {
            $parameters['--production'] = true;
        }

        try {
            $output = new BufferedOutput;
            $input = new ArrayInput(['command' => $command, ...$parameters]);
            $input->setInteractive(false);
            $exitCode = $this->getApplication()?->find($command)->run($input, $output)
                ?? self::FAILURE;
            $decoded = json_decode($output->fetch(), true);
            $checks = is_array($decoded) && isset($decoded['checks']) && is_array($decoded['checks'])
                ? array_values(array_filter($decoded['checks'], 'is_array'))
                : [];
        } catch (Throwable) {
            $exitCode = self::FAILURE;
            $checks = [];
        }

        return [
            'command' => $command,
            'exit_code' => $exitCode,
            'healthy' => $exitCode === self::SUCCESS,
            'checks' => $checks,
        ];
    }

    /**
     * Determine whether a registered package command accepts an option.
     */
    private function supportsOption(string $command, string $option): bool
    {
        try {
            return $this->getApplication()?->find($command)->getDefinition()->hasOption($option) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{key: string, passed: bool, severity: string, message: string}
     */
    private function check(
        string $key,
        bool $passed,
        string $severity,
        string $message,
    ): array {
        return compact('key', 'passed', 'severity', 'message');
    }
}
