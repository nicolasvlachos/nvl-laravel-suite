<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Nvl\Content\Models\ContentBlock;
use Nvl\Pages\Models\Page;
use Nvl\Settings\Models\Setting;
use Nvl\Suite\Services\SuiteConfigurationInspector;
use Nvl\Suite\Services\SuitePackageConfigurationInspector;
use Nvl\Suite\Support\SuiteModuleCatalog;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantMaintenanceRunner;
use Nvl\Tenancy\Services\TenantOwnershipConfiguration;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

it('separates selected tenancy provider from inert feature and deferred schema readiness', function (): void {
    config()->set('nvl-suite.modules', ['tenancy' => true]);
    $report = app(SuiteConfigurationInspector::class)->inspect();
    expect($report['modules']['tenancy']['enabled'])->toBeTrue()
        ->and($report['tenancy']['enabled'])->toBeFalse()
        ->and($report['tenancy']['schema'])->toBe('not-probed');
});

it('rejects enabled runtime stateful providers that have no tenancy integration', function (): void {
    config()->set('tenancy.enabled', true);
    expect(fn () => app(TenantOwnershipConfiguration::class)->assertReady())
        ->toThrow(TenantConfigurationInvalid::class, 'integration');
});

it('reports incompatible runtime packages independently of selected provider flags', function (): void {
    config()->set('tenancy.enabled', true);
    config()->set('nvl-suite.modules', ['tenancy' => true]);
    $report = app(SuiteConfigurationInspector::class)->inspect();
    expect($report['tenancy']['compatible'])->toBeFalse()
        ->and($report['tenancy']['incompatible_families'])->toContain('settings', 'pages');
});

it('denies incompatible composition at actual tenant and activation entry points before any SQL', function (string $entry): void {
    config()->set('tenancy.enabled', true);
    $database = app('db')->connection();
    $database->enableQueryLog();
    $tenant = new TenantId('11111111-1111-4111-8111-111111111111');
    $operation = new PlatformOperation('composition proof', 'operator', 'test');
    $callback = static fn () => throw new LogicException('Must not enter tenant work.');
    $run = match ($entry) {
        'runner' => fn () => app(TenantRunner::class)->run($tenant, $callback),
        'maintenance' => fn () => app(TenantMaintenanceRunner::class)->run($tenant, $operation, $callback),
        'activation' => fn () => app(TenantAdoptionCoordinator::class)->activate(new TenantAdoptionPlan($tenant->value, 'sqlite', str_repeat('a', 64), str_repeat('b', 64)), $operation),
        'boundary' => function () use ($tenant): void {
            $context = Mockery::mock(TenantContext::class);
            $context->shouldReceive('snapshot')->andReturn(new TenantContextSnapshot(TenantContextMode::Tenant, $tenant));
            app()->instance(TenantContext::class, $context);
            app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('host.users', 'host', Setting::class));
            app(TenantBoundary::class)->attributes('host.users');
        },
    };
    expect($run)->toThrow(TenantConfigurationInvalid::class, 'integration')
        ->and($database->getQueryLog())->toBe([]);
})->with(['runner', 'maintenance', 'activation', 'boundary']);

it('rejects invalid published overlays with bounded diagnostic paths without executing source', function (string $case): void {
    $directory = sys_get_temp_dir().'/f6-configuration-'.bin2hex(random_bytes(5));
    mkdir($directory);
    file_put_contents($directory.'/tenancy.php', $case === 'resolver'
        ? "<?php return ['resolvers' => ['http' => []]];"
        : '<?php return ['.var_export(str_repeat('private-key-', 1000), true).' => true];');
    try {
        $inspector = new SuitePackageConfigurationInspector(app(Filesystem::class), app(SuiteModuleCatalog::class), dirname(__DIR__, 2), $directory);
        $findings = $inspector->inspect(['tenancy']);
        expect($findings)->not->toBeEmpty();
        if ($case === 'resolver') {
            expect(array_column($findings, 'path'))->toContain('tenancy.resolvers.http');
        }
        foreach ($findings as $finding) {
            expect(strlen($finding['path']))->toBeLessThan(200);
        }
    } finally {
        unlink($directory.'/tenancy.php');
        rmdir($directory);
    }
})->with(['resolver', 'long-key']);

it('keeps enabled incomplete composition bootable and unresolved for platform diagnostics', function (): void {
    config()->set('tenancy.enabled', true);
    (new TenancyServiceProvider(app()))->boot();
    expect(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
    $output = new BufferedOutput;
    expect(Artisan::call('nvl:tenancy:doctor', ['--json' => true], $output))->toBe(1);
    $report = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['configuration']['incompatible_families'])->toContain('settings', 'csv')
        ->and($report['configuration']['resources'])->toBe([]);
});

it('declares dependency closure through the ownership configuration public entry point', function (): void {
    $registry = app(TenantResourceRegistry::class);
    $registry->register(new TenantResourceDefinition('pages.pages', 'pages', Page::class));
    $registry->register(new TenantResourceDefinition('content.blocks', 'content', ContentBlock::class, allowsPlatformRows: true));
    config()->set('tenancy.resources.content', 'platform');
    $ownership = app(TenantOwnershipConfiguration::class);
    $ownership->requireCompatible('pages', 'content');
    expect(fn () => $ownership->validate())->toThrow(TenantConfigurationInvalid::class, 'compatible ownership');
});

it('allows code-backed catalog dependencies without reclassifying their fixed vocabulary', function (): void {
    $registry = app(TenantResourceRegistry::class);
    $registry->register(new TenantResourceDefinition('pages.pages', 'pages', Page::class));
    $registry->register(new TenantResourceDefinition('content.catalog', 'content', ContentBlock::class, TenantResourceKind::Platform));
    $ownership = app(TenantOwnershipConfiguration::class);
    $ownership->requireCompatible('pages', 'content');
    $ownership->validate();
    expect($ownership->mode($registry->get('content.catalog')))->toBe('platform');
});
