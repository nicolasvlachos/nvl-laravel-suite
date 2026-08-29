<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Suite\Services\SuiteConfigurationInspector;
use Nvl\Suite\Services\SuiteModuleSelection;
use Nvl\Suite\Services\SuitePackageConfigurationInspector;
use Nvl\Suite\Services\SuiteUpgradeInspector;
use Nvl\Suite\Support\SuiteModuleCatalog;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @return array<string, bool>
 */
function suiteDiagnosticModules(string ...$enabled): array
{
    $modules = array_fill_keys(
        array_keys(app(SuiteModuleCatalog::class)->modules()),
        false,
    );

    foreach ($enabled as $module) {
        $modules[$module] = true;
    }

    return $modules;
}

/**
 * @param  array<string, string>  $sources
 * @return array{string, SuitePackageConfigurationInspector}
 */
function suitePackageConfigurationInspector(array $sources): array
{
    $directory = storage_path('framework/testing/suite-package-config-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($directory);

    foreach ($sources as $file => $source) {
        File::put($directory.'/'.$file, $source);
    }

    return [
        $directory,
        new SuitePackageConfigurationInspector(
            filesystem: app(Filesystem::class),
            catalog: app(SuiteModuleCatalog::class),
            suiteRoot: base_path(),
            configurationPath: $directory,
        ),
    ];
}

it('treats omitted legacy module decisions as intentionally disabled', function (): void {
    config()->set('nvl-suite.modules', ['auth' => true, 'forms' => false]);
    $catalog = app(SuiteModuleCatalog::class);

    expect($catalog->moduleDecision('auth'))->toBe('enabled')
        ->and($catalog->moduleDecision('forms'))->toBe('disabled')
        ->and($catalog->moduleDecision('pages'))->toBe('implicit')
        ->and($catalog->requested('pages'))->toBeFalse()
        ->and($catalog->effectiveModules())->toBe(['support', 'data', 'auth']);

    $report = app(SuiteConfigurationInspector::class)->inspect();

    expect($report['modules']['auth'])
        ->decision->toBe('enabled')
        ->explicit->toBeTrue()
        ->and($report['modules']['forms'])
        ->decision->toBe('disabled')
        ->explicit->toBeTrue()
        ->and($report['modules']['pages'])
        ->decision->toBe('implicit')
        ->explicit->toBeFalse()
        ->enabled->toBeFalse();
});

it('reports omitted legacy decisions as intentional disabled states in Doctor output', function (): void {
    $modules = suiteDiagnosticModules('support');
    unset($modules['data']);
    config()->set('nvl-suite.modules', $modules);
    config()->set('nvl-suite.adoption.require_explicit_module_decisions', true);

    expect(Artisan::call('nvl:suite:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ]))->toBe(0);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $check = collect($report['checks'] ?? [])
        ->firstWhere('key', 'module.data.explicit_decision');

    expect($check)
        ->not->toBeNull()
        ->passed->toBeTrue()
        ->severity->toBe('info')
        ->message->toBe('The omitted module flag is intentionally disabled in Suite 2.0.');
});

it('disables every omitted legacy module while re-enabling required dependencies', function (): void {
    $catalog = new SuiteModuleCatalog(new Repository([
        'nvl-suite' => [
            'modules' => [
                'media' => false,
                'content' => false,
                'pages' => true,
            ],
        ],
    ]));
    $omittedModules = [
        'support',
        'data',
        'filterable',
        'translatable',
        'activity',
        'auth',
        'csv',
        'mail-notifications',
        'comments',
        'metafields',
        'primitives',
        'seo',
        'settings',
        'taxonomy',
        'templates',
        'translations',
        'forms',
    ];

    expect(array_map(
        static fn (string $module): bool => $catalog->requested($module),
        $omittedModules,
    ))->toBe(array_fill(0, count($omittedModules), false))
        ->and(array_map(
            static fn (string $module): string => $catalog->moduleDecision($module),
            $omittedModules,
        ))->toBe(array_fill(0, count($omittedModules), 'implicit'))
        ->and($catalog->requested('media'))->toBeFalse()
        ->and($catalog->requested('content'))->toBeFalse()
        ->and($catalog->requested('pages'))->toBeTrue()
        ->and($catalog->effectiveModules())->toBe([
            'support',
            'data',
            'filterable',
            'translatable',
            'media',
            'content',
            'metafields',
            'seo',
            'pages',
        ]);
});

it('rejects unknown keys in partial legacy module maps', function (): void {
    $catalog = new SuiteModuleCatalog(new Repository([
        'nvl-suite' => [
            'modules' => [
                'pages' => true,
                'retired-module' => false,
            ],
        ],
    ]));

    expect(fn (): array => $catalog->effectiveModules())
        ->toThrow(
            RuntimeException::class,
            'Unknown suite module configuration: retired-module.',
        );
});

it('provides dependency-complete installation profiles', function (): void {
    $catalog = app(SuiteModuleCatalog::class);

    expect(array_keys($catalog->profiles()))->toBe([
        'auth-only',
        'content-platform',
        'communications',
        'full-suite',
    ])->and($catalog->profileModules('auth-only'))->toBe([
        'support',
        'data',
        'auth',
    ])->and($catalog->profileModules('content-platform'))->toContain(
        'support',
        'data',
        'filterable',
        'translatable',
        'media',
        'content',
        'metafields',
        'seo',
        'taxonomy',
        'templates',
        'translations',
        'pages',
    )->and($catalog->profileModules('full-suite'))->toHaveCount(20);

    foreach ($catalog->modules() as $definition) {
        foreach ($definition['schedules'] as $schedule) {
            expect(app(Kernel::class)->all())->toHaveKey($schedule['command']);
        }
    }
});

it('keeps legacy module maps authoritative while resolving declarative profiles and overlays', function (): void {
    $catalog = app(SuiteModuleCatalog::class);
    $legacyModules = array_fill_keys(array_keys($catalog->modules()), false);
    $legacyModules['auth'] = true;
    $legacy = SuiteModuleSelection::fromConfiguration([
        'modules' => $legacyModules,
        'profile' => 'content-platform',
        'include' => ['pages'],
        'exclude' => [],
    ], $catalog);
    $declarative = SuiteModuleSelection::fromConfiguration([
        'modules' => null,
        'profile' => 'auth-only',
        'include' => ['pages'],
        'exclude' => ['forms'],
    ], $catalog);

    expect($legacy->source)->toBe('legacy')
        ->and($legacy->effectiveModules())->toBe(['support', 'data', 'auth'])
        ->and($legacy->decision('pages'))->toBe('disabled')
        ->and($declarative->source)->toBe('declarative')
        ->and($declarative->effectiveModules())->toContain('support', 'data', 'auth', 'pages')
        ->not->toContain('forms')
        ->and($declarative->requested('pages'))->toBeTrue()
        ->and($declarative->requested('content'))->toBeFalse();
});

it('validates declarative selections and supports an intentional empty suite', function (): void {
    $catalog = app(SuiteModuleCatalog::class);
    $empty = SuiteModuleSelection::fromConfiguration([
        'modules' => null,
        'profile' => null,
        'include' => [],
        'exclude' => [],
    ], $catalog);

    expect($empty->effectiveModules())->toBe([])
        ->and(fn (): SuiteModuleSelection => SuiteModuleSelection::fromConfiguration([
            'modules' => null,
            'profile' => 'auth-only',
            'include' => [],
            'exclude' => ['support'],
        ], $catalog))->toThrow(RuntimeException::class, 'required dependency')
        ->and(fn (): SuiteModuleSelection => SuiteModuleSelection::fromConfiguration([
            'modules' => null,
            'profile' => null,
            'include' => ['unknown'],
            'exclude' => [],
        ], $catalog))->toThrow(RuntimeException::class, 'Unknown suite module')
        ->and(fn (): SuiteModuleSelection => SuiteModuleSelection::fromConfiguration([
            'modules' => null,
            'profile' => 'unknown',
            'include' => [],
            'exclude' => [],
        ], $catalog))->toThrow(RuntimeException::class, 'Unknown suite installation profile');
});

it('expresses the KPO module set as capability roots without an application-specific profile', function (): void {
    $selection = SuiteModuleSelection::fromConfiguration([
        'modules' => null,
        'profile' => null,
        'include' => [
            'activity',
            'auth',
            'csv',
            'mail-notifications',
            'comments',
            'pages',
            'settings',
            'templates',
            'translations',
        ],
        'exclude' => ['primitives', 'taxonomy', 'forms'],
    ], app(SuiteModuleCatalog::class));

    expect($selection->effectiveModules())->toHaveCount(17)
        ->not->toContain('primitives', 'taxonomy', 'forms');
});

it('uses the shipped full-suite default when the consumer has no published configuration', function (): void {
    $configuration = require base_path('config/nvl-suite.php');
    $selection = SuiteModuleSelection::fromConfiguration(
        $configuration,
        app(SuiteModuleCatalog::class),
    );

    expect($configuration)->toHaveKey('modules')
        ->and($configuration['modules'])->toBeNull()
        ->and($configuration['profile'] ?? null)->toBe('full-suite')
        ->and($selection->effectiveModules())->toBe([
            'support',
            'data',
            'filterable',
            'translatable',
            'activity',
            'auth',
            'csv',
            'mail-notifications',
            'media',
            'comments',
            'content',
            'metafields',
            'primitives',
            'seo',
            'settings',
            'taxonomy',
            'templates',
            'translations',
            'forms',
            'pages',
        ]);
});

it('accepts declarative upgrade selections and rejects mixed or invalid sources', function (): void {
    $inspector = app(SuiteUpgradeInspector::class);
    $legacy = suiteDiagnosticModules('support', 'data', 'auth');

    expect($inspector->inspect([
        'profile' => 'auth-only',
        'include' => ['pages'],
        'exclude' => ['forms'],
        'modules' => null,
    ]))->toBe([])
        ->and(collect($inspector->inspect([
            'profile' => 'auth-only',
            'modules' => $legacy,
        ]))->pluck('code'))->toContain('upgrade.selection_conflict')
        ->and(collect($inspector->inspect([
            'profile' => 'auth-only',
            'exclude' => ['support'],
            'modules' => null,
        ]))->pluck('code')->all())->toBe(['upgrade.selection_invalid']);
});

it('reports effective ownership implementations aliases and schedules without secrets', function (): void {
    config()->set('comments.idempotency.digest_key', 'must-never-appear');
    $report = app(SuiteConfigurationInspector::class)->inspect('full-suite');
    $serialized = json_encode($report, JSON_THROW_ON_ERROR);

    expect($report['selection'])->toBe([
        'source' => 'declarative',
        'profile' => 'full-suite',
        'include' => [],
        'exclude' => [],
    ])->and($report['profile'])->not->toBeNull()
        ->and($report['profile']['matches'])->toBeTrue()
        ->and($report['modules'])->toHaveCount(20)
        ->and($report['modules']['auth']['migration']['owner'])->toBe('package')
        ->and($report['modules']['settings']['implementations'])
        ->toHaveKey(SettingsAuthorization::class)
        ->and($report['modules']['content']['registered_aliases'])
        ->toContain('reference_models')
        ->and($report['morph_aliases'])->toContain('reference_models')
        ->and($serialized)->not->toContain('must-never-appear');
});

it('renders machine readable effective configuration and validates options', function (): void {
    expect(Artisan::call('nvl:suite:configuration', [
        '--profile' => 'auth-only',
        '--format' => 'json',
    ]))->toBe(0);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['profile']['name'] ?? null)->toBe('auth-only')
        ->and($report['profile']['matches'] ?? null)->toBeFalse()
        ->and($report['modules']['auth']['enabled'] ?? null)->toBeTrue()
        ->and($report['package_configuration']['healthy'] ?? null)->toBeTrue()
        ->and($report['package_configuration']['findings'] ?? null)->toBe([])
        ->and(Artisan::call('nvl:suite:configuration', [
            '--profile' => 'unknown',
        ]))->toBe(2)
        ->and(Artisan::call('nvl:suite:configuration', [
            '--format' => 'yaml',
        ]))->toBe(2);
});

it('classifies deprecated and unknown package configuration paths without evaluating values', function (): void {
    [$directory, $inspector] = suitePackageConfigurationInspector([
        'translations.php' => <<<'PHP'
<?php

throw new RuntimeException('consumer configuration was executed');

return [
    'authorization' => [
        'class' => 'must-never-appear',
        'ability' => null,
    ],
    'retired_branch' => ['secret' => 'must-never-appear'],
];
PHP,
    ]);

    try {
        $findings = collect($inspector->inspect(['translations']));
        $serialized = json_encode($findings->all(), JSON_THROW_ON_ERROR);

        expect($findings->pluck('code'))
            ->toContain('configuration.deprecated_key', 'configuration.unknown_key')
            ->and($findings->firstWhere('code', 'configuration.deprecated_key')['path'] ?? null)
            ->toBe('translations.authorization.class')
            ->and($findings->firstWhere('code', 'configuration.unknown_key')['path'] ?? null)
            ->toBe('translations.retired_branch')
            ->and($serialized)->not->toContain('must-never-appear');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('treats extension maps and minimal package overlays as intentional configuration', function (): void {
    [$directory, $inspector] = suitePackageConfigurationInspector([
        'comments.php' => <<<'PHP'
<?php

$target = 'article';

return [
    'authorization' => ['class' => App\Comments\CommentAuthorization::class],
    'targets' => [
        $target => App\Comments\ArticleTarget::class,
    ],
    'routes' => [
        'public' => ['enabled' => true],
    ],
];
PHP,
        'content.php' => <<<'PHP'
<?php

return array(
    'scopes' => array(
        'site' => array('key_pattern' => '/^[a-z]+$/'),
    ),
);
PHP,
        'nvl-auth.php' => <<<'PHP'
<?php

return [
    'management' => [
        'abilities' => ['users.viewAny' => 'viewAny'],
        'policy_models' => ['users' => App\Models\User::class],
    ],
    'ownership' => [
        'host_routes' => ['authentication.public' => ['login']],
    ],
];
PHP,
    ]);

    try {
        $findings = collect($inspector->inspect(['comments', 'content', 'auth']));

        expect($findings->whereIn('code', [
            'configuration.unknown_key',
            'configuration.source_unavailable',
            'configuration.expanded_overlay',
            'configuration.missing_current_branch',
        ]))->toBeEmpty();
    } finally {
        File::deleteDirectory($directory);
    }
});

it('warns for expanded snapshots and reports only their missing current branch roots', function (): void {
    [$directory, $inspector] = suitePackageConfigurationInspector([
        'pages.php' => <<<'PHP'
<?php

return [
    'connection' => null,
    'tables' => ['pages' => 'pages', 'pages_i18n' => 'pages_i18n', 'page_tree_locks' => 'page_tree_locks'],
    'migrations' => ['enabled' => true],
    'hierarchy' => ['maximum_depth' => 4],
    'transactions' => ['attempts' => 3],
    'resources' => [],
    'public' => ['default_site' => 'default'],
    'authorization' => ['class' => App\Pages\Authorization::class],
    'urls' => ['base_url' => 'https://example.test', 'locale_prefix' => false, 'default_locale' => 'en'],
    'integrations' => ['seo_owner_alias' => 'page', 'metafield_owner_alias' => 'page', 'metafield_sections' => ['general']],
    'routes' => [
        'public' => ['enabled' => false, 'prefix' => 'api/pages', 'name' => 'pages.', 'middleware' => ['api']],
        'management' => ['enabled' => false, 'prefix' => 'api/pages/manage', 'name' => 'pages.manage.', 'middleware' => ['api']],
    ],
    'limits' => ['per_page' => 25, 'maximum_per_page' => 100, 'maximum_path_bytes' => 768, 'maximum_resource_parameters' => 8],
];
PHP,
    ]);

    try {
        $findings = collect($inspector->inspect(['pages']));
        $missing = $findings->where('code', 'configuration.missing_current_branch')->pluck('path');

        expect($findings->where('code', 'configuration.expanded_overlay'))->toHaveCount(1)
            ->and($missing)->toContain(
                'pages.public.context_resolver',
                'pages.urls.generator',
                'pages.limits.maximum_page_options',
                'pages.limits.maximum_public_children',
            );
    } finally {
        File::deleteDirectory($directory);
    }
});

it('detects full copied defaults but does not serialize their values', function (): void {
    $directory = storage_path('framework/testing/suite-package-config-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($directory);
    File::copy(base_path('packages/nvl/auth/config/nvl-auth.php'), $directory.'/nvl-auth.php');

    try {
        $inspector = new SuitePackageConfigurationInspector(
            filesystem: app(Filesystem::class),
            catalog: app(SuiteModuleCatalog::class),
            suiteRoot: base_path(),
            configurationPath: $directory,
        );
        $findings = $inspector->inspect(['auth']);
        $serialized = json_encode($findings, JSON_THROW_ON_ERROR);

        expect(collect($findings)->where('code', 'configuration.expanded_overlay'))
            ->toHaveCount(1)
            ->and($serialized)->not->toContain('NVL_AUTH_', 'Laravel');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('reports dynamic closed branches as source unavailable', function (): void {
    [$directory, $inspector] = suitePackageConfigurationInspector([
        'pages.php' => <<<'PHP'
<?php

$branch = 'unexpected';

return [
    $branch => ['private' => 'must-never-appear'],
];
PHP,
    ]);

    try {
        $findings = collect($inspector->inspect(['pages']));
        $serialized = json_encode($findings->all(), JSON_THROW_ON_ERROR);

        expect($findings->where('code', 'configuration.source_unavailable'))->toHaveCount(1)
            ->and($serialized)->not->toContain('must-never-appear', 'unexpected');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('runs every effective package doctor through the root strict command', function (): void {
    config()->set('nvl-suite.modules', suiteDiagnosticModules('settings'));
    $output = new BufferedOutput;

    expect(app(Kernel::class)->call('nvl:suite:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ], $output))->toBe(0);

    $report = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($report['doctors'] ?? []))->toBe(['settings'])
        ->and($report['doctors']['settings']['healthy'] ?? null)->toBeTrue()
        ->and($report['healthy'] ?? null)->toBeTrue();

    config()->set('settings.migrations.enabled', 'true');
    $invalidOutput = new BufferedOutput;

    expect(app(Kernel::class)->call('nvl:suite:doctor', [
        '--format' => 'json',
    ], $invalidOutput))->toBe(1);

    $invalidReport = json_decode(
        $invalidOutput->fetch(),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $invalidChecks = collect($invalidReport['checks'])->keyBy('key');

    expect($invalidChecks['module.settings.migration_ownership']['passed'] ?? null)
        ->toBeFalse();
});

it('fails production readiness for debug mode without disclosing the application key', function (): void {
    config()->set('nvl-suite.modules', suiteDiagnosticModules('support'));
    config()->set('app.debug', true);
    config()->set('app.key', 'base64:must-never-be-rendered');

    expect(Artisan::call('nvl:suite:doctor', [
        '--production' => true,
        '--format' => 'json',
    ]))->toBe(1);

    $output = Artisan::output();
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $checks = collect($report['checks'])->keyBy('key');

    expect($checks['production.debug']['passed'] ?? null)->toBeFalse()
        ->and($checks['production.application_key']['passed'] ?? null)->toBeTrue()
        ->and($output)->not->toContain('must-never-be-rendered');
});

it('detects missing required host scheduler entries for enabled features', function (): void {
    config()->set('nvl-suite.modules', suiteDiagnosticModules('mail-notifications'));
    config()->set('mail-notifications.scheduling.enabled', true);
    app(Schedule::class)->command('nvl:mail-notifications:process-scheduled')
        ->everyMinute()
        ->onOneServer()
        ->withoutOverlapping();

    $report = app(SuiteConfigurationInspector::class)->inspect();
    $schedules = collect($report['modules']['mail-notifications']['schedules'])
        ->keyBy('command');

    expect($schedules['nvl:mail-notifications:process-scheduled']['registered'] ?? null)
        ->toBeTrue()
        ->and($schedules['nvl:mail-notifications:recover-scheduled']['registered'] ?? null)
        ->toBeFalse()
        ->and($schedules['nvl:mail-notifications:recover-scheduled']['required'] ?? null)
        ->toBeTrue();
});
