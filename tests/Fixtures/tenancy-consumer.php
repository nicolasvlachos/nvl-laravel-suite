<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Data\Tests\Fixtures\OwnershipProjectionData;
use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Services\EloquentFilterApplier;
use Nvl\Filterable\Tests\Fixtures\PredicateGroup;
use Nvl\Filterable\Tests\Fixtures\PredicateRecord;
use Nvl\Filterable\Tests\Fixtures\RelatedPredicateRecord;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Providers\TenancyServiceProvider;

$loader = require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$doctorExit = Artisan::call('nvl:tenancy:doctor', ['--json' => true]);
$doctor = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
$projection = OwnershipProjectionData::from([
    'name' => 'safe', 'tenantId' => 'forged', 'tenant_id' => 'forged', 'ownership_key' => 'forged',
])->toModelFiltered();
$prefixes = array_filter(array_keys($loader->getPrefixesPsr4()), static fn (string $prefix): bool => str_starts_with($prefix, 'Nvl\\'));
$sources = [];
foreach (['Nvl\\Tenancy\\Providers\\TenancyServiceProvider', 'Nvl\\Data\\Providers\\DataServiceProvider', 'Nvl\\Support\\Providers\\SupportServiceProvider'] as $class) {
    $sources[$class] = str_starts_with((new ReflectionClass($class))->getFileName(), __DIR__.'/vendor/nvl/');
}
$nvls = array_values(array_filter(InstalledVersions::getInstalledPackages(), static fn (string $name): bool => str_starts_with($name, 'nvl/')));
sort($nvls);
$result = [
    'packages' => $nvls,
    'loader_local' => array_keys(ClassLoader::getRegisteredLoaders()) === [__DIR__.'/vendor'],
    'source_paths' => $sources,
    'prefixes' => array_values($prefixes),
    'auth_absent' => ! class_exists('Nvl\\Auth\\Providers\\AuthServiceProvider'),
    'suite_absent' => ! class_exists('Nvl\\Suite\\SuiteServiceProvider'),
    'filterable_present' => class_exists(EloquentFilterApplier::class),
    'cached' => $app->configurationIsCached(),
    'provider_loaded' => $app->providerIsLoaded(TenancyServiceProvider::class),
    'mode' => $app->make(TenantContext::class)->snapshot()->mode->value,
    'type_packages' => array_column($app->make(TypeScriptSourceRegistry::class)->descriptors(), 'package'),
    'doctor_exit' => $doctorExit, 'doctor' => $doctor,
    'tables' => Schema::getTableListing(),
    'projection' => $projection,
];
if (($argv[1] ?? '') === 'filterable') {
    Schema::create('predicate_records', function (Blueprint $table): void {
        $table->id();
        $table->string('owner');
        $table->string('name');
    });
    Schema::create('predicate_groups', function (Blueprint $table): void {
        $table->id();
        $table->string('owner');
        $table->string('name');
    });
    Schema::create('related_predicate_records', function (Blueprint $table): void {
        $table->id();
        $table->string('owner');
        $table->string('name');
        $table->unsignedBigInteger('group_id');
    });
    $a = PredicateRecord::query()->create(['owner' => 'tenant-a', 'name' => 'shared-business-key']);
    PredicateRecord::query()->create(['owner' => 'tenant-b', 'name' => 'shared-business-key']);
    $schema = new FilterSchema([new FilterDefinition('search', 'name', handler: static fn (Builder $query, FilterCriterion $criterion): Builder => $query->orWhere('name', $criterion->value))], []);
    $applier = $app->make(EloquentFilterApplier::class);
    $result['or_ids'] = $applier->apply(PredicateRecord::query()->where('owner', 'tenant-a'), new FilterSet([new FilterCriterion('search', FilterOperator::Equals, 'shared-business-key')]), $schema)->pluck('id')->all();
    $result['expected_ids'] = [$a->id];
    $groupA = PredicateGroup::query()->create(['owner' => 'tenant-a', 'name' => 'shared']);
    $groupB = PredicateGroup::query()->create(['owner' => 'tenant-b', 'name' => 'shared']);
    $related = RelatedPredicateRecord::query()->create(['owner' => 'tenant-a', 'name' => 'shared', 'group_id' => $groupA->id]);
    RelatedPredicateRecord::query()->create(['owner' => 'tenant-a', 'name' => 'corrupt', 'group_id' => $groupB->id]);
    RelatedPredicateRecord::query()->create(['owner' => 'tenant-b', 'name' => 'shared', 'group_id' => $groupB->id]);
    $schema = new FilterSchema([new FilterDefinition('group', 'group.name', handler: static fn (Builder $query, FilterCriterion $criterion): Builder => $query->orWhereHas('group', static fn (Builder $related): Builder => $related->where('name', $criterion->value)))], []);
    $result['relation_ids'] = $applier->apply(RelatedPredicateRecord::query()->where('owner', 'tenant-a'), new FilterSet([new FilterCriterion('group', FilterOperator::Equals, 'shared')]), $schema)->pluck('id')->all();
    $result['expected_relation_ids'] = [$related->id];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
