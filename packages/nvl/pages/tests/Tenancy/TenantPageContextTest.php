<?php

declare(strict_types=1);

use Nvl\Pages\Actions\CreatePageAction;
use Nvl\Pages\Data\Mutations\CreatePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Pages\Tests\Fixtures\TenantScenario;

beforeEach(function (): void {
    $this->scenario = TenantScenario::install();
    foreach ([TenantScenario::A, TenantScenario::B] as $tenant) {
        $this->scenario->runWithSite($tenant, static fn () => app(CreatePageAction::class)->execute(
            new CreatePageData(
                key: 'pages.about',
                slug: 'about',
                status: PageStatus::Published,
                translations: ['en' => ['title' => $tenant === TenantScenario::A ? 'About A' : 'About B']],
            ),
            PageActorData::system(),
        ));
    }
});

it('resolves tenant site before public Page binding and canonical URL work', function (): void {
    $configuration = config()->all();
    $a = $this->withHeaders([
        'X-Tenant-Id' => TenantScenario::B,
        'X-Site' => 'foreign',
        'X-Canonical-Origin' => 'https://b.pages.test',
    ])->getJson('https://a.pages.test/api/v1/pages/about?tenant_id='.TenantScenario::B.'&site=foreign');
    $b = $this->getJson('https://b.pages.test/api/v1/pages/about');

    $a->assertOk()->assertJsonPath('data.page.title', 'About A');
    $b->assertOk()->assertJsonPath('data.page.title', 'About B');
    expect((string) $a->json('data.page.url'))->toStartWith('https://a.pages.test')
        ->and((string) $b->json('data.page.url'))->toStartWith('https://b.pages.test')
        ->and(config()->all())->toBe($configuration);
});

it('fails closed for an unknown host without leaking another site', function (): void {
    $this->getJson('https://unknown.pages.test/api/v1/pages/about')
        ->assertNotFound();
});
