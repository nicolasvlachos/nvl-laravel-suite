<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Nvl\Pages\Contracts\PageAuthorization;
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
            ->and($report['modules']['data'] ?? null)->toBeTrue()
            ->and($report['modules']['auth'] ?? null)->toBeTrue()
            ->and(collect($report['modules'] ?? [])->except(['data', 'auth'])->filter())
            ->toBeEmpty();
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
    } finally {
        File::delete($path);
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
        ->toContain('data', 'pages')
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

    expect($findings->pluck('code'))
        ->toContain('upgrade.module_unknown', 'upgrade.module_invalid')
        ->and($output)->not->toContain('fixture-secret-value', 'definitely-not-a-boolean');
});

it('rejects a configuration source that does not return an array', function (): void {
    expect(Artisan::call('nvl:suite:upgrade:check', [
        '--path' => base_path('tests/Fixtures/suite-config/not-array.php'),
        '--format' => 'json',
    ]))->toBe(2)
        ->and(Artisan::output())->not->toContain('fixture-secret-value');
});
