<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Services\ActivityReadService;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Support\ActivitySubjectReference;
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
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Translatable\Providers\TranslatableServiceProvider;

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
if (($argv[1] ?? '') === 'translatable') {
    $sources[TranslatableServiceProvider::class] = str_starts_with(
        (new ReflectionClass(TranslatableServiceProvider::class))->getFileName(),
        __DIR__.'/vendor/nvl/',
    );
}
$resourceProviders = [
    'media' => 'Nvl\\Media\\Providers\\MediaServiceProvider',
    'metafields' => 'Nvl\\Metafields\\Providers\\MetafieldsServiceProvider',
    'taxonomy' => 'Nvl\\Taxonomy\\Providers\\TaxonomyServiceProvider',
];
$resourceMode = $argv[1] ?? '';
if (isset($resourceProviders[$resourceMode])) {
    $provider = $resourceProviders[$resourceMode];
    $sources[$provider] = str_starts_with((new ReflectionClass($provider))->getFileName(), __DIR__.'/vendor/nvl/');
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
if (isset($resourceProviders[$resourceMode])) {
    $result['resource_provider_loaded'] = $app->providerIsLoaded($resourceProviders[$resourceMode]);
    $result['route_cached'] = $app->routesAreCached();
    $result['resource_tables_absent'] = array_filter(
        Schema::getTableListing(),
        static fn (string $table): bool => str_contains($table, 'media')
            || str_contains($table, 'metafield')
            || str_contains($table, 'term'),
    ) === [];
}
if (($argv[1] ?? '') === 'translatable') {
    $providers = array_keys($app->getLoadedProviders());
    $result['translatable_provider_loaded'] = $app->providerIsLoaded(TranslatableServiceProvider::class);
    $result['provider_order'] = array_values(array_filter(
        $providers,
        static fn (string $provider): bool => in_array($provider, [
            'Nvl\\Support\\Providers\\SupportServiceProvider',
            'Nvl\\Data\\Providers\\DataServiceProvider',
            TenancyServiceProvider::class,
            TranslatableServiceProvider::class,
        ], true),
    ));
}
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
if (($argv[1] ?? '') === 'activity') {
    $tenantA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $tenantB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    $app->make(Repository::class)->set([
        'tenancy.enabled' => true,
        'tenancy.profile' => 'application',
        'tenancy.resources' => ['activity' => 'tenant'],
    ]);
    $app->instance(TenantDirectory::class, new class($tenantA, $tenantB) implements TenantDirectory
    {
        public function __construct(
            private readonly string $tenantA,
            private readonly string $tenantB,
        ) {}

        public function find(TenantId $id): TenantDescriptor
        {
            if (! in_array($id->value, [$this->tenantA, $this->tenantB], true)) {
                throw new RuntimeException('Unknown archive-consumer tenant.');
            }

            return new TenantDescriptor($id, TenantStatus::Active);
        }
    });
    $app->instance(PlatformAccess::class, new class implements PlatformAccess
    {
        public function authorize(PlatformOperation $operation): void {}
    });
    $app->instance(MaintenanceMode::class, new class implements MaintenanceMode
    {
        private bool $enabled = true;

        public function activate(array $payload): void
        {
            $this->enabled = true;
        }

        public function deactivate(): void
        {
            $this->enabled = false;
        }

        public function active(): bool
        {
            return $this->enabled;
        }

        public function data(): array
        {
            return [];
        }
    });
    Artisan::call('migrate', [
        '--path' => __DIR__.'/vendor/nvl/tenancy/database/migrations/tenancy',
        '--realpath' => true,
        '--force' => true,
    ]);
    Artisan::call('migrate', [
        '--path' => __DIR__.'/vendor/nvl/activity/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);
    $coordinator = $app->make(TenantAdoptionCoordinator::class);
    $operation = new PlatformOperation('archive-consumer-adoption', 'test', 'standalone');
    $plan = $coordinator->prepare(['activity'], [], $operation);
    $done = false;
    for ($batch = 0; $batch < 100 && ! $done; $batch++) {
        $done = $coordinator->backfill($plan, 100, $operation);
    }
    if (! $done || ! $coordinator->verify($plan)->passed()) {
        throw new RuntimeException('Activity archive adoption did not verify.');
    }
    $coordinator->activate($plan, $operation);
    $runner = $app->make(TenantRunner::class);
    $subject = new ActivitySubjectReference('archive_subject', 'same-id');
    $record = static fn (): ActivityLog => app(ActivityRecorder::class)
        ->recordForSubjectReference($subject, 'updated');
    $a = $runner->run(new TenantId($tenantA), $record);
    $b = $runner->run(new TenantId($tenantB), $record);
    $rows = $runner->run(
        new TenantId($tenantA),
        static fn () => app(ActivityReadService::class)
            ->forSubjectKey('archive_subject', 'same-id'),
    );
    $sources[ActivityServiceProvider::class] = str_starts_with(
        (new ReflectionClass(ActivityServiceProvider::class))->getFileName(),
        __DIR__.'/vendor/nvl/',
    );
    $result['source_paths'] = $sources;
    $result['activity_provider_loaded'] = $app->providerIsLoaded(ActivityServiceProvider::class);
    $result['tenancy_provider_loaded'] = $app->providerIsLoaded(TenancyServiceProvider::class);
    $result['activity_ids'] = $rows->modelKeys();
    $result['expected_activity_ids'] = [$a->getKey()];
    $result['foreign_activity_id'] = $b->getKey();
    $result['ownership_keys'] = $rows->pluck('ownership_key')->unique()->values()->all();
    $result['ownership_schema'] = Schema::hasColumns('activity_log', ['tenant_id', 'ownership_key'])
        && Schema::hasIndex('activity_log', 'activity_ownership_created_idx');
}
echo json_encode($result, JSON_THROW_ON_ERROR);
