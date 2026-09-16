<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Nvl\Suite\Services\SuitePackageConfigurationInspector;
use Nvl\Suite\Support\SuiteModuleCatalog;
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
