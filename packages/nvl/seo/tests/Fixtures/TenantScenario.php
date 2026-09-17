<?php

declare(strict_types=1);

namespace Nvl\Seo\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Http\Request;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;
use RuntimeException;

/** Installs and exercises one complete two-tenant SEO graph. */
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
                    throw new TenantNotFound('Unknown SEO fixture tenant.');
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

            public function activate(array $payload): void { $this->enabled = true; }

            public function deactivate(): void { $this->enabled = false; }

            public function active(): bool { return $this->enabled; }

            public function data(): array { return []; }
        });
    }

    /** @param iterable<\Nvl\Tenancy\ValueObjects\TenantAssignment> $mappings */
    public static function install(iterable $mappings = []): self
    {
        $coordinator = app(TenantAdoptionCoordinator::class);
        $operation = new PlatformOperation('seo.fixture.adoption', 'test', 'pest');
        $plan = $coordinator->prepare(['seo-test-owners', 'seo'], $mappings, $operation);
        $done = false;
        for ($batch = 0; $batch < 100 && ! $done; $batch++) {
            $done = $coordinator->backfill($plan, 100, $operation);
        }
        if (! $done || ! $coordinator->verify($plan)->passed()) {
            throw new RuntimeException('SEO fixture adoption did not verify.');
        }
        $coordinator->activate($plan, $operation);
        app(MaintenanceMode::class)->deactivate();

        return new self;
    }

    public function runWithSite(string $tenant, Closure $callback): mixed
    {
        return app(TenantRunner::class)->run(new TenantId($tenant), function () use ($callback, $tenant): mixed {
            $previous = app('request');
            $site = self::site($tenant);
            $request = Request::create($site->canonicalOrigin.'/fixture');
            $request->attributes->set(TenantSiteContext::class, $site);
            app()->instance('request', $request);
            app()->instance(Request::class, $request);
            try {
                return $callback($site);
            } finally {
                app()->instance('request', $previous);
                app()->instance(Request::class, $previous);
            }
        });
    }

    public function owner(string $tenant, string $name): TenantSeoOwner
    {
        return $this->runWithSite($tenant, static function () use ($name): TenantSeoOwner {
            $owner = new TenantSeoOwner(['name' => $name]);
            $owner->forceFill(app(TenantBoundary::class)->attributes('test.seo-owners'));
            $owner->save();

            return $owner->refresh();
        });
    }

    public static function site(string $tenant): TenantSiteContext
    {
        return new TenantSiteContext(
            new TenantId($tenant),
            'default',
            $tenant === self::A ? 'https://a.seo.test' : 'https://b.seo.test',
        );
    }
}
