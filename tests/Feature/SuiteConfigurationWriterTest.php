<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Suite\Services\SuitePackageConfigurationInspector;
use Nvl\Suite\Support\SuiteModuleCatalog;

it('renders a dependency-complete profile without writing by default', function (): void {
    $path = storage_path('framework/testing/nvl-suite-dry-run-'.bin2hex(random_bytes(4)).'.php');
    File::delete($path);

    try {
        expect(Artisan::call('nvl:suite:configure', [
            '--profile' => 'auth-only',
            '--path' => $path,
            '--format' => 'json',
        ]))->toBe(0)
            ->and(File::exists($path))->toBeFalse();

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($report['written'] ?? null)->toBeFalse()
            ->and($report['backup'] ?? null)->toBeNull()
            ->and($report['modules'] ?? null)->toBe([
                'support' => true,
                'data' => true,
                'filterable' => false,
                'translatable' => false,
                'activity' => false,
                'auth' => true,
                'csv' => false,
                'mail-notifications' => false,
                'media' => false,
                'comments' => false,
                'content' => false,
                'metafields' => false,
                'primitives' => false,
                'seo' => false,
                'settings' => false,
                'taxonomy' => false,
                'templates' => false,
                'translations' => false,
                'forms' => false,
                'pages' => false,
            ]);
    } finally {
        File::delete($path);
    }
});

it('writes a complete canonical configuration only with the explicit write flag', function (): void {
    $path = storage_path('framework/testing/nvl-suite-write-'.bin2hex(random_bytes(4)).'.php');
    File::delete($path);

    try {
        expect(Artisan::call('nvl:suite:configure', [
            '--profile' => 'auth-only',
            '--add' => ['pages'],
            '--path' => $path,
            '--write' => true,
            '--format' => 'json',
        ]))->toBe(0)
            ->and($path)->toBeFile();

        $configuration = require $path;
        $catalog = resolve(SuiteModuleCatalog::class);

        expect($configuration)->toBeArray()
            ->and(array_keys($configuration['modules'] ?? []))
            ->toBe(array_keys($catalog->modules()))
            ->and($configuration['modules']['auth'] ?? null)->toBeTrue()
            ->and($configuration['modules']['pages'] ?? null)->toBeTrue()
            ->and($configuration['modules']['content'] ?? null)->toBeTrue()
            ->and($configuration['modules']['forms'] ?? null)->toBeFalse()
            ->and($configuration['adoption']['require_explicit_module_decisions'] ?? null)
            ->toBeFalse()
            ->and($configuration['consumer_audit']['suppressions'] ?? null)->toBe([]);

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($report['backup'] ?? null)->toBeNull()
            ->and(File::glob($path.'.backup-*'))->toBe([]);
    } finally {
        File::delete($path);
    }
});

it('renders minimal declarative overlays and full legacy maps explicitly', function (): void {
    expect(Artisan::call('nvl:suite:configure', [
        '--profile' => 'auth-only',
        '--add' => ['comments'],
        '--remove' => ['forms'],
        '--minimal' => true,
        '--format' => 'json',
    ]))->toBe(0);

    $minimal = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($minimal['mode'] ?? null)->toBe('minimal')
        ->and($minimal['removals'] ?? null)->toBe(['forms'])
        ->and($minimal['contents'] ?? null)
        ->toContain("'profile' => 'auth-only'", "'include' => ['comments']", "'exclude' => ['forms']")
        ->not->toContain("'modules' =>");

    expect(Artisan::call('nvl:suite:configure', [
        '--profile' => 'auth-only',
        '--full' => true,
        '--format' => 'json',
    ]))->toBe(0);

    $full = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($full['mode'] ?? null)->toBe('full')
        ->and($full['contents'] ?? null)->toContain("'modules' => [")
        ->and(Artisan::call('nvl:suite:configure', [
            '--profile' => 'auth-only',
            '--minimal' => true,
            '--full' => true,
        ]))->toBe(2);
});

it('backs up exact overwritten configuration only when contents change', function (): void {
    $path = storage_path('framework/testing/nvl-suite-force-'.bin2hex(random_bytes(4)).'.php');
    $original = "<?php\n\ndeclare(strict_types=1);\n\nreturn ['existing' => true];\n";
    File::put($path, $original);
    $before = hash_file('sha256', $path);
    Carbon::setTestNow('2026-08-29 12:34:56');
    $backupPath = $path.'.backup-20260829-123456';

    try {
        expect(Artisan::call('nvl:suite:configure', [
            '--profile' => 'auth-only',
            '--minimal' => true,
            '--path' => $path,
            '--write' => true,
            '--format' => 'json',
        ]))->toBe(2)
            ->and(hash_file('sha256', $path))->toBe($before)
            ->and(Artisan::call('nvl:suite:configure', [
                '--profile' => 'auth-only',
                '--minimal' => true,
                '--path' => $path,
                '--write' => true,
                '--force' => true,
                '--format' => 'json',
            ]))->toBe(0);

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($report['diff'] ?? null)
            ->toContain('--- config/', '+++ generated/', '@@')
            ->and($report['backup'] ?? null)->toBe(str_replace(base_path().'/', '', $backupPath))
            ->and(File::get($path))->toBe($report['contents'] ?? null)
            ->and(File::get($backupPath))->toBe($original);

        Carbon::setTestNow('2026-08-29 12:35:57');

        expect(Artisan::call('nvl:suite:configure', [
            '--profile' => 'auth-only',
            '--minimal' => true,
            '--path' => $path,
            '--write' => true,
            '--force' => true,
            '--format' => 'json',
        ]))->toBe(0);

        $unchanged = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($unchanged['written'] ?? null)->toBeFalse()
            ->and($unchanged['backup'] ?? null)->toBeNull()
            ->and(File::exists($path.'.backup-20260829-123557'))->toBeFalse();
    } finally {
        Carbon::setTestNow();
        File::delete($path);
        File::delete($backupPath);
    }
});

it('rejects invalid selections formats and destinations without writing', function (): void {
    $outside = sys_get_temp_dir().'/nvl-suite-outside-'.bin2hex(random_bytes(4)).'.php';
    $directoryPath = storage_path('framework/testing/nvl-suite-directory-'.bin2hex(random_bytes(4)).'.php');
    File::delete($outside);
    File::makeDirectory($directoryPath);

    try {
        expect(Artisan::call('nvl:suite:configure', [
            '--profile' => 'unknown',
        ]))->toBe(2)
            ->and(Artisan::call('nvl:suite:configure', [
                '--add' => ['unknown'],
            ]))->toBe(2)
            ->and(Artisan::call('nvl:suite:configure', [
                '--profile' => 'auth-only',
                '--remove' => ['support'],
            ]))->toBe(2)
            ->and(Artisan::call('nvl:suite:configure', [
                '--profile' => 'auth-only',
                '--force' => true,
            ]))->toBe(2)
            ->and(Artisan::call('nvl:suite:configure'))->toBe(2)
            ->and(Artisan::call('nvl:suite:configure', [
                '--profile' => 'auth-only',
                '--format' => 'yaml',
            ]))->toBe(2)
            ->and(Artisan::call('nvl:suite:configure', [
                '--profile' => 'auth-only',
                '--path' => $outside,
                '--write' => true,
            ]))->toBe(2)
            ->and(File::exists($outside))->toBeFalse()
            ->and(Artisan::call('nvl:suite:configure', [
                '--profile' => 'auth-only',
                '--path' => storage_path('framework/testing/nvl-suite.json'),
            ]))->toBe(2)
            ->and(Artisan::call('nvl:suite:configure', [
                '--profile' => 'auth-only',
                '--path' => $directoryPath,
            ]))->toBe(2);
    } finally {
        File::deleteDirectory($directoryPath);
    }
});

it('rejects configuration paths that escape through a symbolic link', function (): void {
    $outside = sys_get_temp_dir().'/nvl-suite-symlink-target-'.bin2hex(random_bytes(4));
    $link = storage_path('framework/testing/nvl-suite-symlink-'.bin2hex(random_bytes(4)));
    File::makeDirectory($outside);

    try {
        expect(symlink($outside, $link))->toBeTrue()
            ->and(Artisan::call('nvl:suite:configure', [
                '--profile' => 'auth-only',
                '--path' => $link.'/nvl-suite.php',
                '--write' => true,
            ]))->toBe(2)
            ->and(File::exists($outside.'/nvl-suite.php'))->toBeFalse();
    } finally {
        if (is_link($link)) {
            unlink($link);
        }

        File::deleteDirectory($outside);
    }
});

it('reports incomplete published module decisions and their operational reviews', function (): void {
    $path = base_path('tests/Fixtures/suite-config/partial.php');
    $before = hash_file('sha256', $path);

    expect(Artisan::call('nvl:suite:upgrade:check', [
        '--path' => $path,
        '--format' => 'json',
    ]))->toBe(1);

    $output = Artisan::output();
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $findings = collect($report['findings'] ?? []);

    expect($report['healthy'] ?? null)->toBeFalse()
        ->and($findings->where('code', 'upgrade.module_missing')->pluck('module'))
        ->values()->all()->toBe([
            'activity',
            'comments',
            'content',
            'csv',
            'data',
            'filterable',
            'forms',
            'mail-notifications',
            'media',
            'metafields',
            'pages',
            'primitives',
            'seo',
            'settings',
            'support',
            'taxonomy',
            'templates',
            'translatable',
            'translations',
        ])
        ->and($findings->where('code', 'upgrade.module_missing')->pluck('message')->unique()->values()->all())
        ->toBe(['The omitted module flag resolves to disabled in Suite 2.0.'])
        ->and($findings->where('code', 'upgrade.module_missing')->pluck('remediation')->unique()->values()->all())
        ->toBe(['Run nvl:suite:configure with a reviewed profile and --full, then use --write --force to replace the partial map with explicit decisions.'])
        ->and($findings->where('code', 'upgrade.required_contract_review')->pluck('symbol'))
        ->toContain(PageAuthorization::class)
        ->and($findings->where('code', 'upgrade.required_schedule_review')->pluck('symbol'))
        ->toContain('nvl:mail-notifications:process-scheduled')
        ->and(hash_file('sha256', $path))->toBe($before)
        ->and($output)->not->toContain('fixture-secret-value');
});

it('accepts the current complete published configuration', function (): void {
    expect(Artisan::call('nvl:suite:upgrade:check', [
        '--path' => base_path('config/nvl-suite.php'),
        '--strict' => true,
        '--format' => 'json',
    ]))->toBe(0);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['healthy'] ?? null)->toBeTrue()
        ->and($report['findings'] ?? null)->toBe([]);
});

it('reports unknown and non-boolean module keys without exposing their values', function (): void {
    expect(Artisan::call('nvl:suite:upgrade:check', [
        '--path' => base_path('tests/Fixtures/suite-config/invalid.php'),
        '--format' => 'json',
    ]))->toBe(1);

    $output = Artisan::output();
    $findings = collect(json_decode(
        $output,
        true,
        flags: JSON_THROW_ON_ERROR,
    )['findings'] ?? []);

    $unknown = $findings->firstWhere('code', 'upgrade.module_unknown');
    $invalid = $findings->firstWhere('code', 'upgrade.module_invalid');

    expect($findings->pluck('code'))
        ->toContain('upgrade.module_unknown', 'upgrade.module_invalid')
        ->and($unknown['module'] ?? null)->toBe('retired-module')
        ->and($unknown['symbol'] ?? null)->toBe('retired-module')
        ->and($unknown['message'] ?? null)
        ->toBe('The published configuration contains an unknown suite module.')
        ->and($unknown['remediation'] ?? null)
        ->toBe('Remove the retired or unsupported module decision after reviewing the upgrade notes.')
        ->and($invalid['module'] ?? null)->toBe('auth')
        ->and($invalid['symbol'] ?? null)->toBe('modules.auth')
        ->and($output)->not->toContain('fixture-secret-value', 'definitely-not-a-boolean');
});

it('rejects a configuration source that does not return an array', function (): void {
    expect(Artisan::call('nvl:suite:upgrade:check', [
        '--path' => base_path('tests/Fixtures/suite-config/not-array.php'),
        '--format' => 'json',
    ]))->toBe(2)
        ->and(Artisan::output())->not->toContain('fixture-secret-value');
});

it('combines value-free package drift findings and supports repeatable module filters', function (): void {
    $directory = storage_path('framework/testing/suite-upgrade-package-config-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($directory);
    File::put($directory.'/translations.php', <<<'PHP'
<?php

throw new RuntimeException('published configuration was executed');

return [
    'authorization' => [
        'class' => 'must-never-appear',
    ],
];
PHP);
    app()->instance(
        SuitePackageConfigurationInspector::class,
        new SuitePackageConfigurationInspector(
            filesystem: app(Filesystem::class),
            catalog: app(SuiteModuleCatalog::class),
            suiteRoot: base_path(),
            configurationPath: $directory,
        ),
    );

    try {
        expect(Artisan::call('nvl:suite:upgrade:check', [
            '--path' => base_path('config/nvl-suite.php'),
            '--module' => ['translations'],
            '--strict' => true,
            '--format' => 'json',
        ]))->toBe(1);

        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $findings = collect($report['findings'] ?? []);

        expect($report['modules'] ?? null)->toBe(['translations'])
            ->and($findings->where('code', 'configuration.deprecated_key'))->toHaveCount(1)
            ->and($findings->where('module', 'translations'))->toHaveCount(1)
            ->and($output)->not->toContain('must-never-appear', 'published configuration was executed');
    } finally {
        app()->forgetInstance(SuitePackageConfigurationInspector::class);
        File::deleteDirectory($directory);
    }
});

it('keeps expanded-overlay warnings non-failing in strict upgrade checks', function (): void {
    $directory = storage_path('framework/testing/suite-upgrade-package-config-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($directory);
    File::copy(base_path('packages/nvl/auth/config/nvl-auth.php'), $directory.'/nvl-auth.php');
    app()->instance(
        SuitePackageConfigurationInspector::class,
        new SuitePackageConfigurationInspector(
            filesystem: app(Filesystem::class),
            catalog: app(SuiteModuleCatalog::class),
            suiteRoot: base_path(),
            configurationPath: $directory,
        ),
    );

    try {
        expect(Artisan::call('nvl:suite:upgrade:check', [
            '--path' => base_path('config/nvl-suite.php'),
            '--module' => ['auth'],
            '--strict' => true,
            '--format' => 'json',
        ]))->toBe(0);

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect(collect($report['findings'] ?? [])->pluck('code'))
            ->toContain('configuration.expanded_overlay')
            ->not->toContain('configuration.unknown_key', 'configuration.deprecated_key');
    } finally {
        app()->forgetInstance(SuitePackageConfigurationInspector::class);
        File::deleteDirectory($directory);
    }
});

it('rejects unknown package configuration module filters', function (): void {
    expect(Artisan::call('nvl:suite:upgrade:check', [
        '--path' => base_path('config/nvl-suite.php'),
        '--module' => ['unknown'],
        '--format' => 'json',
    ]))->toBe(2);
});
