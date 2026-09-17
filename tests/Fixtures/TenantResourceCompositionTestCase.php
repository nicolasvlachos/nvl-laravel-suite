<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Providers\TaxonomyServiceProvider;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase;
use ReflectionClass;

/** Boots only the independent resource package closure with inert Auth. */
abstract class TenantResourceCompositionTestCase extends TestCase
{
    use DatabaseMigrations;

    public const string A = '00000000-0000-4000-8000-00000000000a';

    public const string B = '00000000-0000-4000-8000-00000000000b';

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            DataServiceProvider::class,
            FilterableServiceProvider::class,
            SupportServiceProvider::class,
            TenancyServiceProvider::class,
            TenantResourceCompositionServiceProvider::class,
            TranslatableServiceProvider::class,
            MediaServiceProvider::class,
            MetafieldsServiceProvider::class,
            TaxonomyServiceProvider::class,
        ];
    }

    /** Freeze the application tenant profile before provider registration. */
    protected function defineEnvironment($app): void
    {
        $owner = TenantResourceCompositionOwner::class;
        $app['config']->set([
            'app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'cache.default' => 'array',
            'filesystems.default' => 'tenant-disk',
            'filesystems.disks.tenant-disk' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/tenant-composition')],
            'media.disk' => 'tenant-disk',
            'media.file_types.txt' => 'text/plain',
            'media.group_types.document' => ['txt'],
            'media.routes.api_enabled' => false,
            'media.routes.assets_enabled' => false,
            'media.tenancy.owner_types' => [$owner],
            'media.allowed_associable_types' => [$owner],
            'metafields.owners.resource-owner' => [
                'model' => $owner,
                'label' => 'Resource owners',
                'supported_types' => array_map(static fn (MetafieldTypeEnum $type): string => $type->value, MetafieldTypeEnum::cases()),
                'sections' => ['general'],
                'runtime_status' => 'live',
            ],
            'metafields.reference_models.resource-owner' => $owner,
            'taxonomy.owners.resource-owner' => $owner,
            'taxonomy.taxonomies.category.model' => Term::class,
            'taxonomy.taxonomies.category.allowed_owners' => ['resource-owner'],
            'taxonomy.taxonomies.tag.model' => Term::class,
            'taxonomy.taxonomies.tag.allowed_owners' => ['resource-owner'],
            'translatable.locales' => ['en', 'bg'],
            'translatable.fallback_locales' => ['en'],
            'tenancy.enabled' => true,
            'tenancy.resources.media' => 'tenant',
            'tenancy.resources.metafields' => 'tenant',
            'tenancy.resources.taxonomy' => 'tenant',
            'tenancy.sharing.media' => 'none',
            'tenancy.sharing.metafields' => 'none',
            'tenancy.directory.driver' => 'host',
            'tenancy.directory.adapter' => null,
            'tenancy.access.platform' => null,
        ]);
        $app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            private bool $enabled = true;

            /** @var array<string, mixed> */
            private array $payload = [];

            public function activate(array $payload): void
            {
                $this->enabled = true;
                $this->payload = $payload;
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
                return $this->payload;
            }
        });
        $app->instance(TenantDirectory::class, new class implements TenantDirectory
        {
            public function find(TenantId $tenant): TenantDescriptor
            {
                if (! in_array($tenant->value, [TenantResourceCompositionTestCase::A, TenantResourceCompositionTestCase::B], true)) {
                    throw new TenantNotFound;
                }

                return new TenantDescriptor($tenant, TenantStatus::Active);
            }
        });
        $app->instance(PlatformAccess::class, new class implements PlatformAccess
        {
            public function authorize(PlatformOperation $operation): void
            {
                if ($operation->purpose !== 'fixture.adoption' || $operation->actorType !== 'test' || $operation->actorId !== 'fixture') {
                    throw new TenantBoundaryViolation;
                }
            }
        });
    }

    /** Load only the opt-in Tenancy core after ordinary package migrations. */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $provider = new ReflectionClass(TenancyServiceProvider::class);
        $this->loadMigrationsFrom(dirname($provider->getFileName()).'/../../database/migrations/tenancy');
    }
}
