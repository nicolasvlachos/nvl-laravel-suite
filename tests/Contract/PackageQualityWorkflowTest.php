<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('keeps both coverage matrices complete and their Auth infrastructure exclusion effective', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');
    $catalog = require $root.'/tools/package-family.php';
    $expectedPackages = $catalog['packages'];

    expect($workflow)->toBeArray();

    sort($expectedPackages);

    foreach (['line-coverage', 'branch-coverage'] as $jobName) {
        $job = $workflow['jobs'][$jobName] ?? null;

        expect($job)->toBeArray();

        $packages = $job['strategy']['matrix']['package'] ?? [];
        $coverageStep = collect($job['steps'] ?? [])->firstWhere(
            'name',
            $jobName === 'line-coverage'
                ? 'Enforce line threshold'
                : 'Enforce line and branch thresholds',
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
            )
            ->and($lines)->not->toContain(
                '"packages/nvl/${{ matrix.package }}/tests"',
            );

        if ($jobName === 'line-coverage') {
            expect($command)->toContain(
                'check-changed-clover-coverage.php',
                'PULL_REQUEST_BASE_SHA',
            );
        } else {
            expect($command)->toContain(
                '--path-coverage',
                '--coverage-html=',
            );
        }
    }
});

it('uses representative compatibility boundaries and event-scoped rich coverage', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $jobs = $workflow['jobs'] ?? [];
    $compatibility = $jobs['package-matrix']['strategy']['matrix']['include'] ?? null;
    $lineCoverage = $jobs['line-coverage'] ?? null;
    $branchCoverage = $jobs['branch-coverage'] ?? null;
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
        ->and($lineCoverage)->toBeArray()
        ->and($lineCoverage['if'] ?? null)->toContain(
            "github.event_name == 'pull_request'",
            "github.event_name == 'push'",
        )
        ->and($branchCoverage)->toBeArray()
        ->and($branchCoverage['if'] ?? null)->toContain(
            "github.event_name == 'schedule'",
            "startsWith(github.ref, 'refs/tags/v')",
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
            'branch-coverage',
            'standalone-consumers',
            'auth-consumer-profiles',
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

it('keeps the Auth clean-consumer profile and production proofs complete', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $job = $workflow['jobs']['standalone-consumers'] ?? null;
    $profileJob = $workflow['jobs']['auth-consumer-profiles'] ?? null;
    $step = collect($job['steps'] ?? [])->firstWhere(
        'name',
        'Create clean consumer',
    );
    $profileStep = collect($profileJob['steps'] ?? [])->firstWhere(
        'name',
        'Create and exercise clean Auth profile consumers',
    );
    $command = is_array($step) ? ($step['run'] ?? null) : null;
    $profileCommand = is_array($profileStep) ? ($profileStep['run'] ?? null) : null;

    expect($job)->toBeArray()
        ->and($step)->toBeArray()
        ->and($command)->toBeString()
        ->toContain(
            '--no-dev',
            "'laravel/sanctum:^4.3'",
            'vendor:publish --tag=sanctum-migrations --force',
            'vendor:publish --tag=permission-migrations --force',
            'tools/fixtures/auth-production-consumer/app/.',
            'tools/fixtures/auth-production-consumer/database/.',
            'tools/fixtures/auth-production-consumer/routes/.',
            'nvl:auth:doctor --profile=production --strict --format=json',
            'php artisan auth-consumer:maintenance --format=json',
            'php artisan config:cache',
            'php artisan route:cache',
            'php artisan auth-consumer:smoke --format=json',
            'php artisan migrate:rollback --force --step=999',
        );

    expect($profileJob)->toBeArray()
        ->and($profileJob['strategy']['matrix']['laravel'] ?? null)->toBe(['12', '13'])
        ->and($profileStep)->toBeArray()
        ->and($profileCommand)->toBeString()
        ->toContain(
            '--no-dev',
            '--prefer-lowest',
            "'nvl/auth:@dev'",
            "'laravel/sanctum:^4.3'",
            'browser-baseline',
            'selective-feature',
            'all-enabled',
            'ingress-disabled',
            'tools/run-auth-production-consumer.sh',
        );

    expect(substr_count(
        $command,
        'php artisan auth-consumer:smoke --format=json',
    ))->toBeGreaterThanOrEqual(3);

    expect(substr_count(
        $command,
        'php artisan auth-consumer:maintenance --format=json',
    ))->toBe(2);

    $firstMaintenance = strpos(
        $command,
        'php artisan auth-consumer:maintenance --format=json',
    );
    $firstProductionDoctor = strpos(
        $command,
        'php artisan nvl:auth:doctor --profile=production --strict --format=json',
    );
    $rollback = strpos(
        $command,
        'php artisan migrate:rollback --force --step=999',
    );

    expect($firstMaintenance)->toBeInt()
        ->and($firstProductionDoctor)->toBeInt()
        ->and($rollback)->toBeInt()
        ->and($firstMaintenance)->toBeLessThan($firstProductionDoctor)
        ->and($firstProductionDoctor)->toBeLessThan($rollback);

    $postRollbackMaintenance = strpos(
        $command,
        'php artisan auth-consumer:maintenance --format=json',
        $rollback,
    );
    $postRollbackDoctor = strpos(
        $command,
        'php artisan nvl:auth:doctor --profile=production --strict --format=json',
        $rollback,
    );

    expect($postRollbackMaintenance)->toBeInt()
        ->and($postRollbackDoctor)->toBeInt()
        ->and($rollback)->toBeLessThan($postRollbackMaintenance)
        ->and($postRollbackMaintenance)->toBeLessThan($postRollbackDoctor);

    foreach ([
        'app/Auth/ApiTokens/ApiTokenApiProbe.php',
        'app/Auth/ApiTokens/ApplicationApiTokenAbilityProvider.php',
        'app/Auth/ApiTokens/ApplicationApiTokenEligibility.php',
        'app/Auth/ApiTokens/OwnProfileResult.php',
        'app/Auth/Clients/ApplicationAuthClientManagementAccess.php',
        'app/Auth/Clients/AuthClientApiProbe.php',
        'app/Auth/Credentials/ApplicationPasswordUpdater.php',
        'app/Auth/Flows/AuthenticationApiProbe.php',
        'app/Auth/Invitations/InvitationWorkflowProbe.php',
        'app/Auth/Management/ManagementApiProbe.php',
        'app/Auth/Session/AuthenticateConsumerCredentialsAction.php',
        'app/Console/Commands/AuthConsumerMaintenanceCommand.php',
        'app/Http/Controllers/ApiProfileController.php',
        'app/Http/Controllers/AuthConsumerSessionController.php',
        'app/Http/Controllers/CsrfTokenController.php',
        'app/Http/Requests/AuthConsumerSessionRequest.php',
        'app/Providers/AuthProductionServiceProvider.php',
        'config/auth-consumer.php',
        'database/migrations/2026_08_01_000001_create_auth_consumer_password_operations_table.php',
        'routes/auth-management.php',
    ] as $path) {
        expect($root.'/tools/fixtures/auth-production-consumer/'.$path)
            ->toBeFile();
    }

    $fixtureRoot = $root.'/tools/fixtures/auth-production-consumer';
    $provider = file_get_contents($fixtureRoot.'/app/Providers/AuthProductionServiceProvider.php');
    $maintenance = file_get_contents(
        $fixtureRoot.'/app/Console/Commands/AuthConsumerMaintenanceCommand.php',
    );
    $user = file_get_contents($fixtureRoot.'/app/Models/User.php');
    $routes = file_get_contents($fixtureRoot.'/routes/auth-management.php');
    $smoke = file_get_contents($fixtureRoot.'/app/Console/Commands/AuthConsumerSmokeCommand.php');
    $http = file_get_contents($fixtureRoot.'/app/Auth/Http/SyntheticHttpProbe.php');
    $apiTokens = file_get_contents($fixtureRoot.'/app/Auth/ApiTokens/ApiTokenApiProbe.php');
    $clients = file_get_contents($fixtureRoot.'/app/Auth/Clients/AuthClientApiProbe.php');
    $profileConfig = file_get_contents($fixtureRoot.'/config/auth-consumer.php');
    $profileRunner = file_get_contents($root.'/tools/run-auth-production-consumer.sh');

    expect($provider)->toBeString();

    $featureBlockMatched = preg_match(
        '/FEATURE_HANDLES\s*=\s*\[(?<features>.*?)\];/s',
        $provider,
        $featureBlock,
    );
    $featureHandlesMatched = preg_match_all(
        "/^\\s+'([a-z_]+)',\\s*$/m",
        is_string($featureBlock['features'] ?? null)
            ? $featureBlock['features']
            : '',
        $featureHandleMatches,
    );

    expect($featureBlockMatched)->toBe(1)
        ->and($featureHandlesMatched)->toBe(20)
        ->and($featureHandleMatches[1] ?? null)->toBe([
            'authentication',
            'password',
            'magic_links',
            'security_codes',
            'contacts',
            'invitations',
            'totp',
            'passkeys',
            'recovery_codes',
            'account_recovery',
            'social_identities',
            'devices',
            'cross_device',
            'sessions',
            'clients',
            'api_tokens',
            'rbac',
            'security_notifications',
            'principal_management',
            'security_event_management',
        ]);

    expect($provider)->toContain(
        'AuthClientManagementAccess::class',
        'ApiTokenAbilityProvider::class',
        'ApiTokenEligibility::class',
        'SanctumApiTokenDriver::class',
        'singleton(SyntheticHttpProbe::class)',
        "RateLimiter::for('api'",
        "Config::set('nvl-auth.enabled', \$profile !== 'ingress-disabled')",
        'AuthConsumerMaintenanceCommand::class',
        'in_array($feature, $enabledFeatures, true)',
        'Config::set("nvl-auth.features.{$feature}.mode", \'enabled\')',
        '"nvl-auth.features.{$feature}.routes.{$surface}.enabled"',
        'nvl-auth.features.authentication.services.principal_resolver',
        'nvl-auth.features.password.services.verifier',
        'nvl-auth.features.sessions.services.driver',
        'nvl-auth.features.api_tokens.services.driver',
        'nvl-auth.features.social_identities.services.acquirer',
        'nvl-auth.features.passkeys.services.ceremony',
        'nvl-auth.features.invitations.settings.purpose_handlers',
        'nvl-auth.features.rbac.settings.permission_catalog_providers',
        "'browser-baseline'",
        "'selective-feature'",
        "'all-enabled'",
        "'ingress-disabled'",
        "'clients'",
        "'api_tokens'",
    )->not->toContain(
        "array_keys((array) Config::get('nvl-auth.features'",
        'nvl-auth.feature_routes',
    )
        ->and($maintenance)->toBeString()->toContain(
            'AuthMaintenanceTask::cases()',
            '$this->maintenance->run($task)',
        )
        ->and($user)->toBeString()->toContain(
            'use HasApiTokens;',
            'protected $fillable',
            "'name'",
            "'email'",
            "'password'",
            'protected $hidden',
            "'remember_token'",
        )->not->toContain(
            '#[Fillable',
            '#[Hidden',
        )
        ->and($routes)->toBeString()->toContain(
            'CsrfTokenController::class',
            "'auth:sanctum'",
            "'throttle:api'",
            'ValidateManagedAccessToken::class',
            "CheckAbilities::class.':profile:read'",
            "'auth:sanctum',\n        'throttle:api',\n        ValidateManagedAccessToken::class,\n        CheckAbilities::class.':profile:read'",
            "->middleware(['web', 'throttle:api'])",
            "'can:manage-authentication',\n        'throttle:nvl-auth-management'",
        )
        ->and($smoke)->toBeString()->toContain(
            "'ready' => \$packageRoutes === 89",
            'count($authTables) === 34 && $installedTables === 34',
            "'registered_clients'",
            "'api_tokens'",
        )
        ->and($http)->toBeString()->toContain(
            'public function useBrowser(',
            'public function dispatchStateless(',
            "\$this->config->set('sanctum.guard', []);",
            '$this->captureCookies($response);',
        )
        ->and($apiTokens)->toBeString()->toContain(
            'whereKey($authentication->sessionId)',
            "'/rotate'",
            "'/api/v1/auth/api-tokens'",
            'dispatchStateless(',
        )
        ->and($clients)->toBeString()->toContain(
            "'/api/v1/auth/management/clients?surfaceKey='",
            "'/status'",
            'public function cleanup(',
            "'clients.destroy'",
        )
        ->and($profileConfig)->toBeString()->toContain(
            "env('AUTH_CONSUMER_PROFILE', 'all-enabled')",
        )
        ->and($profileRunner)->toBeString()->toContain(
            'expected_features=\'["authentication","password","magic_links","security_codes","contacts","totp","devices","sessions","security_notifications"]\'',
            'php -d error_reporting=8191 artisan "$@"',
            'php_artisan config:cache',
            'php_artisan route:cache',
            'php_artisan migrate:rollback --force --step=999',
            'php_artisan schedule:list',
            'php_artisan schedule:run --no-interaction',
            'php_artisan queue:work database',
            'php_artisan nvl:auth:doctor --profile=production --strict --format=json',
            'php_artisan nvl:auth:features --format=json',
            'php_artisan auth-consumer:smoke --format=json',
            'registered_route_count',
            'effective_route_count',
        );
});
