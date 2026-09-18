<?php

declare(strict_types=1);

use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Facades\Content;
use Nvl\Content\Tests\Fixtures\TenantScenario;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantContextMissing;

beforeEach(function (): void {
    $this->scenario = TenantScenario::install();
});

it('keeps code definitions platform-owned while tenant blocks reuse natural keys', function (): void {
    $create = static fn () => Content::createBlock(new CreateContentBlockData(
        definition: 'hero',
        key: 'homepage',
        scope: 'site',
        scopeKey: 'default',
        translations: ['en' => ['title' => 'Welcome']],
    ), ContentActorData::system());

    $a = $this->scenario->run(TenantScenario::A, $create);
    $b = $this->scenario->run(TenantScenario::B, $create);

    expect($a->tenant_id)->toBe(TenantScenario::A)
        ->and($b->tenant_id)->toBe(TenantScenario::B)
        ->and($a->key)->toBe($b->key);
});

it('fails closed without an admitted tenant', function (): void {
    expect(fn () => Content::createBlock(new CreateContentBlockData(
        definition: 'hero',
        key: 'unresolved',
        scope: 'site',
        scopeKey: 'default',
        translations: ['en' => ['title' => 'Denied']],
    ), ContentActorData::system()))->toThrow(TenantContextMissing::class);
});

it('keeps definition synchronization behind explicit platform execution', function (): void {
    $this->scenario->run(TenantScenario::A, function (): void {
        expect(fn () => app(SyncContentDefinitionsAction::class)->execute(
            ContentActorData::system(),
        ))->toThrow(TenantBoundaryViolation::class);
    });
});
