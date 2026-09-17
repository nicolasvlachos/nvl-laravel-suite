<?php

declare(strict_types=1);

use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentCompositionSnapshotData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Content\Facades\Content;
use Nvl\Content\Services\CanonicalJson;
use Nvl\Content\Tests\Fixtures\TenantScenario;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

beforeEach(function (): void {
    $this->scenario = TenantScenario::install();
    $this->actor = ContentActorData::system();
});

it('converts a hash-valid legacy snapshot only under its canonical owner tenant', function (): void {
    $owner = $this->scenario->owner(TenantScenario::A, 'Legacy owner');
    $legacy = new ContentCompositionSnapshotData(
        ownerType: 'tenant-owner',
        ownerId: $owner->id,
        group: 'default',
        blocks: [],
        version: app(CanonicalJson::class)->hash([
            'owner_type' => 'tenant-owner',
            'owner_id' => $owner->id,
            'group' => 'default',
            'blocks' => [],
        ]),
    );

    $adopted = $this->scenario->run(
        TenantScenario::A,
        static fn () => Content::adoptSnapshot($legacy),
    );

    expect($adopted->formatVersion)->toBe(2)
        ->and($adopted->tenantId)->toBe(TenantScenario::A)
        ->and($adopted->version)->not->toBe($legacy->version);
});

it('captures format two snapshots and denies foreign owners blocks and snapshots', function (): void {
    $ownerA = $this->scenario->owner(TenantScenario::A, 'A');
    $ownerB = $this->scenario->owner(TenantScenario::B, 'B');
    $blockA = $this->scenario->run(TenantScenario::A, function () {
        $block = Content::createBlock(new CreateContentBlockData(
            definition: 'hero',
            key: 'hero',
            scope: 'site',
            scopeKey: 'default',
            translations: ['en' => ['title' => 'Tenant A']],
        ), $this->actor);

        return Content::publishBlock($block, $block->revision, $this->actor);
    });

    $snapshot = $this->scenario->run(TenantScenario::A, function () use ($blockA, $ownerA) {
        Content::place($blockA, $ownerA, 'default', new PlaceContentBlockData('hero'), $this->actor);

        return Content::capture($ownerA, 'default', $this->actor, publishing: true);
    });

    expect($snapshot->formatVersion)->toBe(2)
        ->and($snapshot->tenantId)->toBe(TenantScenario::A)
        ->and($snapshot->version)->toHaveLength(64);

    $this->scenario->run(TenantScenario::B, function () use ($blockA, $ownerB, $snapshot): void {
        expect(fn () => Content::place($blockA, $ownerB, 'default', new PlaceContentBlockData('foreign'), $this->actor))
            ->toThrow(TenantBoundaryViolation::class)
            ->and(fn () => Content::renderSnapshot($snapshot, 'en', $this->actor))
            ->toThrow(TenantBoundaryViolation::class);
    });
});
