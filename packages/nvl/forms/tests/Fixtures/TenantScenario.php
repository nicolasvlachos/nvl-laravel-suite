<?php

declare(strict_types=1);

namespace Nvl\Forms\Tests\Fixtures;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Http\Request;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Contracts\TenantSiteResolver;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;
use RuntimeException;

final class TenantScenario
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
                    throw new TenantNotFound('Unknown test tenant.');
                }

                return new TenantDescriptor($id, TenantStatus::Active);
            }
        });
        $app->instance(TenantSiteResolver::class, new class implements TenantSiteResolver
        {
            public function resolve(Request $request): TenantSiteContext
            {
                return match ($request->getHost()) {
                    'a.test' => new TenantSiteContext(new TenantId(TenantScenario::A), 'a', 'https://a.test'),
                    'b.test' => new TenantSiteContext(new TenantId(TenantScenario::B), 'b', 'https://b.test'),
                    default => throw new TenantNotFound('Unknown test site.'),
                };
            }
        });
        $app->instance(PlatformAccess::class, new class implements PlatformAccess
        {
            public function authorize(PlatformOperation $operation): void {}
        });
        $app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return [];
            }
        });
    }

    /** @param list<string> $packages */
    public static function activate(array $packages): void
    {
        $coordinator = app(TenantAdoptionCoordinator::class);
        $operation = new PlatformOperation('forms-test-adoption', 'test', 'pest');
        $plan = $coordinator->prepare($packages, [], $operation);
        $done = false;
        for ($batch = 0; $batch < 100 && ! $done; $batch++) {
            $done = $coordinator->backfill($plan, 100, $operation);
        }
        if (! $done || ! $coordinator->verify($plan)->passed()) {
            throw new RuntimeException('Forms tenant fixture adoption did not verify.');
        }
        $coordinator->activate($plan, $operation);
    }
}
