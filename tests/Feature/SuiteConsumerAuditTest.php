<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Nvl\Auth\Contracts\AuthManagementAccess;
use Nvl\Auth\Http\Controllers\Management\UserController;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Suite\Services\ConsumerAudit\ComposerSourceRootLocator;
use Nvl\Suite\Services\ConsumerAudit\PhpConsumerBoundaryScanner;
use Nvl\Suite\Services\ConsumerAudit\PhpImportMap;
use Nvl\Suite\Services\ConsumerAudit\SuiteRuntimeConsumerScanner;
use Nvl\Suite\Services\SuiteConfigurationInspector;
use Nvl\Suite\Services\SuiteConsumerAuditor;
use Nvl\Suite\Services\SuiteSkillManager;
use Nvl\Suite\Support\ConsumerAuditFinding;
use Nvl\Suite\Support\SuiteModuleCatalog;
use Symfony\Component\Process\Process;

/**
 * @return list<ConsumerAuditFinding>
 */
function consumerAuditFixtureFindings(): array
{
    static $findings;

    return $findings ??= resolve(SuiteConsumerAuditor::class)->audit(
        base_path('tests/Fixtures/consumer-audit'),
    );
}

it('runs runtime checks only for the booted consumer application', function (): void {
    $auditor = resolve(SuiteConsumerAuditor::class);

    expect($auditor->runtimeChecked(base_path()))->toBeTrue()
        ->and($auditor->runtimeChecked(base_path('tests/Fixtures/consumer-audit')))->toBeFalse();
});

it('reports package model access with consumer source context', function (): void {
    $findings = consumerAuditFixtureFindings();

    expect($findings)
        ->each->toBeInstanceOf(ConsumerAuditFinding::class);

    $finding = collect($findings)->first(
        fn (ConsumerAuditFinding $finding): bool => $finding->code === 'consumer.package_model_query'
            && $finding->path === 'app/UnsafeRoleReader.php',
    );

    expect($finding)
        ->not->toBeNull()
        ->package->toBe('auth')
        ->severity->toBe('error')
        ->path->toBe('app/UnsafeRoleReader.php')
        ->symbol->toBe('Nvl\\Auth\\Models\\Role::query');
});

it('classifies every unallowlisted package model query as an error', function (): void {
    $findings = collect(consumerAuditFixtureFindings());

    expect($findings
        ->where('path', 'app/UnsafeDynamicRoleReader.php')
        ->where('code', 'consumer.package_model_query')
        ->where('severity', 'error')
        ->pluck('symbol')
        ->all())->toBe(['Nvl\\Auth\\Models\\Role::whereName'])
        ->and($findings
            ->where('path', 'app/UnsafeBoundRoleReader.php')
            ->where('code', 'consumer.package_model_query')
            ->where('severity', 'error')
            ->pluck('symbol')
            ->all())->toBe([
                'Nvl\\Auth\\Models\\Role::permissions',
                'Nvl\\Auth\\Models\\Role::whereName',
            ])
        ->and($findings
            ->where('path', 'app/UnsafeActionReturnedRoleReader.php')
            ->where('code', 'consumer.package_model_query')
            ->where('severity', 'error')
            ->pluck('symbol')
            ->all())->toBe(['Nvl\\Auth\\Models\\Role::permissions']);
});

it('matches the reviewer probe for lazy closure and property Action query dataflow', function (): void {
    $findings = collect(resolve(SuiteConsumerAuditor::class)->audit(
        base_path('tests/Fixtures/consumer-audit-reviewer-probe'),
    ));

    expect($findings
        ->where('path', 'app/ConsumerBoundaryProbe.php')
        ->where('code', 'consumer.package_model_query')
        ->where('severity', 'error')
        ->pluck('symbol')
        ->all())->toBe([
            'Nvl\\Auth\\Models\\Role::permissions',
            'Nvl\\Auth\\Models\\Role::permissions',
            'Nvl\\Auth\\Models\\Role::permissions',
        ]);
});

it('reports newly instantiated and quiet package model writes', function (): void {
    $symbols = collect(consumerAuditFixtureFindings())
        ->where('path', 'app/UnsafeQuietRoleWriter.php')
        ->where('code', 'consumer.package_model_write')
        ->pluck('symbol')
        ->sort()
        ->values()
        ->all();

    expect($symbols)->toBe([
        'Nvl\\Auth\\Models\\Role::createQuietly',
        'Nvl\\Auth\\Models\\Role::deleteQuietly',
        'Nvl\\Auth\\Models\\Role::pushQuietly',
        'Nvl\\Auth\\Models\\Role::saveQuietly',
        'Nvl\\Auth\\Models\\Role::touchQuietly',
        'Nvl\\Auth\\Models\\Role::updateQuietly',
        'Nvl\\Pages\\Models\\Page::forceDeleteQuietly',
        'Nvl\\Pages\\Models\\Page::restoreQuietly',
    ]);
});

it('keeps adoption reads advisory while failing raw package table writes', function (): void {
    $findings = collect(consumerAuditFixtureFindings());
    $read = $findings
        ->where('path', 'database/migrations/2026_01_02_000000_reference_auth_roles_table.php')
        ->first();
    $writes = $findings
        ->where('path', 'database/migrations/2026_01_05_000000_write_auth_table_for_adoption.php')
        ->where('code', 'consumer.package_table_reference')
        ->where('severity', 'error')
        ->pluck('symbol')
        ->sort()
        ->values()
        ->all();

    expect($read)
        ->not->toBeNull()
        ->code->toBe('consumer.package_migration_reference')
        ->severity->toBe('warning')
        ->and($writes)->toBe([
            'nvl_auth_roles::delete',
            'nvl_auth_roles::insert',
            'nvl_auth_roles::update',
        ]);
});

it('preserves the explicit allowed consumer model classifications', function (): void {
    $findings = collect(consumerAuditFixtureFindings());

    foreach ([
        'app/AllowedModelBoundaries.php',
        'app/AllowedConsumerTraits.php',
        'app/AllowedOwner.php',
    ] as $path) {
        expect($findings->where('path', $path))->toBeEmpty();
    }

    expect($findings
        ->where('path', 'database/migrations/2026_01_03_000000_read_auth_roles_for_adoption.php')
        ->whereIn('code', [
            'consumer.package_model_query',
            'consumer.package_model_write',
        ]))->toBeEmpty();

    expect($findings
        ->where('path', 'database/migrations/2026_01_04_000000_write_auth_roles_for_adoption.php')
        ->where('code', 'consumer.package_model_write')
        ->pluck('symbol')
        ->all())->toBe(['Nvl\\Auth\\Models\\Role::update']);
});

it('allows package model access from documented owner model traits', function (): void {
    $findings = consumerAuditFixtureFindings();

    $codes = collect($findings)
        ->filter(fn (ConsumerAuditFinding $finding): bool => $finding->path === 'app/AllowedOwner.php')
        ->pluck('code')
        ->all();

    expect($codes)->toBeEmpty();
});

it('reports runtime and adoption risks with stable finding codes', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-consumer-audit-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->ensureDirectoryExists($workspace);
    $modules = array_fill_keys(
        array_keys(resolve(SuiteModuleCatalog::class)->modules()),
        false,
    );
    $modules['auth'] = true;
    $modules['media'] = true;
    $configuration = new Repository([
        'nvl-suite' => [
            'modules' => $modules,
            'consumer_audit' => ['authentication_middleware' => ['auth']],
        ],
        'media' => ['multipart' => ['enabled' => true]],
    ]);
    $catalog = new SuiteModuleCatalog($configuration);
    $application = resolve(Application::class);
    $authBinding = $application->getBindings()[AuthManagementAccess::class] ?? null;
    $application->offsetUnset(AuthManagementAccess::class);
    $schedule = new Schedule;
    $inspector = new SuiteConfigurationInspector(
        $application,
        $configuration,
        $schedule,
        $catalog,
    );
    $router = new Router(new Dispatcher($application), $application);
    $router->get('unsafe-management', [UserController::class, 'index'])
        ->name('consumer.test.unsafe-management');
    $console = Mockery::mock(Kernel::class);
    $console->shouldReceive('call')->once()->andReturn(1);
    $skills = new SuiteSkillManager(
        filesystem: $filesystem,
        catalog: $catalog,
        suiteRoot: dirname(__DIR__, 2),
        applicationRoot: $workspace,
        suiteVersion: '1.0.0-test',
    );

    try {
        $findings = (new SuiteRuntimeConsumerScanner(
            $inspector,
            $router,
            $console,
            $skills,
            $catalog,
            $configuration,
        ))->scan();

        $codes = collect($findings)->pluck('code');
        $missingContracts = collect($findings)
            ->where('code', 'consumer.missing_auth_binding')
            ->pluck('symbol');

        expect($codes)->toContain(
            'consumer.missing_auth_binding',
            'consumer.unsafe_management_route',
            'consumer.missing_required_schedule',
            'consumer.stale_generated_contract',
            'consumer.stale_suite_skill',
        )->and($missingContracts)
            ->toContain(AuthManagementAccess::class)
            ->not->toContain(
                MediaContentScanner::class,
                MultipartUploadGateway::class,
            );
    } finally {
        if (is_array($authBinding)) {
            $application->bind(
                AuthManagementAccess::class,
                $authBinding['concrete'],
                $authBinding['shared'],
            );
        }

        $filesystem->deleteDirectory($workspace);
    }
});

it('uses package Doctor authorization checks for authenticated management routes', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-consumer-audit-doctor-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->ensureDirectoryExists($workspace);
    $modules = array_fill_keys(
        array_keys(resolve(SuiteModuleCatalog::class)->modules()),
        false,
    );
    $modules['auth'] = true;
    $configuration = new Repository([
        'nvl-suite' => [
            'modules' => $modules,
            'consumer_audit' => ['authentication_middleware' => ['auth']],
        ],
    ]);
    $catalog = new SuiteModuleCatalog($configuration);
    $application = resolve(Application::class);
    $inspector = new SuiteConfigurationInspector(
        $application,
        $configuration,
        new Schedule,
        $catalog,
    );
    $router = new Router(new Dispatcher($application), $application);
    $router->get('secured-management', [UserController::class, 'index'])
        ->middleware('auth:sanctum')
        ->name('consumer.test.secured-management');
    $console = Mockery::mock(Kernel::class);
    $console->shouldReceive('call')->twice()->andReturnUsing(
        static function (string $command, array $_parameters, mixed $output): int {
            if ($command === 'nvl:auth:doctor') {
                $output->writeln((string) json_encode([
                    'checks' => [[
                        'name' => 'authorization.nvl-auth.users.viewAny',
                        'passed' => false,
                    ]],
                ], JSON_THROW_ON_ERROR));

                return 1;
            }

            return 0;
        },
    );
    $skills = new SuiteSkillManager(
        filesystem: $filesystem,
        catalog: $catalog,
        suiteRoot: dirname(__DIR__, 2),
        applicationRoot: $workspace,
        suiteVersion: '1.0.0-test',
    );

    try {
        $findings = (new SuiteRuntimeConsumerScanner(
            $inspector,
            $router,
            $console,
            $skills,
            $catalog,
            $configuration,
        ))->scan();

        expect(collect($findings)
            ->where('code', 'consumer.unsafe_management_route')
            ->pluck('symbol'))
            ->toContain('auth:management-authorization')
            ->not->toContain('consumer.test.secured-management');
    } finally {
        $filesystem->deleteDirectory($workspace);
    }
});

it('reports duplicate enabled package migrations', function (): void {
    $findings = consumerAuditFixtureFindings();

    $codes = collect($findings)->pluck('code');

    expect($codes)->toContain('consumer.duplicate_package_migration');
});

it('discovers Composer, Modules, and migration roots while excluding development sources', function (): void {
    $findings = collect(consumerAuditFixtureFindings());

    expect($findings->where('path', 'Modules/Inventory/UnsafePageReader.php'))
        ->toHaveCount(1)
        ->and($findings->where('path', 'legacy/LegacyTemplateReader.php'))
        ->toHaveCount(1)
        ->and($findings->where('path', 'tests/IgnoredRoleReader.php'))
        ->toBeEmpty()
        ->and($findings->where('path', 'package-src/InternalRoleReader.php'))
        ->toBeEmpty()
        ->and($findings->where('path', 'database/migrations/2026_01_02_000000_reference_auth_roles_table.php')
            ->first()?->code)
        ->toBe('consumer.package_migration_reference');
});

it('distinguishes static model writes and raw table access from allowed references', function (): void {
    $findings = collect(consumerAuditFixtureFindings());
    $write = $findings->firstWhere('path', 'app/UnsafeRoleWriter.php');
    $writeSymbols = $findings
        ->where('path', 'app/UnsafeRoleWriter.php')
        ->where('code', 'consumer.package_model_write')
        ->pluck('symbol');
    $table = $findings->firstWhere('path', 'app/RawTableReference.php');

    expect($write)
        ->not->toBeNull()
        ->code->toBe('consumer.package_model_write')
        ->severity->toBe('error')
        ->line->toBe(14)
        ->symbol->toBe('Nvl\\Auth\\Models\\Role::updateOrCreate')
        ->and($writeSymbols)->toContain(
            'Nvl\\Auth\\Models\\Role::save',
            'Nvl\\Auth\\Models\\Role::update',
        )
        ->and($table)
        ->not->toBeNull()
        ->code->toBe('consumer.package_table_reference')
        ->and($findings->where('path', 'app/AllowedReferences.php'))
        ->toBeEmpty();
});

it('resolves grouped aliases and fully qualified class references deterministically', function (): void {
    $imports = PhpImportMap::fromSource(<<<'PHP'
        <?php

        namespace Consumer\Feature;

        use Nvl\Auth\Models\{Role as ManagedRole, User};
        use Nvl\Pages\Models\Page;
        PHP);

    expect($imports->resolve('ManagedRole'))->toBe('Nvl\\Auth\\Models\\Role')
        ->and($imports->resolve('User'))->toBe('Nvl\\Auth\\Models\\User')
        ->and($imports->resolve('Page'))->toBe('Nvl\\Pages\\Models\\Page')
        ->and($imports->resolve('LocalClass'))->toBe('Consumer\\Feature\\LocalClass')
        ->and($imports->resolve('\\Nvl\\Media\\Models\\Media'))->toBe('Nvl\\Media\\Models\\Media');
});

it('extends source discovery with configured exact paths', function (): void {
    $configuration = resolve(Repository::class);
    $original = $configuration->get('nvl-suite.consumer_audit.paths');

    try {
        $configuration->set('nvl-suite.consumer_audit.paths', ['domain']);
        $findings = resolve(SuiteConsumerAuditor::class)->audit(
            base_path('tests/Fixtures/consumer-audit'),
        );

        expect(collect($findings)->firstWhere('path', 'domain/ConfiguredCommentReader.php'))
            ->not->toBeNull()
            ->package->toBe('comments');
    } finally {
        $configuration->set('nvl-suite.consumer_audit.paths', $original);
    }
});

it('recognizes configured package-owned table names', function (): void {
    $configuration = resolve(Repository::class);
    $original = $configuration->get('comments.tables.comments');

    try {
        $configuration->set('comments.tables.comments', 'tenant_comments');
        $findings = resolve(SuiteConsumerAuditor::class)->audit(
            base_path('tests/Fixtures/consumer-audit'),
        );
        $finding = collect($findings)
            ->firstWhere('path', 'app/ConfiguredTableReference.php');

        expect($finding)
            ->not->toBeNull()
            ->code->toBe('consumer.package_table_reference')
            ->package->toBe('comments')
            ->symbol->toBe('tenant_comments');
    } finally {
        $configuration->set('comments.tables.comments', $original);
    }
});

it('does not require configuration for explicitly disabled package tables', function (): void {
    $configuration = resolve(Repository::class);
    $originalModules = $configuration->get('nvl-suite.modules');
    $originalComments = $configuration->get('comments');

    try {
        $configuration->set('nvl-suite.modules.comments', false);
        $configuration->set('comments', null);

        $findings = resolve(SuiteConsumerAuditor::class)->audit(
            base_path('tests/Fixtures/consumer-audit'),
        );

        expect($findings)->each->toBeInstanceOf(ConsumerAuditFinding::class);
    } finally {
        $configuration->set('nvl-suite.modules', $originalModules);
        $configuration->set('comments', $originalComments);
    }
});

it('applies only exact reviewed suppressions', function (): void {
    $configuration = resolve(Repository::class);
    $original = $configuration->get('nvl-suite.consumer_audit.suppressions');

    try {
        $configuration->set('nvl-suite.consumer_audit.suppressions', [[
            'code' => 'consumer.package_model_query',
            'path' => 'app/UnsafeRoleReader.php',
            'symbol' => 'Nvl\\Auth\\Models\\Role::query',
            'reason' => 'Temporary 1.x compatibility migration.',
        ]]);
        $findings = resolve(SuiteConsumerAuditor::class)->audit(
            base_path('tests/Fixtures/consumer-audit'),
        );

        expect(collect($findings)
            ->where('path', 'app/UnsafeRoleReader.php')
            ->where('symbol', 'Nvl\\Auth\\Models\\Role::query'))
            ->toBeEmpty();
    } finally {
        $configuration->set('nvl-suite.consumer_audit.suppressions', $original);
    }
});

it('rejects invalid paths, formats, and broad suppressions with exit two', function (): void {
    expect(Artisan::call('nvl:suite:consumer-audit', [
        'path' => base_path('tests/Fixtures/missing-consumer'),
    ]))->toBe(2)
        ->and(Artisan::call('nvl:suite:consumer-audit', [
            'path' => base_path('tests/Fixtures/consumer-audit'),
            '--format' => 'yaml',
        ]))->toBe(2);

    $configuration = resolve(Repository::class);
    $original = $configuration->get('nvl-suite.consumer_audit.suppressions');

    try {
        $configuration->set('nvl-suite.consumer_audit.suppressions', [[
            'code' => 'consumer.package_model_query',
            'path' => 'app/*.php',
            'symbol' => 'Nvl\\Auth\\Models\\Role::query',
            'reason' => 'Too broad.',
        ]]);

        expect(Artisan::call('nvl:suite:consumer-audit', [
            'path' => base_path('tests/Fixtures/consumer-audit'),
        ]))->toBe(2);
    } finally {
        $configuration->set('nvl-suite.consumer_audit.suppressions', $original);
    }
});

it('rejects every inexact or unjustified suppression form', function (array $suppression): void {
    $configuration = resolve(Repository::class);
    $original = $configuration->get('nvl-suite.consumer_audit.suppressions');

    try {
        $configuration->set('nvl-suite.consumer_audit.suppressions', [$suppression]);

        expect(Artisan::call('nvl:suite:consumer-audit', [
            'path' => base_path('tests/Fixtures/consumer-audit'),
        ]))->toBe(2);
    } finally {
        $configuration->set('nvl-suite.consumer_audit.suppressions', $original);
    }
})->with([
    'unknown code' => [[
        'code' => 'consumer.unknown',
        'path' => 'app/UnsafeRoleReader.php',
        'symbol' => 'Nvl\\Auth\\Models\\Role::query',
        'reason' => 'Reviewed.',
    ]],
    'absolute path' => [[
        'code' => 'consumer.package_model_query',
        'path' => '/app/UnsafeRoleReader.php',
        'symbol' => 'Nvl\\Auth\\Models\\Role::query',
        'reason' => 'Reviewed.',
    ]],
    'parent traversal' => [[
        'code' => 'consumer.package_model_query',
        'path' => '../app/UnsafeRoleReader.php',
        'symbol' => 'Nvl\\Auth\\Models\\Role::query',
        'reason' => 'Reviewed.',
    ]],
    'regular expression' => [[
        'code' => 'consumer.package_model_query',
        'path' => 'app/UnsafeRoleReader.php',
        'symbol' => '/Role::query/',
        'reason' => 'Reviewed.',
    ]],
    'empty reason' => [[
        'code' => 'consumer.package_model_query',
        'path' => 'app/UnsafeRoleReader.php',
        'symbol' => 'Nvl\\Auth\\Models\\Role::query',
        'reason' => '',
    ]],
]);

it('fails package model queries as v2 errors in normal and strict modes', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-consumer-audit-command-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->ensureDirectoryExists($workspace);
    $modules = array_fill_keys(
        array_keys(resolve(SuiteModuleCatalog::class)->modules()),
        false,
    );
    $runtimeConfiguration = new Repository([
        'nvl-suite' => [
            'modules' => $modules,
            'consumer_audit' => ['authentication_middleware' => ['auth']],
        ],
    ]);
    $catalog = new SuiteModuleCatalog($runtimeConfiguration);
    $application = resolve(Application::class);
    $inspector = new SuiteConfigurationInspector(
        $application,
        $runtimeConfiguration,
        new Schedule,
        $catalog,
    );
    $router = new Router(new Dispatcher($application), $application);
    $console = Mockery::mock(Kernel::class);
    $skills = new SuiteSkillManager(
        filesystem: $filesystem,
        catalog: $catalog,
        suiteRoot: dirname(__DIR__, 2),
        applicationRoot: $workspace,
        suiteVersion: '1.0.0-test',
    );
    $originalAuditor = resolve(SuiteConsumerAuditor::class);

    try {
        expect($skills->publish()['healthy'])->toBeTrue();

        $runtime = new SuiteRuntimeConsumerScanner(
            $inspector,
            $router,
            $console,
            $skills,
            $catalog,
            $runtimeConfiguration,
        );
        app()->instance(SuiteConsumerAuditor::class, new SuiteConsumerAuditor(
            resolve(ComposerSourceRootLocator::class),
            resolve(PhpConsumerBoundaryScanner::class),
            $runtime,
            $catalog,
            resolve(Repository::class),
            $application,
        ));
        $path = base_path('tests/Fixtures/consumer-audit-warning');

        expect(Artisan::call('nvl:suite:consumer-audit', [
            'path' => $path,
        ]))->toBe(1)
            ->and(Artisan::output())->toContain('consumer.package_model_query')
            ->and(Artisan::call('nvl:suite:consumer-audit', [
                'path' => $path,
                '--strict' => true,
                '--format' => 'json',
            ]))->toBe(1);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($payload['healthy'] ?? null)->toBeFalse()
            ->and($payload['strict'] ?? null)->toBeTrue()
            ->and($payload['runtime_checked'] ?? null)->toBeFalse()
            ->and($payload['findings'] ?? [])->toHaveCount(1)
            ->and($payload['findings'][0]['severity'] ?? null)->toBe('error')
            ->and($payload['findings'][0])->toHaveKeys([
                'code',
                'severity',
                'package',
                'path',
                'line',
                'symbol',
                'message',
                'remediation',
            ]);
    } finally {
        app()->instance(SuiteConsumerAuditor::class, $originalAuditor);
        $filesystem->deleteDirectory($workspace);
    }
});

it('reports implicit module decisions and applies the explicit adoption switch', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-consumer-audit-decisions-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->ensureDirectoryExists($workspace);
    $modules = array_fill_keys(
        array_keys(resolve(SuiteModuleCatalog::class)->modules()),
        false,
    );
    $modules['support'] = true;
    unset($modules['data']);
    $runtimeConfiguration = new Repository([
        'nvl-suite' => [
            'modules' => $modules,
            'consumer_audit' => ['authentication_middleware' => ['auth']],
        ],
    ]);
    $catalog = new SuiteModuleCatalog($runtimeConfiguration);
    $runtimeApplication = resolve(Application::class);
    $inspector = new SuiteConfigurationInspector(
        $runtimeApplication,
        $runtimeConfiguration,
        new Schedule,
        $catalog,
    );
    $router = new Router(new Dispatcher($runtimeApplication), $runtimeApplication);
    $console = Mockery::mock(Kernel::class);
    $console->shouldNotReceive('call');
    $skills = new SuiteSkillManager(
        filesystem: $filesystem,
        catalog: $catalog,
        suiteRoot: dirname(__DIR__, 2),
        applicationRoot: $workspace,
        suiteVersion: '1.0.0-test',
    );
    $originalAuditor = resolve(SuiteConsumerAuditor::class);
    $configuration = resolve(Repository::class);
    $originalPolicy = $configuration->get(
        'nvl-suite.adoption.require_explicit_module_decisions',
    );
    $consumerPath = base_path();

    try {
        expect($skills->publish()['healthy'])->toBeTrue();

        $runtime = new SuiteRuntimeConsumerScanner(
            $inspector,
            $router,
            $console,
            $skills,
            $catalog,
            $runtimeConfiguration,
        );
        $auditor = new SuiteConsumerAuditor(
            resolve(ComposerSourceRootLocator::class),
            resolve(PhpConsumerBoundaryScanner::class),
            $runtime,
            $catalog,
            $configuration,
            $runtimeApplication,
        );
        app()->instance(SuiteConsumerAuditor::class, $auditor);
        $configuration->set(
            'nvl-suite.adoption.require_explicit_module_decisions',
            true,
        );
        $findings = $auditor->audit($consumerPath);
        $decisions = collect($findings)
            ->where('code', 'consumer.implicit_module_decision');

        expect($decisions)->toHaveCount(1)
            ->and($decisions->first()?->package)->toBe('data')
            ->and(Artisan::call('nvl:suite:consumer-audit', [
                'path' => $consumerPath,
            ]))->toBe(0)
            ->and(Artisan::call('nvl:suite:consumer-audit', [
                'path' => $consumerPath,
                '--strict' => true,
            ]))->toBe(1);

        $configuration->set(
            'nvl-suite.adoption.require_explicit_module_decisions',
            false,
        );

        expect(Artisan::call('nvl:suite:consumer-audit', [
            'path' => $consumerPath,
            '--strict' => true,
        ]))->toBe(0);
    } finally {
        app()->instance(SuiteConsumerAuditor::class, $originalAuditor);
        $configuration->set(
            'nvl-suite.adoption.require_explicit_module_decisions',
            $originalPolicy,
        );
        $filesystem->deleteDirectory($workspace);
    }
});

it('returns one for error findings and emits secret-free JSON', function (): void {
    $process = new Process([
        PHP_BINARY,
        base_path('artisan'),
        'nvl:suite:consumer-audit',
        base_path('tests/Fixtures/consumer-audit'),
        '--format=json',
    ], base_path());
    $process->setTimeout(30);
    $process->run();
    $output = $process->getOutput();
    $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe(1)
        ->and($payload['healthy'] ?? null)->toBeFalse()
        ->and($payload['runtime_checked'] ?? null)->toBeFalse()
        ->and(collect($payload['findings'] ?? [])->pluck('code'))
        ->toContain('consumer.package_model_write', 'consumer.package_table_reference')
        ->and($output)
        ->not->toContain(
            'guard_name',
            'Temporary 1.x compatibility migration.',
            'sk_live_consumer_secret',
        );
});
