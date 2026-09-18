<?php

declare(strict_types=1);

use Nvl\Seo\Contracts\SitemapSource;
use Nvl\Seo\Services\AbsoluteUrl;
use Nvl\Seo\Services\SitemapCache;
use Nvl\Seo\Services\SitemapRegistry;
use Nvl\Seo\Tests\Fixtures\TenantScenario;

beforeEach(function (): void {
    $this->scenario = TenantScenario::install();
});

it('derives URLs and sitemap cache identity from one verified tenant site', function (): void {
    $a = $this->scenario->runWithSite(TenantScenario::A, static fn () => [
        app(AbsoluteUrl::class)->resolve('/catalog'),
        app(SitemapCache::class)->capture('default'),
    ]);
    $b = $this->scenario->runWithSite(TenantScenario::B, static fn () => [
        app(AbsoluteUrl::class)->resolve('/catalog'),
        app(SitemapCache::class)->capture('default'),
    ]);

    expect($a[0])->toBe('https://a.seo.test/catalog')
        ->and($b[0])->toBe('https://b.seo.test/catalog')
        ->and($a[1]->tenantId)->toBe(TenantScenario::A)
        ->and($b[1]->tenantId)->toBe(TenantScenario::B)
        ->and($a[1]->key)->not->toBe($b[1]->key)
        ->and($a[1]->namespace)->not->toBe($b[1]->namespace);
});

it('invalidates only the captured tenant site identity', function (): void {
    $cache = app(SitemapCache::class);
    $a = $this->scenario->runWithSite(
        TenantScenario::A,
        static fn () => app(SitemapCache::class)->capture('default'),
    );
    $b = $this->scenario->runWithSite(
        TenantScenario::B,
        static fn () => app(SitemapCache::class)->capture('default'),
    );

    expect($cache->forgetCaptured($a))->toBeTrue();

    $aAfter = $this->scenario->runWithSite(
        TenantScenario::A,
        static fn () => app(SitemapCache::class)->capture('default'),
    );
    $bAfter = $this->scenario->runWithSite(
        TenantScenario::B,
        static fn () => app(SitemapCache::class)->capture('default'),
    );

    expect($aAfter->version)->toBe($a->version + 1)
        ->and($bAfter->version)->toBe($b->version)
        ->and($bAfter->key)->toBe($b->key);
});

it('resolves sitemap source objects freshly inside each tenant scope', function (): void {
    $a = $this->scenario->runWithSite(
        TenantScenario::A,
        static fn () => app(SitemapRegistry::class)->all()[0],
    );
    $b = $this->scenario->runWithSite(
        TenantScenario::B,
        static fn () => app(SitemapRegistry::class)->all()[0],
    );

    expect($a)->not->toBe($b);
});

it('rejects incompatible sitemap declarations when they are registered', function (): void {
    $source = new class implements SitemapSource
    {
        public function entries(string $scope): iterable
        {
            return [];
        }
    };
    $registry = app(SitemapRegistry::class);

    expect(fn () => $registry->register($source, 'test.legacy'))
        ->toThrow(InvalidArgumentException::class, 'is not tenant compatible')
        ->and(fn () => $registry->registerType(stdClass::class, 'test.invalid'))
        ->toThrow(InvalidArgumentException::class, 'must implement');
});
