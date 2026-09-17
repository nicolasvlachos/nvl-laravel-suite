<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Nvl\Suite\Services\SuitePackageConfigurationInspector;
use Nvl\Suite\Support\SuiteModuleCatalog;
use Symfony\Component\Yaml\Yaml;
use Tests\Fixtures\TenancyArchiveConsumer;

it('classifies Tenancy type discovery and configuration for consumers', function (): void {
    $catalog = new SuiteModuleCatalog(new Repository(['nvl-suite' => ['modules' => ['tenancy' => true]]]));
    expect($catalog->modules()['tenancy']['typescript'])->toBeTrue();
    expect($catalog->modules()['tenancy']['configuration'])->toMatchArray([
        'key' => 'tenancy', 'published' => 'tenancy.php', 'open_maps' => ['resources'],
        'merge_strategy' => 'deep-map-atomic-list',
    ]);
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir().'/nvl-tenancy-config-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->makeDirectory($directory);
    try {
        $inspector = new SuitePackageConfigurationInspector($filesystem, $catalog, $root, $directory);
        $filesystem->put($directory.'/tenancy.php', "<?php return ['resources' => ['host' => 'tenant']];");
        expect($inspector->inspect(['tenancy']))->toBe([]);
        $filesystem->put($directory.'/tenancy.php', "<?php return ['enabledd' => true];");
        expect(array_column($inspector->inspect(['tenancy']), 'path'))->toContain('tenancy.enabledd');
    } finally {
        $filesystem->deleteDirectory($directory);
    }
    $contracts = json_decode((string) file_get_contents($root.'/tools/package-contracts.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($contracts['packages']['tenancy']['symbols'])->toHaveKeys([
        'Nvl\\Tenancy\\Contracts\\TenantContext',
        'Nvl\\Tenancy\\Services\\TenantRunner',
        'Nvl\\Tenancy\\Services\\TenantBoundary',
        'Nvl\\Tenancy\\Services\\TenantAdoptionCoordinator',
    ]);
});

it('boots cached Tenancy archives with only the declared NVL dependency profile', function (bool $filterable): void {
    $result = TenancyArchiveConsumer::run($filterable);
    expect($result['packages'])->toBe($filterable ? ['nvl/data', 'nvl/filterable', 'nvl/support', 'nvl/tenancy'] : ['nvl/data', 'nvl/support', 'nvl/tenancy']);
    expect($result['source_paths'])->each->toBeTrue();
    expect($result['loader_local'])->toBeTrue();
    expect($result['prefixes'])->each->toBeIn([
        'Nvl\\Tenancy\\', 'Nvl\\Support\\', 'Nvl\\Data\\', 'Nvl\\Data\\Tests\\Fixtures\\',
        ...($filterable ? ['Nvl\\Filterable\\', 'Nvl\\Filterable\\Tests\\Fixtures\\'] : []),
    ]);
    expect($result['auth_absent'])->toBeTrue();
    expect($result['suite_absent'])->toBeTrue();
    expect($result['filterable_present'])->toBe($filterable);
    expect($result['cached'])->toBeTrue();
    expect($result['provider_loaded'])->toBeTrue();
    expect($result['mode'])->toBe('disabled');
    expect($result['type_packages'])->toContain('nvl/tenancy');
    expect($result['doctor_exit'])->toBe(0);
    expect($result['doctor']['configuration'])->toMatchArray(['enabled' => false, 'connection' => 'sqlite']);
    expect($result['tables'])->toBe([]);
    expect($result['projection'])->toBe(['name' => 'safe']);
    if ($filterable) {
        expect($result['or_ids'])->toBe($result['expected_ids']);
        expect($result['relation_ids'])->toBe($result['expected_relation_ids']);
    }
})->with(['minimal' => false, 'explicit Filterable' => true]);

it('records tenant-partitioned Activity facts from cached standalone archives without Auth', function (): void {
    $result = TenancyArchiveConsumer::runActivity();

    expect($result['packages'])->toBe(['nvl/activity', 'nvl/data', 'nvl/support', 'nvl/tenancy'])
        ->and($result['source_paths'])->each->toBeTrue()
        ->and($result['loader_local'])->toBeTrue()
        ->and($result['auth_absent'])->toBeTrue()
        ->and($result['suite_absent'])->toBeTrue()
        ->and($result['cached'])->toBeTrue()
        ->and($result['activity_provider_loaded'])->toBeTrue()
        ->and($result['tenancy_provider_loaded'])->toBeTrue()
        ->and($result['activity_ids'])->toBe($result['expected_activity_ids'])
        ->and($result['ownership_keys'])->toBe(['tenant:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'])
        ->and($result['ownership_schema'])->toBeTrue();
});

it('boots cached Translatable archives through Composer discovery without Auth or Suite', function (): void {
    $result = TenancyArchiveConsumer::runTranslatable();

    expect($result['packages'])->toBe(['nvl/data', 'nvl/support', 'nvl/tenancy', 'nvl/translatable'])
        ->and($result['source_paths'])->each->toBeTrue()
        ->and($result['loader_local'])->toBeTrue()
        ->and($result['auth_absent'])->toBeTrue()
        ->and($result['suite_absent'])->toBeTrue()
        ->and($result['cached'])->toBeTrue()
        ->and($result['provider_loaded'])->toBeTrue()
        ->and($result['translatable_provider_loaded'])->toBeTrue()
        ->and($result['provider_order'])->toBe([
            'Nvl\\Data\\Providers\\DataServiceProvider',
            'Nvl\\Support\\Providers\\SupportServiceProvider',
            'Nvl\\Tenancy\\Providers\\TenancyServiceProvider',
            'Nvl\\Translatable\\Providers\\TranslatableServiceProvider',
        ]);
});

it('keeps worker and race evidence in the existing database quality jobs', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');
    $postgres = json_encode($workflow['jobs']['postgresql'] ?? [], JSON_THROW_ON_ERROR);
    $mysql = json_encode($workflow['jobs']['mysql-family'] ?? [], JSON_THROW_ON_ERROR);

    expect($postgres)->toContain('redis:8.0-alpine', 'pdo_pgsql', 'redis', 'REDIS_HOST', 'translatable')
        ->and($mysql)->toContain('TranslationTenancySchemaTest.php');
});
