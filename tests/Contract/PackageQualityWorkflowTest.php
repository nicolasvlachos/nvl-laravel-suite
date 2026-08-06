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

it('keeps fast line coverage complete and its Auth infrastructure exclusion effective', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');
    $catalog = require $root.'/tools/package-family.php';
    $expectedPackages = $catalog['packages'];

    expect($workflow)->toBeArray();

    sort($expectedPackages);

    $job = $workflow['jobs']['line-coverage'] ?? null;

    expect($job)->toBeArray();

    $packages = $job['strategy']['matrix']['package'] ?? [];
    $setupStep = collect($job['steps'] ?? [])->firstWhere(
        'uses',
        'shivammathur/setup-php@v2',
    );
    $coverageStep = collect($job['steps'] ?? [])->firstWhere(
        'name',
        'Enforce line threshold',
    );

    expect($packages)->toBeArray();

    sort($packages);

    expect($packages)->toBe($expectedPackages)
        ->and($coverageStep)->toBeArray();

    $command = $coverageStep['run'] ?? null;
    $lines = is_string($command)
        ? array_map('trim', explode("\n", $command))
        : [];

    expect($command)->toBeString()
        ->toContain(
            '--exclude-testsuite=infrastructure',
            '--test-directory="packages/nvl/${{ matrix.package }}/tests"',
            '"${coverage_arguments[@]}"',
            'check-changed-clover-coverage.php',
            'COVERAGE_BASE_SHA',
            '0000000000000000000000000000000000000000',
            'minimum_line=90',
            '"$minimum_line" 0',
        )
        ->and($lines)->not->toContain(
            '"packages/nvl/${{ matrix.package }}/tests"',
        )
        ->and($setupStep)->toBeArray()
        ->and($setupStep['with']['coverage'] ?? null)->toBe('pcov')
        ->and($setupStep['with']['ini-values'] ?? null)->toBe(
            'pcov.directory=${{ github.workspace }}/packages/nvl/${{ matrix.package }}/src',
        )
        ->and($coverageStep['env']['COVERAGE_BASE_SHA'] ?? null)->toContain(
            'github.event.pull_request.base.sha',
            'github.event.before',
        )
        ->and($workflow['jobs'])->not->toHaveKey('branch-coverage');
});

it('uses representative compatibility boundaries and push-scoped line coverage', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $jobs = $workflow['jobs'] ?? [];
    $compatibility = $jobs['package-matrix']['strategy']['matrix']['include'] ?? null;
    $frameworkStep = collect($jobs['package-matrix']['steps'] ?? [])->firstWhere(
        'name',
        'Select framework line',
    );
    $frameworkCommand = is_array($frameworkStep) ? ($frameworkStep['run'] ?? null) : null;
    $lineCoverage = $jobs['line-coverage'] ?? null;
    $archives = $jobs['archives'] ?? null;
    $archiveExercise = is_array($archives)
        ? collect($archives['steps'] ?? [])->firstWhere('name', 'Install and exercise built archives')
        : null;
    $archiveCommand = is_array($archiveExercise) ? ($archiveExercise['run'] ?? null) : null;

    expect($compatibility)->toBe([
        ['php' => '8.3', 'laravel' => '12', 'dependencies' => 'lowest'],
        ['php' => '8.4', 'laravel' => '12', 'dependencies' => 'highest'],
        ['php' => '8.4', 'laravel' => '13', 'dependencies' => 'lowest'],
        ['php' => '8.5', 'laravel' => '13', 'dependencies' => 'highest'],
    ])
        ->and($frameworkCommand)->toBeString()->toContain(
            '"laravel/tinker:^${{ matrix.laravel == \'12\' && \'2.10.1\' || \'3.0\' }}"',
        )
        ->and($lineCoverage)->toBeArray()
        ->and($lineCoverage['if'] ?? null)->toContain(
            "github.event_name == 'pull_request'",
            "github.event_name == 'push'",
        )
        ->and($archives)->toBeArray()
        ->and($archives['if'] ?? null)->toBeNull()
        ->and($archiveExercise)->toBeArray()
        ->and($archiveCommand)->toBeString()
        ->toContain(
            'composer config repositories.nvl artifact',
            'export QUEUE_CONNECTION=database',
            'export DB_QUEUE_RETRY_AFTER=960',
            'php artisan config:cache',
        )
        ->not->toContain('composer config repositories.nvl composer');
});

it('publishes tagged archives only after every release gate succeeds', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $publish = $workflow['jobs']['publish-release'] ?? null;
    $deploy = $workflow['jobs']['deploy-composer-repository'] ?? null;
    $metadataStep = is_array($publish)
        ? collect($publish['steps'] ?? [])->firstWhere(
            'name',
            'Build public Composer repository metadata',
        )
        : null;
    $releaseStep = is_array($publish)
        ? collect($publish['steps'] ?? [])->firstWhere(
            'name',
            'Publish immutable GitHub release assets',
        )
        : null;
    $pagesUpload = is_array($publish)
        ? collect($publish['steps'] ?? [])->firstWhere(
            'name',
            'Upload Composer repository to GitHub Pages',
        )
        : null;
    $metadataCommand = is_array($metadataStep) ? ($metadataStep['run'] ?? null) : null;
    $releaseCommand = is_array($releaseStep) ? ($releaseStep['run'] ?? null) : null;

    expect($publish)->toBeArray()
        ->and($publish['if'] ?? null)->toBe("startsWith(github.ref, 'refs/tags/v')")
        ->and($publish['needs'] ?? null)->toBe([
            'quality',
            'package-matrix',
            'database-matrix',
            'auth-security-integration',
            'mail-notifications-mariadb',
            'comments-mariadb',
            'line-coverage',
            'standalone-consumers',
            'archives',
        ])
        ->and($publish['permissions']['contents'] ?? null)->toBe('write')
        ->and($publish['permissions']['pages'] ?? null)->toBe('write')
        ->and($metadataCommand)->toBeString()->toContain(
            'test "$archive_count" -eq 20',
            'build-public-composer-repository.php',
            'build/previous-packages.json',
            'build/public/packages.json',
            'Unable to fetch the existing Composer index',
        )
        ->and($releaseCommand)->toBeString()->toContain(
            'gh release view',
            'gh release download',
            'gh release create',
            'cmp -s',
            '--verify-tag',
            '--generate-notes',
        )
        ->and($pagesUpload)->toBeArray()
        ->and($pagesUpload['uses'] ?? null)->toBe('actions/upload-pages-artifact@v4')
        ->and($deploy)->toBeArray()
        ->and($deploy['needs'] ?? null)->toBe('publish-release')
        ->and($deploy['environment']['name'] ?? null)->toBe('github-pages')
        ->and($deploy['steps'][0]['uses'] ?? null)->toBe('actions/deploy-pages@v4');
});

it('keeps every package-quality shell block syntactically valid', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    foreach ($workflow['jobs'] ?? [] as $jobName => $job) {
        foreach ($job['steps'] ?? [] as $index => $step) {
            $script = $step['run'] ?? null;

            if (! is_string($script)) {
                continue;
            }

            $sanitized = preg_replace(
                '/\$\{\{.*?\}\}/s',
                'ci_expression',
                $script,
            );

            expect($sanitized)->toBeString();

            $process = new Process(['bash', '-n']);
            $process->setInput($sanitized);
            $process->setTimeout(5);
            $process->run();

            expect($process->isSuccessful())->toBeTrue(
                sprintf(
                    'Invalid shell in job [%s], step [%s]: %s',
                    $jobName,
                    $step['name'] ?? $index,
                    $process->getErrorOutput(),
                ),
            );
        }
    }
});

it('keeps the Auth clean-consumer proof on current package-owned surfaces', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $jobs = $workflow['jobs'] ?? [];
    $job = $jobs['standalone-consumers'] ?? null;
    $step = collect($job['steps'] ?? [])->firstWhere('name', 'Create clean consumer');
    $command = is_array($step) ? ($step['run'] ?? null) : null;
    $packages = $job['strategy']['matrix']['package'] ?? [];

    expect($job)->toBeArray()
        ->and($packages)->toContain('auth')
        ->and($step)->toBeArray()
        ->and($command)->toBeString()
        ->toContain(
            '--no-dev',
            '--prefer-lowest',
            '"nvl/${{ matrix.package }}:@dev"',
            'php artisan package:discover',
            'php artisan config:cache',
            'php artisan route:cache',
            'php artisan migrate --force',
            'select(endswith(":doctor"))',
            'php artisan "$doctor" --strict --format=json',
            'php artisan migrate:rollback --force --step=999',
        )
        ->not->toContain(
            'tools/fixtures/auth-production-consumer',
            'tools/run-auth-production-consumer.sh',
            'auth-consumer:maintenance',
            'auth-consumer:smoke',
            'AuthMaintenanceTask',
            'SynchronizePermissionCatalogAction',
            'boost:update',
        )
        ->and($jobs)->not->toHaveKey('auth-consumer-profiles');

    expect(substr_count(
        $command,
        'php artisan "$doctor" --strict --format=json',
    ))->toBe(2)
        ->and(is_dir($root.'/tools/fixtures/auth-production-consumer'))->toBeFalse()
        ->and(is_file($root.'/tools/run-auth-production-consumer.sh'))->toBeFalse();
});

it('bounds routine CI fan-out while preserving the full release grid', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $jobs = $workflow['jobs'] ?? [];
    $standalone = $jobs['standalone-consumers'] ?? null;
    $laravelMatrix = is_array($standalone)
        ? ($standalone['strategy']['matrix']['laravel'] ?? null)
        : null;

    expect($workflow['concurrency']['cancel-in-progress'] ?? null)
        ->toBe('${{ !startsWith(github.ref, \'refs/tags/v\') }}')
        ->and($laravelMatrix)->toBeString()
        ->toContain(
            "startsWith(github.ref, 'refs/tags/v')",
            "github.event_name == 'schedule'",
            "github.event_name == 'workflow_dispatch'",
            '["12", "13"]',
            '["13"]',
        );

    foreach ([
        'package-matrix' => 20,
        'database-matrix' => 20,
        'auth-security-integration' => 20,
        'line-coverage' => 15,
        'standalone-consumers' => 15,
    ] as $jobName => $timeout) {
        expect($jobs[$jobName]['strategy']['fail-fast'] ?? null)
            ->toBeTrue()
            ->and($jobs[$jobName]['timeout-minutes'] ?? null)
            ->toBe($timeout);
    }

    expect($jobs['quality']['timeout-minutes'] ?? null)->toBe(20)
        ->and($jobs['archives']['timeout-minutes'] ?? null)->toBe(30);
});
