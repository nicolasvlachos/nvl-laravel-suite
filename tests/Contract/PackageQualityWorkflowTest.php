<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

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
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $jobs = $workflow['jobs'] ?? [];
    $routineJobs = array_filter(
        $jobs,
        static fn (mixed $job): bool => is_array($job) && ! isset($job['if']),
    );

    expect(array_keys($routineJobs))->toBe([
        'quality',
        'current-tests',
        'laravel12-lowest',
        'postgresql',
        'changed-coverage',
    ])
        ->and($workflow['on'])->not->toHaveKey('schedule')
        ->and($workflow['on']['push']['branches'] ?? null)->toBe(['main'])
        ->and($workflow['on']['push']['tags'] ?? null)->toBe(['v*'])
        ->and($workflow['concurrency']['cancel-in-progress'] ?? null)
        ->toBe('${{ !startsWith(github.ref, \'refs/tags/v\') }}')
        ->and(is_file($root.'/.github/workflows/media-quality.yml'))->toBeFalse();
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

it('publishes tagged archives only after all five routine gates pass', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $jobs = $workflow['jobs'] ?? [];
    $archives = $jobs['archives'] ?? [];
    $publish = $jobs['publish-release'] ?? [];
    $deploy = $jobs['deploy-composer-repository'] ?? [];
    $archiveCommands = workflowCommands($archives);
    $publishCommands = workflowCommands($publish);

    expect($archives['if'] ?? null)->toBe("startsWith(github.ref, 'refs/tags/v')")
        ->and($archives['needs'] ?? null)->toBe([
            'quality',
            'current-tests',
            'laravel12-lowest',
            'postgresql',
            'changed-coverage',
        ])
        ->and($archiveCommands)->toContain(
            'for directory in packages/nvl/*; do',
            'composer archive',
            'inspect-package-archive.php',
            'composer config repositories.nvl artifact',
            'composer audit --locked --no-interaction',
        )
        ->and($publish['if'] ?? null)->toBe("startsWith(github.ref, 'refs/tags/v')")
        ->and($publish['needs'] ?? null)->toBe('archives')
        ->and($publishCommands)->toContain(
            'test "$archive_count" -eq 20',
            'build-public-composer-repository.php',
            'gh release create',
        )
        ->and($publish['permissions']['contents'] ?? null)->toBe('write')
        ->and($publish['permissions']['pages'] ?? null)->toBe('write')
        ->and($deploy['needs'] ?? null)->toBe('publish-release')
        ->and($deploy['steps'][0]['uses'] ?? null)->toBe('actions/deploy-pages@v4');
});

it('keeps every package-quality shell block syntactically valid', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/package-quality.yml');

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
                'Invalid shell in job [%s], step [%s]: %s',
                $jobName,
                $step['name'] ?? $index,
                $process->getErrorOutput(),
            ));
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
