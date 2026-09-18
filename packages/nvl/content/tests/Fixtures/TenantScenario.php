<?php

declare(strict_types=1);

namespace Nvl\Content\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Data\ContentActorData;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use RuntimeException;

/** Installs and exercises a real two-tenant Content graph. */
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
                    throw new TenantNotFound('Unknown Content fixture tenant.');
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
    }

    /** @param iterable<TenantAssignment> $mappings */
    public static function install(iterable $mappings = []): self
    {
        $coordinator = app(TenantAdoptionCoordinator::class);
        $operation = new PlatformOperation('content.fixture.adoption', 'test', 'pest');
        $plan = $coordinator->prepare(['media', 'content-test-owners', 'content'], $mappings, $operation);
        $done = false;
        for ($batch = 0; $batch < 100 && ! $done; $batch++) {
            $done = $coordinator->backfill($plan, 100, $operation);
        }
        if (! $done || ! $coordinator->verify($plan)->passed()) {
            throw new RuntimeException('Content fixture adoption did not verify.');
        }
        $coordinator->activate($plan, $operation);
        app(MaintenanceMode::class)->deactivate();
        app(TenantRunner::class)->platform(
            new PlatformOperation('content.fixture.definitions', 'test', 'pest'),
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

    public function owner(string $tenant, string $name = 'Owner'): TenantContentOwner
    {
        return $this->run($tenant, static function () use ($name): TenantContentOwner {
            $owner = new TenantContentOwner(['name' => $name]);
            $owner->forceFill(app(TenantBoundary::class)->attributes('test.content-owners'));
            $owner->save();

            return $owner->refresh();
        });
    }
}
