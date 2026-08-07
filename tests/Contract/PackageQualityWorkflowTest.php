<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('defines one installable suite package for every internal module', function (): void {
    $root = dirname(__DIR__, 2);
    $manifest = json_decode(
        file_get_contents($root.'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $catalog = require $root.'/tools/package-family.php';
    $packages = $catalog['packages'];
    $replacedPackages = array_fill_keys(
        array_map(static fn (string $package): string => 'nvl/'.$package, $packages),
        'self.version',
    );
    ksort($replacedPackages);

    expect($manifest['name'] ?? null)->toBe('nvl/laravel-suite')
        ->and($manifest['type'] ?? null)->toBe('library')
        ->and($manifest)->not->toHaveKey('repositories')
        ->and(array_intersect_key($manifest['require'] ?? [], $replacedPackages))->toBe([])
        ->and($manifest['replace'] ?? null)->toBe($replacedPackages)
        ->and($manifest['extra']['laravel']['providers'] ?? null)->toBe([
            'Nvl\\Suite\\SuiteServiceProvider',
        ])
        ->and($manifest['autoload']['psr-4']['Nvl\\Suite\\'] ?? null)->toBe('src/')
        ->and($manifest['autoload']['psr-4'] ?? [])->not->toHaveKey('Nvl\\Workbench\\')
        ->and($manifest['autoload-dev']['psr-4']['Nvl\\Workbench\\'] ?? null)->toBe('app/')
        ->and($manifest['autoload']['psr-4'] ?? [])->not->toHaveKey('App\\');

    foreach ($packages as $package) {
        $namespace = str_replace(' ', '', ucwords(str_replace('-', ' ', $package)));

        expect($manifest['autoload']['psr-4']['Nvl\\'.$namespace.'\\'] ?? null)
            ->toBe('packages/nvl/'.$package.'/src/');
    }
});

it('keeps Composer update hooks independent of optional development tools', function (): void {
    $manifest = json_decode(
        file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['scripts']['post-update-cmd'] ?? null)
        ->toBeArray()
        ->not->toContain('@php artisan boost:update --ansi');
});

it('gives clean-runner integration tests a deterministic non-production application key', function (): void {
    $configuration = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml');

    expect($configuration)->not->toBeFalse();

    $matches = $configuration->xpath('/phpunit/php/env[@name="APP_KEY"]');
    $testingKey = is_array($matches) ? ($matches[0] ?? null) : null;

    expect($testingKey)->toBeInstanceOf(SimpleXMLElement::class)
        ->and((string) $testingKey['value'])->toStartWith('base64:')
        ->and((string) $testingKey['force'])->toBe('true');
});

it('runs five routine gates without scheduled fan-out', function (): void {
    $root = dirname(__DIR__, 2);
    $qualityWorkflowPath = $root.'/.github/workflows/package-quality.yml';
    $releaseWorkflowPath = $root.'/.github/workflows/package-release.yml';
    $workflow = Yaml::parseFile($qualityWorkflowPath);
    $qualityWorkflowSource = file_get_contents($qualityWorkflowPath);
    $releaseWorkflowSource = file_get_contents($releaseWorkflowPath);

    expect($workflow)->toBeArray();

    $jobs = $workflow['jobs'] ?? [];

    expect(array_keys($jobs))->toBe([
        'quality',
        'current-tests',
        'laravel12-lowest',
        'postgresql',
        'changed-coverage',
    ])
        ->and($workflow['on'])->not->toHaveKey('schedule')
        ->and($workflow['on'])->toHaveKey('workflow_call')
        ->and($workflow['on']['push']['branches'] ?? null)->toBe(['main'])
        ->and($workflow['on']['push']['tags'] ?? null)->toBeNull()
        ->and($workflow['concurrency']['cancel-in-progress'] ?? null)
        ->toBeTrue()
        ->and($qualityWorkflowSource)->toBeString()->toContain(
            'actions/checkout@v6',
            'actions/upload-artifact@v7',
        )->not->toContain(
            'actions/checkout@v4',
            'actions/upload-artifact@v4',
        )
        ->and(is_file($root.'/.github/workflows/media-quality.yml'))->toBeFalse();

    $releaseWorkflow = Yaml::parseFile($releaseWorkflowPath);

    expect($releaseWorkflow)->toBeArray()
        ->and($releaseWorkflow['on']['push']['tags'] ?? null)->toBe(['v*'])
        ->and($releaseWorkflow['on']['push']['branches'] ?? null)->toBeNull()
        ->and($releaseWorkflowSource)->toBeString()->toContain(
            'actions/checkout@v6',
            'actions/upload-artifact@v7',
            'actions/download-artifact@v8',
        )->not->toContain(
            'actions/checkout@v4',
            'actions/upload-artifact@v4',
            'actions/download-artifact@v4',
        );
});

it('keeps routine quality focused on formatting analysis manifests and contracts', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $quality = $workflow['jobs']['quality'] ?? [];
    $commands = collect($quality['steps'] ?? [])
        ->pluck('run')
        ->filter(static fn (mixed $command): bool => is_string($command))
        ->implode("\n");

    expect($quality['timeout-minutes'] ?? null)->toBe(15)
        ->and($commands)->toContain(
            'vendor/bin/pest --compact tests/Contract',
            'composer validate --strict',
            'composer autoload:check',
            'composer packages:validate',
            'composer contracts:check',
            'composer analyse',
            'composer packages:analyse',
            'composer format:test',
        )
        ->not->toContain(
            'npm ci',
            'composer test:packages',
        );
});

it('tests the current stack Laravel 12 lowest and PostgreSQL exactly once', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $jobs = $workflow['jobs'] ?? [];
    $currentCommands = workflowCommands($jobs['current-tests'] ?? []);
    $lowestCommands = workflowCommands($jobs['laravel12-lowest'] ?? []);
    $postgresCommands = workflowCommands($jobs['postgresql'] ?? []);

    expect($currentCommands)->toContain(
        '"laravel/framework:^13.0"',
        '"orchestra/testbench:^11.0"',
        'composer test',
    )
        ->and($lowestCommands)->toContain(
            '"laravel/framework:^12.0"',
            '"laravel/tinker:^2.10.1"',
            '"orchestra/testbench:^10.0"',
            '--prefer-lowest',
            'composer test:integration',
            'composer test:packages',
        )
        ->and($jobs['postgresql']['services']['postgres']['image'] ?? null)->toBe('postgres:17')
        ->and($postgresCommands)->toContain(
            'for package in activity auth comments content',
            'composer test:integration',
        )
        ->not->toContain('mysql');
});

it('collects coverage only for packages changed by the event', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $coverage = $workflow['jobs']['changed-coverage'] ?? [];
    $commands = workflowCommands($coverage);
    $setup = collect($coverage['steps'] ?? [])->firstWhere('uses', 'shivammathur/setup-php@v2');

    expect($commands)->toContain(
        'git diff --name-only "$base_sha...HEAD" -- packages/nvl',
        '[[ -f "packages/nvl/$package/composer.json" ]]',
        "jq -R -s -c 'split(\"\\n\") | map(select(length > 0))'",
        'while IFS= read -r package; do',
        '--test-directory="packages/nvl/$package/tests"',
        '--exclude-testsuite=infrastructure',
        'check-clover-coverage.php',
        'check-changed-clover-coverage.php',
    )
        ->and($setup)->toBeArray()
        ->and($setup['with']['coverage'] ?? null)->toBe('pcov')
        ->and($setup['with']['ini-values'] ?? null)
        ->toBe('pcov.directory=${{ github.workspace }}/packages/nvl')
        ->and($coverage)->not->toHaveKey('strategy');
});

it('publishes one tagged suite archive only after all five routine gates pass', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/package-release.yml');

    expect($workflow)->toBeArray();

    $jobs = $workflow['jobs'] ?? [];
    $archive = $jobs['archive'] ?? [];
    $publish = $jobs['publish-release'] ?? [];
    $archiveCommands = workflowCommands($archive);
    $publishCommands = workflowCommands($publish);

    expect($jobs['checks']['uses'] ?? null)->toBe('./.github/workflows/package-quality.yml')
        ->and(array_keys($jobs))->toBe(['checks', 'archive', 'publish-release'])
        ->and($archive['needs'] ?? null)->toBe('checks')
        ->and($archiveCommands)->toContain(
            'COMPOSER_ROOT_VERSION="$package_version" composer archive',
            'test "$archive_count" -eq 1',
            '{type:"path",url:$url,options:{symlink:false,versions:{"nvl/laravel-suite":$version}}}',
            '"nvl/laravel-suite:$PACKAGE_VERSION"',
            'composer audit --locked --no-interaction',
        )
        ->not->toContain(
            'for directory in packages/nvl/*; do',
            'build-public-composer-repository.php',
            'actions/deploy-pages',
        )
        ->and($publish['needs'] ?? null)->toBe('archive')
        ->and($publishCommands)->toContain(
            "--pattern 'nvl-laravel-suite-*.zip'",
            'gh release create',
        )
        ->and($publish['permissions']['contents'] ?? null)->toBe('write')
        ->and($publish['permissions']['pages'] ?? null)->toBeNull();
});

it('keeps every package workflow shell block syntactically valid', function (): void {
    $root = dirname(__DIR__, 2);

    foreach (['package-quality.yml', 'package-release.yml'] as $filename) {
        $workflow = Yaml::parseFile($root.'/.github/workflows/'.$filename);

        expect($workflow)->toBeArray();

        foreach ($workflow['jobs'] ?? [] as $jobName => $job) {
            foreach ($job['steps'] ?? [] as $index => $step) {
                $script = $step['run'] ?? null;

                if (! is_string($script)) {
                    continue;
                }

                $sanitized = preg_replace('/\$\{\{.*?\}\}/s', 'ci_expression', $script);

                expect($sanitized)->toBeString();

                $process = new Process(['bash', '-n']);
                $process->setInput($sanitized);
                $process->setTimeout(5);
                $process->run();

                expect($process->isSuccessful())->toBeTrue(sprintf(
                    'Invalid shell in workflow [%s], job [%s], step [%s]: %s',
                    $filename,
                    $jobName,
                    $step['name'] ?? $index,
                    $process->getErrorOutput(),
                ));
            }
        }
    }
});

/**
 * Return every shell command declared by a workflow job.
 *
 * @param  array<string, mixed>  $job
 */
function workflowCommands(array $job): string
{
    return collect($job['steps'] ?? [])
        ->pluck('run')
        ->filter(static fn (mixed $command): bool => is_string($command))
        ->implode("\n");
}
