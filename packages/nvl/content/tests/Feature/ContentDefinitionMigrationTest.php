<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\UpdateContentBlockData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Enums\ContentRevisionEvent;
use Nvl\Content\Events\ContentBlockChanged;
use Nvl\Content\Exceptions\ContentDefinitionMigrationRequiredException;
use Nvl\Content\Exceptions\StaleContentException;
use Nvl\Content\Facades\Content;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentBlockTranslation;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Models\ContentRevision;
use Nvl\Content\Tests\Fixtures\TestContentOwner;

beforeEach(function (): void {
    app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system());
});

it('plans and atomically migrates block values translations revisions and events', function (): void {
    $actor = ContentActorData::system();
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'legacy-hero',
            scope: 'site',
            scopeKey: 'main-site',
            values: ['enabled' => true],
            translations: ['en' => ['title' => 'Legacy title']],
            metadata: ['source' => 'legacy'],
        ),
        $actor,
    );
    downgradeHeroBlockForMigration($block, 'Legacy headline');
    Event::fake([ContentBlockChanged::class]);

    expect(fn () => Content::updateBlock(
        $block->fresh(),
        new UpdateContentBlockData(expectedRevision: $block->revision),
        $actor,
    ))->toThrow(ContentDefinitionMigrationRequiredException::class);

    $plan = Content::planDefinitionMigrations($actor);

    expect($plan->totalPending)->toBe(1)
        ->and($plan->hasMore)->toBeFalse()
        ->and($plan->blocked)->toBe([])
        ->and($plan->ready)->toHaveCount(1)
        ->and($plan->ready[0]->versions)->toBe([1, 2])
        ->and($plan->ready[0]->expectedRevision)->toBe(1)
        ->and(json_encode($plan->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('Legacy headline');

    $result = Content::applyDefinitionMigrations($plan, $actor);
    $migrated = ContentBlock::query()
        ->with('translations')
        ->findOrFail($block->id);

    expect($result->applied)->toBeTrue()
        ->and($result->migrated)->toHaveCount(1)
        ->and($result->migrated[0]->previousRevision)->toBe(1)
        ->and($result->migrated[0]->revision)->toBe(2)
        ->and($migrated->definition_version)->toBe(2)
        ->and($migrated->revision)->toBe(2)
        ->and($migrated->metadata)->toBe(['source' => 'legacy'])
        ->and($migrated->translations->first()?->values)->toBe([
            'title' => 'Legacy headline',
        ])
        ->and(ContentRevision::query()
            ->where('content_block_id', $block->id)
            ->where('event', ContentRevisionEvent::Migrated->value)
            ->count())->toBe(1)
        ->and(Content::planDefinitionMigrations($actor)->totalPending)->toBe(0);
    Event::assertDispatched(
        ContentBlockChanged::class,
        static fn (ContentBlockChanged $event): bool => $event->blockId === $block->id
            && $event->event === ContentRevisionEvent::Migrated
            && $event->revision === 2,
    );
});

it('reports unsupported stored versions without exposing content values', function (): void {
    $actor = ContentActorData::system();
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'unsupported-version',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Never expose this value']],
        ),
        $actor,
    );
    $block->forceFill(['definition_version' => 3])->save();

    $plan = Content::planDefinitionMigrations($actor);
    $encoded = json_encode($plan->toArray(), JSON_THROW_ON_ERROR);

    expect($plan->ready)->toBe([])
        ->and($plan->blocked)->toHaveCount(1)
        ->and($plan->blocked[0]->code)->toBe('future_version')
        ->and($encoded)->not->toContain('Never expose this value');
    expect(fn () => Content::applyDefinitionMigrations($plan, $actor))
        ->toThrow(InvalidArgumentException::class);
});

it('authorizes definition migration planning explicitly', function (): void {
    config()->set(
        'content.authorization.callback',
        static fn (ContentAbility $ability): bool => $ability !== ContentAbility::MigrateDefinitions,
    );

    expect(fn () => Content::planDefinitionMigrations(
        new ContentActorData('member', 'unauthorized'),
    ))->toThrow(AuthorizationException::class);
});

it('returns a stable conflict when the management API receives an old block', function (): void {
    config()->set([
        'content.routes.management.enabled' => true,
        'content.routes.management.prefix' => 'api/content-migrations',
        'content.routes.management.name' => 'content.migrations.',
        'content.routes.management.middleware' => [],
    ]);
    require __DIR__.'/../../routes/api.php';
    app('router')->getRoutes()->refreshNameLookups();

    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'api-old-version',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'API old version']],
        ),
        ContentActorData::system(),
    );
    downgradeHeroBlockForMigration($block, 'API legacy title');

    expect(Route::has('content.migrations.blocks.update'))->toBeTrue();
    $this->patchJson("/api/content-migrations/blocks/{$block->id}", [
        'expectedRevision' => 1,
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'definition_migration_required')
        ->assertJsonPath('error.context.block_id', $block->id)
        ->assertJsonPath('error.context.stored_version', 1)
        ->assertJsonPath('error.context.current_version', 2);
});

it('rolls back the complete batch when a planned revision becomes stale', function (): void {
    $actor = ContentActorData::system();
    $blocks = collect(['atomic-a', 'atomic-b'])
        ->map(function (string $key) use ($actor): ContentBlock {
            $block = Content::createBlock(
                new CreateContentBlockData(
                    definition: 'hero',
                    key: $key,
                    scope: 'site',
                    scopeKey: 'main-site',
                    translations: ['en' => ['title' => $key]],
                ),
                $actor,
            );
            downgradeHeroBlockForMigration($block, $key);

            return $block;
        });
    $plan = Content::planDefinitionMigrations($actor);
    $staleTarget = $plan->ready[array_key_last($plan->ready)];
    ContentBlock::query()
        ->whereKey($staleTarget->blockId)
        ->increment('revision');
    Event::fake([ContentBlockChanged::class]);

    expect(fn () => Content::applyDefinitionMigrations($plan, $actor))
        ->toThrow(StaleContentException::class);

    expect(ContentBlock::query()
        ->whereIn('id', $blocks->pluck('id'))
        ->where('definition_version', 1)
        ->count())->toBe(2)
        ->and(ContentRevision::query()
            ->whereIn('content_block_id', $blocks->pluck('id'))
            ->where('event', ContentRevisionEvent::Migrated->value)
            ->count())->toBe(0);
    Event::assertNotDispatched(ContentBlockChanged::class);
});

it('rolls back definition migration when an existing placement region is removed', function (): void {
    $actor = ContentActorData::system();
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'legacy-placement-region',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Legacy placement region']],
        ),
        $actor,
    );
    $owner = TestContentOwner::query()->create(['name' => 'Legacy region owner']);
    ContentPlacement::query()->create([
        'content_block_id' => $block->id,
        'owner_type' => 'page',
        'owner_id' => $owner->id,
        'group' => 'default',
        'key' => 'legacy-region',
        'region' => 'removed-region',
    ]);
    downgradeHeroBlockForMigration($block, 'Legacy region headline');
    $plan = Content::planDefinitionMigrations($actor);

    expect(fn () => Content::applyDefinitionMigrations($plan, $actor))
        ->toThrow(InvalidArgumentException::class);

    expect($block->refresh()->definition_version)->toBe(1)
        ->and($block->revision)->toBe(1)
        ->and(ContentPlacement::query()->where('content_block_id', $block->id)->count())->toBe(1);
});

it('rolls back definition migration when placement overrides violate the new schema', function (): void {
    $actor = ContentActorData::system();
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'legacy-placement-overrides',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Legacy placement overrides']],
        ),
        $actor,
    );
    $owner = TestContentOwner::query()->create(['name' => 'Legacy overrides owner']);
    ContentPlacement::query()->create([
        'content_block_id' => $block->id,
        'owner_type' => 'page',
        'owner_id' => $owner->id,
        'group' => 'default',
        'key' => 'legacy-overrides',
        'region' => 'main',
        'overrides' => ['headline' => 'Legacy placement headline'],
    ]);
    downgradeHeroBlockForMigration($block, 'Legacy override headline');
    $plan = Content::planDefinitionMigrations($actor);

    expect(fn () => Content::applyDefinitionMigrations($plan, $actor))
        ->toThrow(InvalidArgumentException::class);

    expect($block->refresh()->definition_version)->toBe(1)
        ->and($block->revision)->toBe(1)
        ->and(ContentPlacement::query()
            ->where('content_block_id', $block->id)
            ->firstOrFail()
            ->overrides)->toBe(['headline' => 'Legacy placement headline']);
});

it('rolls back definition migration when the stored scope is no longer allowed', function (): void {
    $actor = ContentActorData::system();
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'legacy-definition-scope',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Legacy definition scope']],
        ),
        $actor,
    );
    downgradeHeroBlockForMigration($block, 'Legacy scope headline');
    $block->forceFill([
        'scope' => 'global',
        'scope_key' => '*',
    ])->save();
    $plan = Content::planDefinitionMigrations($actor);

    expect(fn () => Content::applyDefinitionMigrations($plan, $actor))
        ->toThrow(InvalidArgumentException::class);

    expect($block->refresh()->definition_version)->toBe(1)
        ->and($block->revision)->toBe(1)
        ->and($block->scope)->toBe('global')
        ->and($block->scope_key)->toBe('*');
});

it('migrates soft-deleted blocks without restoring them', function (): void {
    $actor = ContentActorData::system();
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'deleted-migration',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Deleted migration']],
        ),
        $actor,
    );
    Content::deleteBlock($block, $block->revision, $actor);
    $deleted = ContentBlock::withTrashed()->findOrFail($block->id);
    downgradeHeroBlockForMigration($deleted, 'Deleted legacy headline');

    $plan = Content::planDefinitionMigrations($actor);

    expect($plan->ready)->toHaveCount(1)
        ->and($plan->ready[0]->deleted)->toBeTrue()
        ->and($plan->ready[0]->expectedRevision)->toBe(2);

    Content::applyDefinitionMigrations($plan, $actor);
    $migrated = ContentBlock::withTrashed()
        ->with('translations')
        ->findOrFail($block->id);

    expect($migrated->trashed())->toBeTrue()
        ->and($migrated->definition_version)->toBe(2)
        ->and($migrated->revision)->toBe(3)
        ->and($migrated->translations->first()?->values)
        ->toBe(['title' => 'Deleted legacy headline']);
});

it('supports read-only command plans and one bounded applied batch', function (): void {
    $actor = ContentActorData::system();
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'command-migration',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Command migration']],
        ),
        $actor,
    );
    downgradeHeroBlockForMigration($block, 'Command headline');

    $this->artisan('nvl:content:definitions:migrate', [
        '--dry-run' => true,
        '--format' => 'json',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('"totalPending": 1');
    expect($block->fresh()?->definition_version)->toBe(1);

    $this->artisan('nvl:content:definitions:migrate', ['--format' => 'json'])
        ->assertSuccessful()
        ->expectsOutputToContain('"applied": true');
    expect($block->fresh()?->definition_version)->toBe(2);
});

it('reports pending definition migrations as an unhealthy deployment', function (): void {
    $actor = ContentActorData::system();
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'doctor-migration',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Doctor migration']],
        ),
        $actor,
    );
    downgradeHeroBlockForMigration($block, 'Doctor headline');
    $second = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'doctor-migration-second',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Doctor migration second']],
        ),
        $actor,
    );
    downgradeHeroBlockForMigration($second, 'Doctor headline second');

    $exitCode = Artisan::call('nvl:content:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('"definitions.block_versions_current": false')
        ->and($output)->toContain('"definitions.pending_migrations": 2');

    Content::applyDefinitionMigrations(
        Content::planDefinitionMigrations($actor),
        $actor,
    );

    $this->artisan('nvl:content:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertSuccessful()
        ->expectsOutputToContain('"definitions.block_versions_current": true');
});

function downgradeHeroBlockForMigration(
    ContentBlock $block,
    string $headline,
): void {
    $block->forceFill([
        'definition_version' => 1,
        'definition_hash' => str_repeat('a', 64),
    ])->save();
    ContentBlockTranslation::query()
        ->where('content_block_id', $block->id)
        ->where('locale', 'en')
        ->update(['values' => ['headline' => $headline]]);
}
