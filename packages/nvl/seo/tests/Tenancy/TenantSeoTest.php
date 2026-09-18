<?php

declare(strict_types=1);

use Nvl\Seo\Actions\SyncSeoProfileAction;
use Nvl\Seo\Actions\SyncSeoRedirectAction;
use Nvl\Seo\Data\Mutations\SeoProfilePayload;
use Nvl\Seo\Data\Mutations\SeoRedirectPayload;
use Nvl\Seo\Services\SeoMetadataResolver;
use Nvl\Seo\Services\SeoRedirectResolver;
use Nvl\Seo\Tests\Fixtures\TenantScenario;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

beforeEach(function (): void {
    $this->scenario = TenantScenario::install();
});

it('partitions profiles redirects fallback and hashes by tenant', function (): void {
    $ownerA = $this->scenario->owner(TenantScenario::A, 'A');
    $ownerB = $this->scenario->owner(TenantScenario::B, 'B');
    $payload = SeoProfilePayload::from([
        'translations' => ['en' => ['path' => '/same', 'title' => 'Same']],
    ]);
    $redirect = new SeoRedirectPayload('/old', '/same');

    [$profileA, $redirectA] = $this->scenario->runWithSite(TenantScenario::A, static fn () => [
        app(SyncSeoProfileAction::class)->execute($ownerA, $payload),
        app(SyncSeoRedirectAction::class)->execute(null, $redirect),
    ]);
    [$profileB, $redirectB] = $this->scenario->runWithSite(TenantScenario::B, static fn () => [
        app(SyncSeoProfileAction::class)->execute($ownerB, $payload),
        app(SyncSeoRedirectAction::class)->execute(null, $redirect),
    ]);

    expect($profileA->tenant_id)->toBe(TenantScenario::A)
        ->and($profileB->tenant_id)->toBe(TenantScenario::B)
        ->and($redirectA->source_hash)->not->toBe($redirectB->source_hash);

    $this->scenario->runWithSite(TenantScenario::A, static function (): void {
        expect(app(SeoRedirectResolver::class)->resolve('/old')?->target)->toBe('/same');
    });
});

it('canonically rejects a foreign owner even when the supplied model is preloaded', function (): void {
    $ownerA = $this->scenario->owner(TenantScenario::A, 'A');

    $this->scenario->runWithSite(TenantScenario::B, static function () use ($ownerA): void {
        expect(fn () => app(SyncSeoProfileAction::class)->execute(
            $ownerA,
            SeoProfilePayload::from(['translations' => ['en' => ['path' => '/foreign']]]),
        ))->toThrow(TenantBoundaryViolation::class);
    });
});

it('rejects an external image reference before an incapable resolver reads it', function (): void {
    $owner = $this->scenario->owner(TenantScenario::A, 'A image');
    $this->scenario->runWithSite(TenantScenario::A, static function () use ($owner): void {
        app(SyncSeoProfileAction::class)->execute($owner, SeoProfilePayload::from([
            'translations' => ['en' => [
                'path' => '/image',
                'title' => 'Image',
                'imageReference' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            ]],
        ]));

        expect(fn () => app(SeoMetadataResolver::class)->resolve($owner, 'en'))
            ->toThrow(TenantBoundaryViolation::class);
    });
});
