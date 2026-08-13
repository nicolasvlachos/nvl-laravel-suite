<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Suite\Services\SuiteConfigurationInspector;
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

it('provides dependency-complete installation profiles', function (): void {
    $catalog = app(SuiteModuleCatalog::class);

    expect(array_keys($catalog->profiles()))->toBe([
        'auth-only',
        'content-platform',
        'communications',
        'full-suite',
    ])->and($catalog->profileModules('auth-only'))->toBe([
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

it('reports effective ownership implementations aliases and schedules without secrets', function (): void {
    config()->set('comments.idempotency.digest_key', 'must-never-appear');
    $report = app(SuiteConfigurationInspector::class)->inspect('full-suite');
    $serialized = json_encode($report, JSON_THROW_ON_ERROR);

    expect($report['profile'])->not->toBeNull()
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
        ->and(Artisan::call('nvl:suite:configuration', [
            '--profile' => 'unknown',
        ]))->toBe(2)
        ->and(Artisan::call('nvl:suite:configuration', [
            '--format' => 'yaml',
        ]))->toBe(2);
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
