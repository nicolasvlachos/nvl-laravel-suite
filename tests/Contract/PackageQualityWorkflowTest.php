<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Nvl\Suite\Quality\PackageQualityRunner;
use Nvl\Suite\Support\SuiteModuleCatalog;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

const SUITE_CHECKOUT_ACTION = 'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1';
const SUITE_SETUP_PHP_ACTION = 'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240';
const SUITE_UPLOAD_ARTIFACT_ACTION = 'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a';
const SUITE_DOWNLOAD_ARTIFACT_ACTION = 'actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c';
const SUITE_COMPOSER_TOOL = 'composer:2.10.2';

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

it('enforces Activitylog v5 and its PHP 8.4 runtime floor', function (): void {
    $root = dirname(__DIR__, 2);
    $suiteManifest = json_decode(
        file_get_contents($root.'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $activityManifest = json_decode(
        file_get_contents($root.'/packages/nvl/activity/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');
    $lowestSteps = $workflow['jobs']['laravel12-lowest']['steps'] ?? [];
    $setupPhp = collect($lowestSteps)->firstWhere('uses', SUITE_SETUP_PHP_ACTION);

    expect($suiteManifest['require']['php'] ?? null)->toBe('^8.4')
        ->and($activityManifest['require']['php'] ?? null)->toBe('^8.4')
        ->and($suiteManifest['require']['spatie/laravel-activitylog'] ?? null)->toBe('^5.0')
        ->and($activityManifest['require']['spatie/laravel-activitylog'] ?? null)->toBe('^5.0')
        ->and($suiteManifest['autoload']['files'] ?? [])->toBe([])
        ->and($activityManifest['autoload']['files'] ?? [])->toBe([])
        ->and(is_array($setupPhp) ? ($setupPhp['with']['php-version'] ?? null) : null)->toBe('8.4');
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

it('runs six routine gates without scheduled fan-out', function (): void {
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
        'mysql-family',
        'changed-coverage',
    ])
        ->and($workflow['on'])->not->toHaveKey('schedule')
        ->and($workflow['on'])->toHaveKey('workflow_call')
        ->and($workflow['on']['push']['branches'] ?? null)->toBe(['main'])
        ->and($workflow['on']['push']['tags'] ?? null)->toBeNull()
        ->and($workflow['concurrency']['cancel-in-progress'] ?? null)
        ->toBeTrue()
        ->and($qualityWorkflowSource)->toBeString()->toContain(
            SUITE_CHECKOUT_ACTION,
            SUITE_UPLOAD_ARTIFACT_ACTION,
        )->not->toContain(
            'actions/checkout@v6',
            'actions/upload-artifact@v7',
            'actions/checkout@v4',
            'actions/upload-artifact@v4',
        )
        ->and(is_file($root.'/.github/workflows/media-quality.yml'))->toBeFalse();

    $releaseWorkflow = Yaml::parseFile($releaseWorkflowPath);

    expect($releaseWorkflow)->toBeArray()
        ->and($releaseWorkflow['on'])->not->toHaveKey('push')
        ->and($releaseWorkflow['on']['workflow_dispatch']['inputs']['version'] ?? null)
        ->toMatchArray([
            'required' => true,
            'type' => 'string',
        ])
        ->and($releaseWorkflow['concurrency']['group'] ?? null)->toBe('package-release')
        ->and($releaseWorkflow['concurrency']['cancel-in-progress'] ?? null)->toBeFalse()
        ->and($releaseWorkflowSource)->toBeString()->toContain(
            SUITE_CHECKOUT_ACTION,
            SUITE_UPLOAD_ARTIFACT_ACTION,
            SUITE_DOWNLOAD_ARTIFACT_ACTION,
        )->not->toContain(
            'actions/checkout@v6',
            'actions/upload-artifact@v7',
            'actions/download-artifact@v8',
            'actions/checkout@v4',
            'actions/upload-artifact@v4',
            'actions/download-artifact@v4',
        );
});

it('documents one discoverable push and automated release path', function (): void {
    $root = dirname(__DIR__, 2);
    $guide = file_get_contents($root.'/docs/releasing.md');
    $readme = file_get_contents($root.'/README.md');
    $contributing = file_get_contents($root.'/CONTRIBUTING.md');

    expect($guide)->toBeString()->toContain(
        '.github/workflows/package-quality.yml',
        '.github/workflows/package-release.yml',
        'git push origin main',
        'gh workflow run package-release.yml --ref main -f version=1.1.0',
        'Never run `git tag vX.Y.Z`',
        'A commit is not a release, a push is not a version',
        'Leave a blank `Unreleased` section for future work.',
        'unless the request also authorizes it.',
        'If a request is ambiguous about pushing or publishing',
        'Never silently leave a named release under',
        'Packagist will continue to show',
        'composer require --no-interaction --update-no-dev',
    )
        ->and($readme)->toBeString()->toContain(
            '[push, automated tagging, and release guide](docs/releasing.md)',
        )
        ->and($contributing)->toBeString()->toContain(
            '[push and automated release',
            'guide](docs/releasing.md)',
        );
});

it('pins every third-party action to an immutable commit', function (): void {
    $root = dirname(__DIR__, 2);

    foreach (['package-quality.yml', 'package-release.yml'] as $filename) {
        $workflow = Yaml::parseFile($root.'/.github/workflows/'.$filename);

        expect($workflow)->toBeArray();

        foreach ($workflow['jobs'] ?? [] as $job) {
            foreach ($job['steps'] ?? [] as $step) {
                $uses = $step['uses'] ?? null;

                if (! is_string($uses) || str_starts_with($uses, './')) {
                    continue;
                }

                expect($uses)->toMatch('/\A[^@\s]+@[0-9a-f]{40}\z/');
            }
        }
    }
});

it('pins Composer and retries dependency downloads without weakening TLS', function (): void {
    $root = dirname(__DIR__, 2);
    $retryScript = $root.'/tools/retry-composer.sh';
    $retrySource = file_get_contents($retryScript);

    expect($retryScript)->toBeFile()
        ->and($retrySource)->toBeString()->toContain(
            'COMPOSER_RETRY_DELAYS_SECONDS:-15 30 60 120 180',
            '"$composer_binary" "$@"',
        )->not->toContain(
            'disable-tls',
            'secure-http false',
            'source-fallback true',
        );

    foreach (['package-quality.yml', 'package-release.yml'] as $filename) {
        $workflow = Yaml::parseFile($root.'/.github/workflows/'.$filename);

        expect($workflow)->toBeArray();

        foreach ($workflow['jobs'] ?? [] as $job) {
            foreach ($job['steps'] ?? [] as $step) {
                if (($step['uses'] ?? null) === SUITE_SETUP_PHP_ACTION) {
                    expect($step['with']['tools'] ?? null)->toBe(SUITE_COMPOSER_TOOL);
                }
            }
        }
    }

    $qualitySource = file_get_contents($root.'/.github/workflows/package-quality.yml');
    $releaseSource = file_get_contents($root.'/.github/workflows/package-release.yml');

    expect($qualitySource)->toBeString()->toContain(
        'bash tools/retry-composer.sh install',
        'bash tools/retry-composer.sh update',
    )->not->toContain('for attempt in 1 2 3')
        ->and($releaseSource)->toBeString()->toContain(
            'bash tools/retry-composer.sh install',
            'bash "$GITHUB_WORKSPACE/tools/retry-composer.sh" create-project',
            'bash "$GITHUB_WORKSPACE/tools/retry-composer.sh" require',
            'bash "$GITHUB_WORKSPACE/tools/retry-composer.sh" audit',
        );
});

it('preserves Composer arguments and the final failure code across retries', function (): void {
    $root = dirname(__DIR__, 2);
    $temporaryDirectory = sys_get_temp_dir().'/nvl-composer-retry-'.bin2hex(random_bytes(8));
    $fakeComposer = $temporaryDirectory.'/composer';
    $counter = $temporaryDirectory.'/counter';
    $arguments = $temporaryDirectory.'/arguments';

    mkdir($temporaryDirectory, 0700, true);
    file_put_contents($fakeComposer, <<<'BASH'
#!/usr/bin/env bash
set -u
count=0
if [[ -f "$FAKE_COMPOSER_COUNTER" ]]; then
  read -r count < "$FAKE_COMPOSER_COUNTER"
fi
count="$(( count + 1 ))"
printf '%s' "$count" > "$FAKE_COMPOSER_COUNTER"
printf '%s\n' "$*" >> "$FAKE_COMPOSER_ARGUMENTS"
if [[ "$count" -lt "$FAKE_COMPOSER_SUCCEED_AT" ]]; then
  exit 60
fi
BASH);
    chmod($fakeComposer, 0700);

    try {
        $successful = new Process([
            'bash',
            $root.'/tools/retry-composer.sh',
            'install',
            '--no-interaction',
            '--prefer-dist',
        ]);
        $successful->setEnv([
            'COMPOSER_BINARY' => $fakeComposer,
            'COMPOSER_RETRY_DELAYS_SECONDS' => '0 0',
            'FAKE_COMPOSER_COUNTER' => $counter,
            'FAKE_COMPOSER_ARGUMENTS' => $arguments,
            'FAKE_COMPOSER_SUCCEED_AT' => '3',
        ]);
        $successful->run();

        expect($successful->isSuccessful())->toBeTrue()
            ->and(file_get_contents($counter))->toBe('3')
            ->and(file($arguments, FILE_IGNORE_NEW_LINES))->toBe([
                'install --no-interaction --prefer-dist',
                'install --no-interaction --prefer-dist',
                'install --no-interaction --prefer-dist',
            ])
            ->and($successful->getErrorOutput())->toContain(
                'Composer attempt 1/3 failed; retrying in 0s.',
                'Composer attempt 2/3 failed; retrying in 0s.',
            );

        file_put_contents($counter, '0');
        file_put_contents($arguments, '');

        $failed = new Process([
            'bash',
            $root.'/tools/retry-composer.sh',
            'install',
        ]);
        $failed->setEnv([
            'COMPOSER_BINARY' => $fakeComposer,
            'COMPOSER_RETRY_DELAYS_SECONDS' => '0',
            'FAKE_COMPOSER_COUNTER' => $counter,
            'FAKE_COMPOSER_ARGUMENTS' => $arguments,
            'FAKE_COMPOSER_SUCCEED_AT' => '3',
        ]);
        $failed->run();

        expect($failed->getExitCode())->toBe(60)
            ->and(file_get_contents($counter))->toBe('2')
            ->and($failed->getErrorOutput())->toContain(
                'Composer failed after 2 attempts.',
            );
    } finally {
        foreach ([$fakeComposer, $counter, $arguments] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($temporaryDirectory);
    }
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

it('exposes the root package quality runner through Composer', function (): void {
    $manifest = json_decode(
        file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['scripts']['package:quality'] ?? null)
        ->toBe('@php tools/run-package-quality.php');
});

it('tests the current stack Laravel 12 lowest and every supported database family', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $jobs = $workflow['jobs'] ?? [];
    $currentCommands = workflowCommands($jobs['current-tests'] ?? []);
    $lowestCommands = workflowCommands($jobs['laravel12-lowest'] ?? []);
    $postgresCommands = workflowCommands($jobs['postgresql'] ?? []);
    $mysqlCommands = workflowCommands($jobs['mysql-family'] ?? []);

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
            'translatable translations',
            'database="nvl_${package//-/_}_test_ci"',
            'composer test:integration',
        )
        ->not->toContain('mysql')
        ->and($jobs['mysql-family']['strategy']['matrix']['include'] ?? [])->toBe([
            [
                'name' => 'MySQL 8.4',
                'image' => 'mysql:8.4',
                'connection' => 'mysql',
                'health_command' => 'mysqladmin ping -h 127.0.0.1 -uroot -proot --silent',
            ],
            [
                'name' => 'MariaDB 12.3',
                'image' => 'mariadb:12.3',
                'connection' => 'mariadb',
                'health_command' => 'healthcheck.sh --connect --innodb_initialized',
            ],
        ])
        ->and($jobs['mysql-family']['services']['database']['options'] ?? null)
        ->toContain('--health-cmd="${{ matrix.health_command }}"')
        ->and($mysqlCommands)->toContain(
            'for package in activity auth comments content',
            'translatable translations',
            'database="nvl_${package//-/_}_test_ci"',
            'DB_DATABASE=nvl_package_test_integration composer test:integration',
        );
});

it('collects coverage only for packages with changed PHP source', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $coverage = $workflow['jobs']['changed-coverage'] ?? [];
    $commands = workflowCommands($coverage);
    $setup = collect($coverage['steps'] ?? [])->firstWhere('uses', SUITE_SETUP_PHP_ACTION);

    expect($commands)->toContain(
        'git diff --name-only "$base_sha...HEAD" -- packages/nvl',
        '$4 == "src" && $NF ~ /\.php$/',
        '[[ -f "packages/nvl/$package/composer.json" ]]',
        "jq -R -s -c 'split(\"\\n\") | map(select(length > 0))'",
        'while IFS= read -r package; do',
        '--test-directory="packages/nvl/$package/tests"',
        '--exclude-testsuite=infrastructure',
        'mail-notifications) minimum_line=88',
        'check-clover-coverage.php',
        'check-changed-clover-coverage.php',
    )
        ->and($setup)->toBeArray()
        ->and($setup['with']['coverage'] ?? null)->toBe('pcov')
        ->and($setup['with']['ini-values'] ?? null)
        ->toBe('pcov.directory=${{ github.workspace }}/packages/nvl')
        ->and($coverage)->not->toHaveKey('strategy');
});

it('publishes one clean suite tag only after all six routine gates pass', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/package-release.yml');

    expect($workflow)->toBeArray();

    $jobs = $workflow['jobs'] ?? [];
    $archive = $jobs['archive'] ?? [];
    $publish = $jobs['publish-release'] ?? [];
    $validateCommands = workflowCommands($jobs['validate'] ?? []);
    $archiveCommands = workflowCommands($archive);
    $publishCommands = workflowCommands($publish);
    $publishCheckout = collect($publish['steps'] ?? [])->firstWhere('uses', SUITE_CHECKOUT_ACTION);

    expect(array_keys($jobs))->toBe(['validate', 'checks', 'archive', 'publish-release'])
        ->and($validateCommands)->toContain(
            'refs/heads/$DEFAULT_BRANCH',
            'semver_pattern=',
        )
        ->and($jobs['checks']['uses'] ?? null)->toBe('./.github/workflows/package-quality.yml')
        ->and($jobs['checks']['needs'] ?? null)->toBe('validate')
        ->and($archive['needs'] ?? null)->toBe('checks')
        ->and($archiveCommands)->toContain(
            'COMPOSER_ROOT_VERSION="$PACKAGE_VERSION" composer archive',
            'test "$archive_count" -eq 1',
            'sort -u tools/release-archive-top-level.txt',
            'diff -u "$expected_top_level" "$actual_top_level"',
            '--root=/tmp/nvl-suite-artifact',
            '{type:"path",url:$url,options:{symlink:false,versions:{"nvl/laravel-suite":$version}}}',
            '"nvl/laravel-suite:$PACKAGE_VERSION"',
            'protected $fillable = ["host_reference"]',
            '"unlisted_extension" => "blocked"',
            'The archive does not preserve host principal fillable fields safely.',
            '.suite.publish_tags[], .packages[].publish_tags[]',
            'vendor/nvl/laravel-suite/resources/boost/skills/nvl-$package/SKILL.md',
            'compgen -G "database/migrations/*_$suffix"',
            "echo 'NVL_RELEASE_PUBLISHED_MIGRATIONS=true' >> .env",
            'rm -f database/database.sqlite',
            '.suite.commands // {}',
            'diff -u "$expected_doctors" "$available_doctors"',
            '"nvl/laravel-suite:1.0.1"',
            'export NVL_AUTH_INVITATIONS_ENABLED=true',
            'export NVL_AUTH_MAGIC_LINKS_ENABLED=true',
            'composer config --unset repositories.nvl-v1',
            'composer config repositories.nvl-candidate "$repository_config"',
            'php artisan nvl:auth:doctor --strict --format=json',
            'schema.nvl_auth_invitations.index.nvl_auth_invitations_context_hash_index',
            'schema.nvl_auth_challenges.index.nvl_auth_challenges_secondary_secret_hash_unique',
            'retry-composer.sh" audit --locked --no-interaction',
        )
        ->not->toContain(
            'for directory in packages/nvl/*; do',
            'build-public-composer-repository.php',
            'actions/deploy-pages',
        )
        ->and($publish['needs'] ?? null)->toBe('archive')
        ->and($publishCommands)->toContain(
            'git read-tree --empty',
            'git --work-tree="$release_tree" add --all --force -- .',
            'tree="$(git write-tree)"',
            'git commit-tree "$tree" -p "$GITHUB_SHA"',
            'git tag -a "$tag" "$release_commit"',
            'git push origin "refs/tags/$tag"',
            "--pattern 'nvl-laravel-suite-*.zip'",
            'gh release create',
        )
        ->and($publishCheckout)->toBeArray()
        ->and($publishCheckout['with']['fetch-depth'] ?? null)->toBe(0)
        ->and($publish['permissions']['contents'] ?? null)->toBe('write')
        ->and($publish['permissions']['pages'] ?? null)->toBeNull()
        ->and(json_encode($workflow, JSON_THROW_ON_ERROR))->toContain(
            'nvl-laravel-suite-v${{ inputs.version }}',
        )->not->toContain(
            'build-public-composer-repository.php',
            'actions/deploy-pages',
            'actions/upload-pages-artifact',
        );
});

it('adopts published Suite skills before running release doctors', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/package-release.yml');

    expect($workflow)->toBeArray();

    $consumerStep = collect($workflow['jobs']['archive']['steps'] ?? [])
        ->firstWhere('name', 'Install and exercise the suite archive');

    expect($consumerStep)->toBeArray();

    $script = $consumerStep['run'] ?? null;

    expect($script)->toBeString();

    $publicationPosition = mb_strpos($script, 'php artisan nvl:suite:skills:publish --format=json');
    $doctorLoopPosition = mb_strpos($script, 'while IFS= read -r doctor; do');

    expect($publicationPosition)->toBeInt()
        ->and($doctorLoopPosition)->toBeInt()
        ->and($publicationPosition)->toBeLessThan($doctorLoopPosition)
        ->and($script)->toContain(
            'php artisan nvl:suite:skills:doctor --strict --format=json',
            'test -f .agents/skills/.nvl-suite-skills.json',
            'if [[ "$doctor" == "nvl:suite:skills:doctor" ]]; then',
        );
});

it('prints release doctor output only when a doctor fails', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/package-release.yml');

    expect($workflow)->toBeArray();

    $consumerStep = collect($workflow['jobs']['archive']['steps'] ?? [])
        ->firstWhere('name', 'Install and exercise the suite archive');

    expect($consumerStep)->toBeArray();

    $script = $consumerStep['run'] ?? null;

    expect($script)->toBeString()->toContain(
        'doctor_output=/tmp/nvl-release-doctor-output.json',
        'php artisan "$doctor" --strict --format=json > "$doctor_output" 2>&1; then',
        'cat "$doctor_output"',
    );
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

it('rejects package names outside the canonical family before starting a process', function (): void {
    [$root, $catalog] = createPackageQualityFixture(['alpha']);
    $commands = [];
    $errors = '';

    try {
        $runner = packageQualityRunner(
            root: $root,
            catalog: $catalog,
            commands: $commands,
            error: $errors,
        );

        expect($runner->run(['missing']))->toBe(2)
            ->and($commands)->toBe([])
            ->and($errors)->toContain('Unknown package [missing]');
    } finally {
        removePackageQualityFixture($root);
    }
});

it('reports invalid package quality configuration without an uncaught exception', function (): void {
    [$root, $catalog] = createPackageQualityFixture(['alpha']);
    $commands = [];
    $errors = '';
    unset($catalog['quality']['packages']['alpha']['analysis_paths']);

    try {
        $runner = packageQualityRunner(
            root: $root,
            catalog: $catalog,
            commands: $commands,
            error: $errors,
        );

        expect($runner->run(['alpha']))->toBe(2)
            ->and($commands)->toBe([])
            ->and($errors)->toContain('Package [nvl/alpha] has no quality analysis paths.');
    } finally {
        removePackageQualityFixture($root);
    }
});

it('runs one package through root binaries with isolated analysis and test caches', function (): void {
    [$root, $catalog] = createPackageQualityFixture(['alpha']);
    $commands = [];

    try {
        $runner = packageQualityRunner(root: $root, catalog: $catalog, commands: $commands);

        expect($runner->run(['alpha']))->toBe(0)
            ->and($commands)->toHaveCount(3)
            ->and($commands[0])->toMatchArray([
                'workingDirectory' => $root,
                'command' => [
                    $root.'/vendor/bin/pint',
                    '--test',
                    '--format',
                    'agent',
                    $root.'/packages/nvl/alpha',
                ],
            ])
            ->and($commands[1]['workingDirectory'])->toBe($root)
            ->and($commands[1]['command'])->toBe([
                $root.'/vendor/bin/phpstan',
                'analyse',
                '--configuration='.$root.'/storage/framework/cache/package-quality/alpha/phpstan.neon',
                '--no-progress',
                '--error-format=table',
                '--memory-limit=3G',
                $root.'/packages/nvl/alpha/src',
            ])
            ->and($commands[2])->toMatchArray([
                'workingDirectory' => $root,
                'command' => [
                    $root.'/vendor/bin/pest',
                    '--test-directory=packages/nvl/alpha/tests',
                    '--configuration='.$root.'/packages/nvl/alpha/phpunit.xml.dist',
                    '--bootstrap='.$root.'/vendor/autoload.php',
                    '--cache-directory='.$root.'/storage/framework/cache/package-quality/alpha/phpunit',
                    '--compact',
                    $root.'/packages/nvl/alpha/tests',
                ],
            ]);

        $phpStanConfiguration = file_get_contents(
            $root.'/storage/framework/cache/package-quality/alpha/phpstan.neon',
        );

        expect($phpStanConfiguration)->toBeString()->toContain(
            $root.'/vendor/larastan/larastan/extension.neon',
            $root.'/vendor/nesbot/carbon/extension.neon',
            'tmpDir: '.$root.'/storage/framework/cache/package-quality/alpha/phpstan',
        );
    } finally {
        removePackageQualityFixture($root);
    }
});

it('runs multiple packages sequentially in the requested order', function (): void {
    [$root, $catalog] = createPackageQualityFixture(['alpha', 'beta']);
    $commands = [];

    try {
        $runner = packageQualityRunner(root: $root, catalog: $catalog, commands: $commands);

        expect($runner->run(['beta', 'alpha']))->toBe(0)
            ->and($commands)->toHaveCount(6)
            ->and(implode(' ', $commands[0]['command']))->toContain('/beta')
            ->and(implode(' ', $commands[1]['command']))->toContain('/beta')
            ->and(implode(' ', $commands[2]['command']))->toContain('/beta')
            ->and(implode(' ', $commands[3]['command']))->toContain('/alpha')
            ->and(implode(' ', $commands[4]['command']))->toContain('/alpha')
            ->and(implode(' ', $commands[5]['command']))->toContain('/alpha');
    } finally {
        removePackageQualityFixture($root);
    }
});

it('stops on the first failed quality step and returns its exit code', function (): void {
    [$root, $catalog] = createPackageQualityFixture(['alpha', 'beta']);
    $commands = [];

    try {
        $runner = packageQualityRunner(
            root: $root,
            catalog: $catalog,
            commands: $commands,
            exitCodes: [0, 9],
        );

        expect($runner->run(['alpha', 'beta']))->toBe(9)
            ->and($commands)->toHaveCount(2)
            ->and(implode(' ', $commands[1]['command']))->toContain('/alpha')
            ->not->toContain('/beta');
    } finally {
        removePackageQualityFixture($root);
    }
});

it('continues the release matrix after failures only when requested', function (): void {
    [$root, $catalog] = createPackageQualityFixture(['alpha', 'beta']);
    $commands = [];

    try {
        $runner = packageQualityRunner(
            root: $root,
            catalog: $catalog,
            commands: $commands,
            exitCodes: [0, 9, 0, 0, 0, 0],
        );

        expect($runner->run(['alpha', 'beta', '--continue-on-error']))->toBe(9)
            ->and($commands)->toHaveCount(6)
            ->and(implode(' ', $commands[5]['command']))->toContain('/beta');
    } finally {
        removePackageQualityFixture($root);
    }
});

it('emits value-free JSON without leaking absolute paths or process output', function (): void {
    [$root, $catalog] = createPackageQualityFixture(['alpha']);
    $commands = [];
    $output = '';

    try {
        $runner = packageQualityRunner(
            root: $root,
            catalog: $catalog,
            commands: $commands,
            output: $output,
            processOutput: 'sensitive=/absolute/consumer/config',
        );

        expect($runner->run(['alpha', '--format=json']))->toBe(0);

        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        expect($decoded)->toMatchArray([
            'schema' => 'nvl-package-quality-v1',
            'status' => 'passed',
        ])->and($decoded['packages'][0]['package'] ?? null)->toBe('alpha')
            ->and($decoded['packages'][0]['steps'] ?? [])->toHaveCount(3)
            ->and($output)->not->toContain($root, 'sensitive=', '/absolute/consumer/config');
    } finally {
        removePackageQualityFixture($root);
    }
});

it('skips Pest when a package has no test directory', function (): void {
    [$root, $catalog] = createPackageQualityFixture(['support'], ['support']);
    $commands = [];
    $output = '';

    try {
        $runner = packageQualityRunner(
            root: $root,
            catalog: $catalog,
            commands: $commands,
            output: $output,
        );

        expect($runner->run(['support']))->toBe(0)
            ->and($commands)->toHaveCount(2)
            ->and($output)->toContain('tests: skipped');
    } finally {
        removePackageQualityFixture($root);
    }
});

it('preserves paths containing spaces as individual process arguments', function (): void {
    [$root, $catalog] = createPackageQualityFixture(['alpha'], [], 'nvl suite quality');
    $commands = [];

    try {
        $runner = packageQualityRunner(root: $root, catalog: $catalog, commands: $commands);

        expect($runner->run(['alpha']))->toBe(0)
            ->and($commands[0]['command'][0])->toBe($root.'/vendor/bin/pint')
            ->and($commands[0]['command'][4])->toBe($root.'/packages/nvl/alpha')
            ->and($commands[2]['command'][6])->toBe($root.'/packages/nvl/alpha/tests');
    } finally {
        removePackageQualityFixture($root);
    }
});

it('leaves unreleased migrations in mutable PHPStan analysis', function (): void {
    [$root, $catalog] = createPackageQualityFixture(['alpha']);
    $migration = $root.'/packages/nvl/alpha/database/migrations/2099_01_01_000000_create_alpha_table.php';
    $commands = [];

    try {
        (new Filesystem)->dumpFile(
            $migration,
            "<?php\n\ndeclare(strict_types=1);\n",
        );
        $runner = packageQualityRunner(root: $root, catalog: $catalog, commands: $commands);

        expect($runner->run(['alpha']))->toBe(0)
            ->and($commands[1]['command'])->toContain($migration);
    } finally {
        removePackageQualityFixture($root);
    }
});

it('uses one deep-map and atomic-list merger in every config-bearing provider', function (): void {
    $catalog = new SuiteModuleCatalog(new Repository);
    $configBearingModules = collect($catalog->modules())
        ->filter(static fn (array $definition): bool => $definition['configuration'] !== null);

    expect($configBearingModules)->toHaveCount(17);

    foreach ($configBearingModules as $module => $definition) {
        $providerPath = (new ReflectionClass($definition['provider']))->getFileName();

        expect($providerPath)->toBeString()->toBeFile();

        $source = (string) file_get_contents($providerPath);

        expect($source)
            ->toContain('use Nvl\Support\Traits\MergesPackageConfiguration;')
            ->toContain('use MergesPackageConfiguration;')
            ->toContain('mergePackageConfiguration(')
            ->not->toContain(
                'replaceConfigRecursivelyFrom(',
                'mergeConfigFrom(',
                'mergeConfigurationValues(',
                'mergeConfigurationRecursively(',
            );
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

/**
 * Create a filesystem fixture and canonical catalog for package-quality tests.
 *
 * @param  list<string>  $packages
 * @param  list<string>  $packagesWithoutTests
 * @return array{0: string, 1: array<string, mixed>}
 */
function createPackageQualityFixture(
    array $packages,
    array $packagesWithoutTests = [],
    string $prefix = 'nvl-package-quality',
): array {
    $filesystem = new Filesystem;
    $root = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($root.'/tools');
    $qualityPackages = [];
    $contractPackages = [];

    foreach ($packages as $package) {
        $packageDirectory = $root.'/packages/nvl/'.$package;
        $filesystem->mkdir($packageDirectory.'/src');
        $qualityPackages[$package] = [
            'analysis_paths' => ['src'],
            'migration_tests' => [],
        ];
        $contractPackages[$package] = ['migrations' => []];

        if (! in_array($package, $packagesWithoutTests, true)) {
            $filesystem->mkdir($packageDirectory.'/tests');
            $filesystem->dumpFile($packageDirectory.'/phpunit.xml.dist', '<phpunit/>'.PHP_EOL);
        }
    }

    $filesystem->dumpFile(
        $root.'/tools/package-contracts.json',
        json_encode(
            ['packages' => $contractPackages],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL,
    );

    return [
        $root,
        [
            'packages' => $packages,
            'database_tested' => [],
            'quality' => [
                'released_migrations_contract' => 'tools/package-contracts.json',
                'packages' => $qualityPackages,
            ],
        ],
    ];
}

/**
 * Build a package-quality runner with a deterministic fake process executor.
 *
 * @param  array<string, mixed>  $catalog
 * @param  list<array{command: list<string>, workingDirectory: string}>  $commands
 * @param  list<int>  $exitCodes
 */
function packageQualityRunner(
    string $root,
    array $catalog,
    array &$commands,
    array $exitCodes = [],
    string &$output = '',
    string &$error = '',
    string $processOutput = '',
): object {
    $library = dirname(__DIR__, 2).'/tools/package-quality-runner.php';

    expect($library)->toBeFile();

    require_once $library;

    $execute = static function (
        array $command,
        string $workingDirectory,
        Closure $stream,
    ) use (&$commands, &$exitCodes, $processOutput): int {
        $commands[] = [
            'command' => $command,
            'workingDirectory' => $workingDirectory,
        ];

        if ($processOutput !== '') {
            $stream(Process::OUT, $processOutput);
        }

        return array_shift($exitCodes) ?? 0;
    };

    return new PackageQualityRunner(
        root: $root,
        catalog: $catalog,
        execute: $execute,
        writeOutput: static function (string $message) use (&$output): void {
            $output .= $message;
        },
        writeError: static function (string $message) use (&$error): void {
            $error .= $message;
        },
    );
}

/**
 * Remove a package-quality filesystem fixture.
 */
function removePackageQualityFixture(string $root): void
{
    (new Filesystem)->remove($root);
}
