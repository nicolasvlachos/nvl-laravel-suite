<?php

declare(strict_types=1);

use Nvl\Pages\Actions\CreatePageAction;
use Nvl\Pages\Actions\ResolvePageAction;
use Nvl\Pages\Data\Mutations\CreatePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Tests\Fixtures\TenantScenario;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

beforeEach(function (): void {
    $this->scenario = TenantScenario::install();
});

it('scopes a declared dynamic handler query before fetching its model', function (): void {
    $this->scenario->resource(TenantScenario::A, 'same', 'Tenant A record');
    $this->scenario->resource(TenantScenario::B, 'same', 'Tenant B record');

    foreach ([TenantScenario::A, TenantScenario::B] as $tenant) {
        $this->scenario->runWithSite($tenant, static fn () => app(CreatePageAction::class)->execute(
            new CreatePageData(
                key: 'pages.records',
                slug: 'records',
                kind: PageKind::Resource,
                resource: 'tenant-records.detail',
                status: PageStatus::Published,
                translations: ['en' => ['title' => 'Records']],
            ),
            PageActorData::system(),
        ));
    }

    $resolved = $this->scenario->runWithSite(TenantScenario::A, static fn () => app(ResolvePageAction::class)->execute(
        'records/same',
        'default',
        'en',
        PageActorData::anonymous(),
    ));

    expect($resolved->resource?->payload['title'])->toBe('Tenant A record');
});

it('partitions page keys sites and paths by tenant', function (): void {
    $create = static fn () => app(CreatePageAction::class)->execute(new CreatePageData(
        key: 'pages.about',
        slug: 'about',
        status: PageStatus::Published,
        translations: ['en' => ['title' => 'About']],
    ), PageActorData::system());

    $a = $this->scenario->runWithSite(TenantScenario::A, $create);
    $b = $this->scenario->runWithSite(TenantScenario::B, $create);

    expect($a->tenant_id)->toBe(TenantScenario::A)
        ->and($b->tenant_id)->toBe(TenantScenario::B)
        ->and($a->key)->toBe($b->key)
        ->and($a->path)->toBe($b->path);
});

it('rejects a foreign canonical parent before changing a tree', function (): void {
    $parent = $this->scenario->runWithSite(TenantScenario::A, static fn () => app(CreatePageAction::class)->execute(
        new CreatePageData('pages.parent', 'parent', translations: ['en' => ['title' => 'Parent']]),
        PageActorData::system(),
    ));

    $this->scenario->runWithSite(TenantScenario::B, static function () use ($parent): void {
        expect(fn () => app(CreatePageAction::class)->execute(
            new CreatePageData('pages.child', 'child', $parent->id, translations: ['en' => ['title' => 'Child']]),
            PageActorData::system(),
        ))->toThrow(TenantBoundaryViolation::class);
    });
});
