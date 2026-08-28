<?php

declare(strict_types=1);

namespace Nvl\Suite\Quality;

use Closure;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Runs package-scoped formatting, analysis, and tests from the suite root.
 */
final readonly class PackageQualityRunner
{
    private const string JSON_SCHEMA = 'nvl-package-quality-v1';

    /**
     * Create a root package-quality runner.
     *
     * @param  array<string, mixed>  $catalog
     * @param  Closure(list<string>, string, Closure(string, string): void): int  $execute
     * @param  Closure(string): void  $writeOutput
     * @param  Closure(string): void  $writeError
     */
    public function __construct(
        private string $root,
        private array $catalog,
        private Closure $execute,
        private Closure $writeOutput,
        private Closure $writeError,
    ) {}

    /**
     * Run the requested packages and return a process-compatible exit code.
     *
     * @param  list<string>  $arguments
     */
    public function run(array $arguments): int
    {
        try {
            $options = $this->parseArguments($arguments);
            $this->assertPackages($options['packages']);
        } catch (InvalidArgumentException $exception) {
            return $this->usageFailure($exception->getMessage(), $this->requestedFormat($arguments));
        }

        $results = [];
        $firstFailure = 0;

        foreach ($options['packages'] as $package) {
            try {
                $packageResult = $this->runPackage(
                    package: $package,
                    format: $options['format'],
                    continueOnError: $options['continue_on_error'],
                );
            } catch (RuntimeException $exception) {
                return $this->configurationFailure(
                    message: $exception->getMessage(),
                    format: $options['format'],
                    results: $results,
                );
            }

            $results[] = $packageResult;

            if ($packageResult['exit_code'] !== 0 && $firstFailure === 0) {
                $firstFailure = $packageResult['exit_code'];
            }

            if ($packageResult['exit_code'] !== 0 && ! $options['continue_on_error']) {
                break;
            }
        }

        if ($options['format'] === 'json') {
            $this->writeJson([
                'schema' => self::JSON_SCHEMA,
                'status' => $firstFailure === 0 ? 'passed' : 'failed',
                'packages' => $results,
            ]);
        }

        return $firstFailure;
    }

    /**
     * Parse package names and supported CLI switches.
     *
     * @param  list<string>  $arguments
     * @return array{packages: list<string>, format: 'json'|'table', continue_on_error: bool}
     */
    private function parseArguments(array $arguments): array
    {
        $packages = [];
        $format = 'table';
        $continueOnError = false;

        foreach ($arguments as $argument) {
            if ($argument === '--continue-on-error') {
                $continueOnError = true;

                continue;
            }

            if (str_starts_with($argument, '--format=')) {
                $requestedFormat = substr($argument, strlen('--format='));

                if (! in_array($requestedFormat, ['table', 'json'], true)) {
                    throw new InvalidArgumentException(
                        "Unsupported output format [{$requestedFormat}]. Expected table or json.",
                    );
                }

                $format = $requestedFormat;

                continue;
            }

            if (str_starts_with($argument, '--')) {
                throw new InvalidArgumentException("Unknown option [{$argument}].");
            }

            $packages[] = $argument;
        }

        if ($packages === []) {
            throw new InvalidArgumentException('Select at least one package.');
        }

        return [
            'packages' => array_values(array_unique($packages)),
            'format' => $format,
            'continue_on_error' => $continueOnError,
        ];
    }

    /**
     * Determine the requested format before full argument validation.
     *
     * @param  list<string>  $arguments
     * @return 'json'|'table'
     */
    private function requestedFormat(array $arguments): string
    {
        return in_array('--format=json', $arguments, true) ? 'json' : 'table';
    }

    /**
     * Reject package names that are not present in the canonical family.
     *
     * @param  list<string>  $packages
     */
    private function assertPackages(array $packages): void
    {
        $available = $this->catalog['packages'] ?? null;

        if (! is_array($available) || ! array_is_list($available)) {
            throw new InvalidArgumentException('The package family catalog is invalid.');
        }

        foreach ($packages as $package) {
            if (! in_array($package, $available, true)) {
                throw new InvalidArgumentException("Unknown package [{$package}].");
            }
        }
    }

    /**
     * Run all declared quality steps for one package.
     *
     * @return array{
     *     package: string,
     *     status: 'failed'|'passed',
     *     exit_code: int,
     *     duration_ms: int,
     *     steps: list<array{name: string, status: 'failed'|'passed'|'skipped', exit_code: int|null, duration_ms: int}>
     * }
     */
    private function runPackage(string $package, string $format, bool $continueOnError): array
    {
        $packageStartedAt = hrtime(true);
        $steps = [];
        $exitCode = 0;

        if ($format === 'table') {
            ($this->writeOutput)("\nPackage nvl/{$package}\n");
        }

        foreach ($this->commandsFor($package) as $step) {
            if ($step['command'] === null) {
                $steps[] = [
                    'name' => $step['name'],
                    'status' => 'skipped',
                    'exit_code' => null,
                    'duration_ms' => 0,
                ];

                if ($format === 'table') {
                    ($this->writeOutput)("{$step['name']}: skipped\n");
                }

                continue;
            }

            $startedAt = hrtime(true);
            $stepExitCode = ($this->execute)(
                $step['command'],
                $this->root,
                function (string $type, string $buffer) use ($format): void {
                    if ($format !== 'table') {
                        return;
                    }

                    if ($type === 'err') {
                        ($this->writeError)($buffer);

                        return;
                    }

                    ($this->writeOutput)($buffer);
                },
            );
            $stepDuration = $this->durationMilliseconds($startedAt);
            $status = $stepExitCode === 0 ? 'passed' : 'failed';
            $steps[] = [
                'name' => $step['name'],
                'status' => $status,
                'exit_code' => $stepExitCode,
                'duration_ms' => $stepDuration,
            ];

            if ($format === 'table') {
                ($this->writeOutput)("{$step['name']}: {$status} ({$stepDuration} ms)\n");
            }

            if ($stepExitCode !== 0 && $exitCode === 0) {
                $exitCode = $stepExitCode;
            }

            if ($stepExitCode !== 0 && ! $continueOnError) {
                break;
            }
        }

        $duration = $this->durationMilliseconds($packageStartedAt);

        if ($format === 'table') {
            $status = $exitCode === 0 ? 'passed' : 'failed';
            ($this->writeOutput)("nvl/{$package}: {$status} ({$duration} ms)\n");
        }

        return [
            'package' => $package,
            'status' => $exitCode === 0 ? 'passed' : 'failed',
            'exit_code' => $exitCode,
            'duration_ms' => $duration,
            'steps' => $steps,
        ];
    }

    /**
     * Build ordered root commands for one package.
     *
     * @return list<array{name: string, command: list<string>|null}>
     */
    private function commandsFor(string $package): array
    {
        $packageDirectory = $this->root.'/packages/nvl/'.$package;
        $testDirectory = $packageDirectory.'/tests';
        $phpUnitConfiguration = $packageDirectory.'/phpunit.xml.dist';
        $cacheDirectory = $this->root.'/storage/framework/cache/package-quality/'.$package;
        $phpStanConfiguration = $cacheDirectory.'/phpstan.neon';

        if (! is_dir($packageDirectory)) {
            throw new RuntimeException("Package directory [nvl/{$package}] does not exist.");
        }

        $this->writePhpStanConfiguration($phpStanConfiguration, $cacheDirectory.'/phpstan');
        $analysisPaths = $this->analysisPaths($package, $packageDirectory);
        $testCommand = null;

        if (is_dir($testDirectory)) {
            if (! is_file($phpUnitConfiguration)) {
                throw new RuntimeException("Package [nvl/{$package}] has tests but no phpunit.xml.dist.");
            }

            $testCommand = [
                $this->root.'/vendor/bin/pest',
                '--test-directory=packages/nvl/'.$package.'/tests',
                '--configuration='.$phpUnitConfiguration,
                '--bootstrap='.$this->root.'/vendor/autoload.php',
                '--cache-directory='.$cacheDirectory.'/phpunit',
                '--compact',
                $testDirectory,
            ];
        }

        return [
            [
                'name' => 'format',
                'command' => [
                    $this->root.'/vendor/bin/pint',
                    '--test',
                    '--format',
                    'agent',
                    $packageDirectory,
                ],
            ],
            [
                'name' => 'analysis',
                'command' => [
                    $this->root.'/vendor/bin/phpstan',
                    'analyse',
                    '--configuration='.$phpStanConfiguration,
                    '--no-progress',
                    '--error-format=table',
                    '--memory-limit=3G',
                    ...$analysisPaths,
                ],
            ],
            [
                'name' => 'tests',
                'command' => $testCommand,
            ],
        ];
    }

    /**
     * Resolve declared mutable analysis paths and append unreleased migrations.
     *
     * @return list<string>
     */
    private function analysisPaths(string $package, string $packageDirectory): array
    {
        $quality = $this->qualityConfiguration();
        $packageDescriptors = $quality['packages'] ?? null;

        if (! is_array($packageDescriptors)) {
            throw new RuntimeException('The package quality descriptors are invalid.');
        }

        $descriptor = $packageDescriptors[$package] ?? null;
        $relativePaths = is_array($descriptor) ? ($descriptor['analysis_paths'] ?? null) : null;

        if (! is_array($relativePaths) || ! array_is_list($relativePaths) || $relativePaths === []) {
            throw new RuntimeException("Package [nvl/{$package}] has no quality analysis paths.");
        }

        $paths = [];

        foreach ($relativePaths as $relativePath) {
            if (! is_string($relativePath) || ! $this->isSafeRelativePath($relativePath)) {
                throw new RuntimeException("Package [nvl/{$package}] has an invalid analysis path.");
            }

            $absolutePath = $packageDirectory.'/'.$relativePath;

            if (! file_exists($absolutePath)) {
                throw new RuntimeException(
                    "Package [nvl/{$package}] analysis path [{$relativePath}] does not exist.",
                );
            }

            $paths[] = $absolutePath;
        }

        return array_values(array_unique([
            ...$paths,
            ...$this->unreleasedMigrations($package, $packageDirectory),
        ]));
    }

    /**
     * Return migration files not yet protected by the released contract.
     *
     * @return list<string>
     */
    private function unreleasedMigrations(string $package, string $packageDirectory): array
    {
        $quality = $this->qualityConfiguration();
        $contractRelativePath = $quality['released_migrations_contract'] ?? null;

        if (! is_string($contractRelativePath) || ! $this->isSafeRelativePath($contractRelativePath)) {
            throw new RuntimeException('The released migration contract path is invalid.');
        }

        $contractPath = $this->root.'/'.$contractRelativePath;

        if (! is_file($contractPath)) {
            throw new RuntimeException('The released migration contract does not exist.');
        }

        $contractContents = file_get_contents($contractPath);

        if (! is_string($contractContents)) {
            throw new RuntimeException('The released migration contract cannot be read.');
        }

        try {
            $contracts = json_decode(
                $contractContents,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('The released migration contract is invalid JSON.', 0, $exception);
        }

        if (! is_array($contracts)) {
            throw new RuntimeException('The released migration contract must contain an object.');
        }

        $contractPackages = $contracts['packages'] ?? null;

        if (! is_array($contractPackages)) {
            throw new RuntimeException('The released migration contract has no package map.');
        }

        $packageContract = $contractPackages[$package] ?? null;
        $released = is_array($packageContract) ? ($packageContract['migrations'] ?? []) : [];

        if (! is_array($released)) {
            throw new RuntimeException("Package [nvl/{$package}] has invalid migration contracts.");
        }

        $migrationDirectory = $packageDirectory.'/database/migrations';

        if (! is_dir($migrationDirectory)) {
            return [];
        }

        $migrations = glob($migrationDirectory.'/*.php') ?: [];
        $unreleased = array_filter(
            $migrations,
            static fn (string $path): bool => ! array_key_exists(
                'database/migrations/'.basename($path),
                $released,
            ),
        );
        sort($unreleased);

        return $unreleased;
    }

    /**
     * Return the validated quality section of the package family catalog.
     *
     * @return array<string, mixed>
     */
    private function qualityConfiguration(): array
    {
        $quality = $this->catalog['quality'] ?? null;

        if (! is_array($quality)) {
            throw new RuntimeException('The package family has no quality configuration.');
        }

        $validated = [];

        foreach ($quality as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('The package quality configuration must use string keys.');
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    /**
     * Write a root-resolvable PHPStan configuration with a package-local cache.
     */
    private function writePhpStanConfiguration(string $path, string $temporaryDirectory): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the package-quality cache directory.');
        }

        $contents = implode("\n", [
            'includes:',
            '    - '.$this->root.'/vendor/larastan/larastan/extension.neon',
            '    - '.$this->root.'/vendor/nesbot/carbon/extension.neon',
            '',
            'parameters:',
            '    level: max',
            '    checkModelProperties: true',
            '    tmpDir: '.$temporaryDirectory,
            '',
        ]);

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the package PHPStan configuration.');
        }
    }

    /**
     * Determine whether a catalog path is a normalized relative path.
     */
    private function isSafeRelativePath(string $path): bool
    {
        return $path !== ''
            && $path === trim($path)
            && ! str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! in_array('..', explode('/', str_replace('\\', '/', $path)), true);
    }

    /**
     * Return elapsed monotonic time in milliseconds.
     */
    private function durationMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * Emit a stable usage failure in the requested format.
     */
    private function usageFailure(string $message, string $format): int
    {
        if ($format === 'json') {
            $this->writeJson([
                'schema' => self::JSON_SCHEMA,
                'status' => 'failed',
                'error' => $message,
                'packages' => [],
            ]);

            return 2;
        }

        ($this->writeError)($message.PHP_EOL);

        return 2;
    }

    /**
     * Emit a stable package-configuration failure in the requested format.
     *
     * @param  list<array<string, mixed>>  $results
     */
    private function configurationFailure(string $message, string $format, array $results): int
    {
        if ($format === 'json') {
            $this->writeJson([
                'schema' => self::JSON_SCHEMA,
                'status' => 'failed',
                'error' => $message,
                'packages' => $results,
            ]);

            return 2;
        }

        ($this->writeError)($message.PHP_EOL);

        return 2;
    }

    /**
     * Encode and emit one JSON document.
     *
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(array $payload): void
    {
        ($this->writeOutput)(json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL);
    }
}
