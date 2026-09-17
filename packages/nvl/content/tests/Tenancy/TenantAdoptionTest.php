<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentDefinition;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Tests\Fixtures\TenantScenario;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantId;

it('activates the complete constrained Content graph through the coordinator', function (): void {
    TenantScenario::install();

    expect(Schema::hasColumn((new ContentBlock)->getTable(), 'tenant_id'))->toBeTrue()
        ->and(Schema::hasColumn((new ContentPlacement)->getTable(), 'tenant_id'))->toBeTrue()
        ->and(app(TenantInstallationState::class)->assertUsable('content.blocks'))->toBeNull()
        ->and(app(TenantInstallationState::class)->assertUsable('content.placements'))->toBeNull();
});

it('backfills a reviewed legacy block before replacing natural uniqueness', function (): void {
    app(TenantRunner::class)->platform(
        new PlatformOperation('content.legacy.definition', 'test', 'pest'),
        static fn () => app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system()),
    );
    $definition = ContentDefinition::query()->where('key', 'hero')->firstOrFail();
    $id = (string) Str::uuid();
    DB::table((new ContentBlock)->getTable())->insert([
        'id' => $id,
        'definition_id' => $definition->id,
        'key' => 'legacy-hero',
        'scope' => 'site',
        'scope_key' => 'default',
        'status' => 'draft',
        'visibility' => 'public',
        'values' => '[]',
        'metadata' => '[]',
        'definition_version' => $definition->version,
        'definition_hash' => $definition->source_hash,
        'definition_schema' => json_encode($definition->schema, JSON_THROW_ON_ERROR),
        'revision' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $scenario = TenantScenario::install([
        new TenantAssignment('content.blocks', $id, new TenantId(TenantScenario::A)),
    ]);
    $block = $scenario->run(
        TenantScenario::A,
        static fn () => ContentBlock::query()->findOrFail($id),
    );

    expect($block->tenant_id)->toBe(TenantScenario::A)
        ->and($block->key)->toBe('legacy-hero');
});
