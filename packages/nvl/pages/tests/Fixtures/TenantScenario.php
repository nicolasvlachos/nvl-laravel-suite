<?php

declare(strict_types=1);

namespace Nvl\Pages\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Http\Request;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Data\ContentActorData;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Contracts\TenantSiteResolver;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;
use RuntimeException;

/** Real two-site fixture shared by Pages tenant and HTTP tests. */
final readonly class TenantScenario
{
    public const string A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public const string B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    public static function bind(Application $app): void
    {
        $app->instance(TenantDirectory::class, new class implements TenantDirectory
        {
            public function find(TenantId $id): TenantDescriptor
            {
                if (! in_array($id->value, [TenantScenario::A, TenantScenario::B], true)) {
                    throw new TenantNotFound('Unknown Pages fixture tenant.');
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

            /** @param array<string, mixed> $payload */
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

            /** @return array<string, mixed> */
            public function data(): array
            {
                return [];
            }
        });
        $app->instance(TenantSiteResolver::class, new class implements TenantSiteResolver
        {
            public function resolve(Request $request): TenantSiteContext
            {
                return match ($request->getHost()) {
                    'a.pages.test' => TenantScenario::site(TenantScenario::A),
                    'b.pages.test' => TenantScenario::site(TenantScenario::B),
                    default => throw new TenantNotFound('Unknown Pages fixture host.'),
                };
            }
        });
    }

    /** @param iterable<TenantAssignment> $mappings */
    public static function install(iterable $mappings = []): self
    {
        $coordinator = app(TenantAdoptionCoordinator::class);
        $operation = new PlatformOperation('pages.fixture.adoption', 'test', 'pest');
        $plan = $coordinator->prepare(['media', 'content', 'metafields', 'seo', 'page-test-resources', 'pages'], $mappings, $operation);
        $done = false;
        for ($batch = 0; $batch < 100 && ! $done; $batch++) {
            $done = $coordinator->backfill($plan, 100, $operation);
        }
        if (! $done || ! $coordinator->verify($plan)->passed()) {
            throw new RuntimeException('Pages fixture adoption did not verify.');
        }
        $coordinator->activate($plan, $operation);
        app(MaintenanceMode::class)->deactivate();
        app(TenantRunner::class)->platform(
            new PlatformOperation('pages.fixture.definitions', 'test', 'pest'),
            static fn () => app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system()),
        );

        return new self;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $tenant, Closure $callback): mixed
    {
        return app(TenantRunner::class)->run(new TenantId($tenant), $callback);
    }

    /**
     * @template T
     *
     * @param  Closure(TenantSiteContext=): T  $callback
     * @return T
     */
    public function runWithSite(string $tenant, Closure $callback): mixed
    {
        return $this->run($tenant, function () use ($callback, $tenant): mixed {
            $previous = app('request');
            $site = self::site($tenant);
            $request = Request::create($site->canonicalOrigin.'/fixture');
            $request->attributes->set(TenantSiteContext::class, $site);
            app()->instance('request', $request);
            try {
                return $callback($site);
            } finally {
                app()->instance('request', $previous);
            }
        });
    }

    public static function site(string $tenant): TenantSiteContext
    {
        return new TenantSiteContext(
            new TenantId($tenant),
            'default',
            $tenant === self::A ? 'https://a.pages.test' : 'https://b.pages.test',
        );
    }

    /** Create one registered application resource with server-owned tenant attributes. */
    public function resource(string $tenant, string $slug, string $title): TenantPageResource
    {
        return $this->runWithSite($tenant, static function () use ($slug, $title): TenantPageResource {
            $resource = new TenantPageResource(['slug' => $slug, 'title' => $title, 'is_public' => true]);
            $resource->forceFill(app(TenantBoundary::class)->attributes('test.page-resources'));
            $resource->save();

            return $resource->refresh();
        });
    }
}
