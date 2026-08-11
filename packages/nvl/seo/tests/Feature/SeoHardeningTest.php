<?php

declare(strict_types=1);

use Illuminate\Cache\ApcStore;
use Illuminate\Cache\ApcWrapper;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nvl\Seo\Actions\ImportSeoProfilesAction;
use Nvl\Seo\Actions\SyncSeoProfileAction;
use Nvl\Seo\Actions\SyncSeoRedirectAction;
use Nvl\Seo\Contracts\SeoImportSource;
use Nvl\Seo\Contracts\SitemapArtifactStore;
use Nvl\Seo\Contracts\SitemapSource;
use Nvl\Seo\Data\Import\SeoImportPageData;
use Nvl\Seo\Data\Import\SeoImportRecordData;
use Nvl\Seo\Data\Mutations\SeoProfilePayload;
use Nvl\Seo\Data\Mutations\SeoRedirectPayload;
use Nvl\Seo\Data\SitemapEntry;
use Nvl\Seo\Definitions\Tables\SeoTables;
use Nvl\Seo\Events\SeoProfileChanged;
use Nvl\Seo\Exceptions\InvalidSeoMutationException;
use Nvl\Seo\Exceptions\SeoRedirectLoopException;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Models\SeoRedirect;
use Nvl\Seo\Services\AbsoluteUrl;
use Nvl\Seo\Services\RobotsGenerator;
use Nvl\Seo\Services\SeoDoctor;
use Nvl\Seo\Services\SeoRedirectResolver;
use Nvl\Seo\Services\SitemapCache;
use Nvl\Seo\Services\SitemapGenerator;
use Nvl\Seo\Services\SitemapRegistry;
use Nvl\Seo\Tests\Fixtures\TestSeoOwner;
use Spatie\LaravelData\DataCollection;

it('enforces mutation invariants for programmatic action callers', function (): void {
    $owner = TestSeoOwner::query()->create(['name' => 'Invalid profile owner']);

    expect(fn () => app(SyncSeoProfileAction::class)->execute(
        $owner,
        SeoProfilePayload::from([
            'translations' => [
                'en' => [
                    'path' => '/unsafe/%252fsegment',
                    'canonicalUrl' => 'https://example.test/article#fragment',
                ],
            ],
        ]),
    ))->toThrow(InvalidSeoMutationException::class)
        ->and(fn () => app(SyncSeoRedirectAction::class)->execute(
            null,
            SeoRedirectPayload::from([
                'sourcePath' => '/legacy',
                'target' => '/current',
                'statusCode' => 305,
            ]),
        ))->toThrow(InvalidSeoMutationException::class)
        ->and(fn () => app(SyncSeoRedirectAction::class)->execute(
            null,
            SeoRedirectPayload::from([
                'sourcePath' => '/legacy-absolute',
                'target' => 'https://example.test/unsafe/%2fsegment',
            ]),
        ))->toThrow(InvalidSeoMutationException::class)
        ->and(SeoProfile::query()->count())->toBe(0)
        ->and(SeoRedirect::query()->count())->toBe(0);
});

it('canonicalizes unreserved path encoding and rejects malformed encoding', function (): void {
    $owner = TestSeoOwner::query()->create(['name' => 'Encoded path owner']);
    $profile = app(SyncSeoProfileAction::class)->execute(
        $owner,
        SeoProfilePayload::from([
            'translations' => [
                'en' => ['path' => '/articles/%41udit%7epass'],
            ],
        ]),
    );

    expect($profile->getTranslation('en')?->getAttribute('path'))
        ->toBe('/articles/Audit~pass')
        ->and(fn () => app(SyncSeoProfileAction::class)->execute(
            TestSeoOwner::query()->create(['name' => 'Malformed encoded path']),
            SeoProfilePayload::from([
                'translations' => [
                    'en' => ['path' => '/articles/%invalid'],
                ],
            ]),
        ))
        ->toThrow(InvalidSeoMutationException::class);
});

it('rejects unknown programmatic translation fields without persisting a profile', function (): void {
    $owner = TestSeoOwner::query()->create(['name' => 'Unknown field owner']);

    expect(fn () => app(SyncSeoProfileAction::class)->execute(
        $owner,
        SeoProfilePayload::from([
            'translations' => [
                'en' => [
                    'path' => '/unknown-field',
                    'titel' => 'Misspelled title',
                ],
            ],
        ]),
    ))
        ->toThrow(InvalidSeoMutationException::class)
        ->and(SeoProfile::query()->count())->toBe(0);
});

it('rejects crawler identity urls with fragments or foreign origins', function (): void {
    expect(fn () => new SitemapEntry('https://example.test/page#section'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SitemapEntry(
            'https://example.test/page',
            priority: '1e-1',
        ))
        ->toThrow(InvalidArgumentException::class, 'decimal notation');

    config()->set('seo.sitemap.cache_seconds', 0);
    app(SitemapRegistry::class)->register(
        new class implements SitemapSource
        {
            public function entries(string $scope): iterable
            {
                yield new SitemapEntry('https://foreign.example.test/page');
            }
        },
        'test.foreign-origin',
    );

    expect(fn () => app(SitemapGenerator::class)->generate())
        ->toThrow(LogicException::class, 'does not share the sitemap origin');
});

it('rejects base urls containing query strings or fragments', function (): void {
    config()->set('seo.site.base_url', 'https://example.test/base?tenant=one');

    expect(app(AbsoluteUrl::class)->resolve('/page'))->toBeNull();

    config()->set('seo.site.base_url', 'https://example.test/base#content');

    expect(app(AbsoluteUrl::class)->resolve('/page'))->toBeNull();
});

it('isolates sitemap cache identities by configured site url', function (): void {
    $cache = app(SitemapCache::class);
    $firstSiteKey = $cache->key('default');
    config()->set('seo.site.base_url', 'https://second.example.test');

    expect($cache->key('default'))->not->toBe($firstSiteKey);
});

it('enforces sitemap path scope for same-origin source entries', function (): void {
    config()->set([
        'seo.routes.sitemap_path' => 'maps/sitemap.xml',
        'seo.routes.sitemap_chunk_path' => 'maps/sitemap-{chunk}.xml',
        'seo.sitemap.cache_seconds' => 0,
    ]);
    app(SitemapRegistry::class)->register(
        new class implements SitemapSource
        {
            public function entries(string $scope): iterable
            {
                yield new SitemapEntry('https://example.test/outside');
            }
        },
        'test.outside-path-scope',
    );

    expect(fn () => app(SitemapGenerator::class)->generate())
        ->toThrow(LogicException::class, 'outside sitemap path scope');
});

it('requires atomic lock support before generating cached sitemaps', function (): void {
    $cache = new CacheRepository(new ApcStore(new class extends ApcWrapper
    {
        /**
         * Simulate an empty APC cache without requiring the extension.
         *
         * @param  string  $key
         */
        public function get($key): mixed
        {
            return null;
        }
    }));
    $artifacts = app(SitemapArtifactStore::class);
    $generator = new SitemapGenerator(
        app(SitemapRegistry::class),
        new SitemapCache($cache, $artifacts),
        $cache,
        $artifacts,
        app(AbsoluteUrl::class),
    );

    expect(fn () => $generator->generate())
        ->toThrow(LogicException::class, 'atomic lock support');
});

it('self heals cached sitemap manifests whose published artifact disappeared', function (): void {
    Storage::fake('seo-healing');
    config()->set('seo.sitemap.disk', 'seo-healing');
    $cache = app(SitemapCache::class);
    $generator = app(SitemapGenerator::class);
    $originalNamespace = $cache->namespace('default');
    $originalArtifact = "nvl-seo/sitemaps/{$originalNamespace}/chunk-1.xml";

    $generator->generate();
    Storage::disk('seo-healing')->delete($originalArtifact);

    expect(Storage::disk('seo-healing')->exists($originalArtifact))->toBeFalse();

    $xml = $generator->generate();
    $recoveredNamespace = $cache->namespace('default');
    $recoveredArtifact = "nvl-seo/sitemaps/{$recoveredNamespace}/chunk-1.xml";

    expect($xml)->toContain('<urlset')
        ->and($recoveredNamespace)->not->toBe($originalNamespace)
        ->and(Storage::disk('seo-healing')->exists($recoveredArtifact))->toBeTrue();
});

it('keeps committed profile writes successful when sitemap invalidation fails', function (): void {
    $owner = TestSeoOwner::query()->create(['name' => 'Cache failure owner']);
    $cacheRepository = new CacheRepository(new class extends ArrayStore
    {
        public function increment($key, $value = 1): bool
        {
            return false;
        }
    });
    $cache = new SitemapCache(
        $cacheRepository,
        app(SitemapArtifactStore::class),
    );
    app()->instance(SitemapCache::class, $cache);
    Exceptions::fake();
    Event::fake([SeoProfileChanged::class]);

    $profile = app(SyncSeoProfileAction::class)->execute(
        $owner,
        SeoProfilePayload::from([
            'translations' => [
                'en' => [
                    'path' => '/cache-failure',
                    'title' => 'Cache failure',
                ],
            ],
        ]),
    );

    expect($profile->exists)->toBeTrue()
        ->and(SeoProfile::query()->whereKey($profile->id)->exists())->toBeTrue();
    Event::assertDispatched(SeoProfileChanged::class);
    Exceptions::assertReported(LogicException::class);

    $this->artisan('nvl:seo:sitemap:clear')
        ->assertFailed();
});

it('keeps non-default sitemap index and chunk requests in the requested scope', function (): void {
    config()->set([
        'seo.routes.sitemap_scopes' => ['wholesale'],
        'seo.sitemap.max_urls' => 1,
    ]);
    $owner = TestSeoOwner::query()->create(['name' => 'Scoped sitemap owner']);
    app(SyncSeoProfileAction::class)->execute(
        $owner,
        SeoProfilePayload::from([
            'translations' => [
                'en' => [
                    'path' => '/wholesale/product',
                    'title' => 'Wholesale product',
                ],
                'bg' => [
                    'path' => '/wholesale/bg/product',
                    'title' => 'Продукт на едро',
                ],
            ],
        ]),
        'wholesale',
    );

    $index = app(SitemapGenerator::class)->generate('wholesale');

    expect($index)->toContain(
        'sitemap-1.xml?scope=wholesale',
        'sitemap-2.xml?scope=wholesale',
    );
    $this->get('/sitemap-1.xml?scope=wholesale')
        ->assertOk()
        ->assertSee('https://example.test/wholesale/', false);
    $this->getJson('/sitemap-1.xml?scope[]=wholesale')
        ->assertUnprocessable();
    $this->getJson('/sitemap-1.xml?scope=unregistered')
        ->assertUnprocessable();
});

it('omits valid external canonical profiles from the built in sitemap', function (): void {
    $owner = TestSeoOwner::query()->create(['name' => 'External canonical owner']);
    app(SyncSeoProfileAction::class)->execute(
        $owner,
        SeoProfilePayload::from([
            'translations' => [
                'en' => [
                    'path' => '/duplicate',
                    'canonicalUrl' => 'https://canonical.example.org/original',
                    'title' => 'Duplicate',
                ],
                'bg' => [
                    'path' => '/local-copy',
                    'title' => 'Локално копие',
                ],
            ],
        ]),
    );

    $xml = app(SitemapGenerator::class)->generate();

    expect($xml)->toContain(
        '<urlset',
        '<loc>https://example.test/local-copy</loc>',
        'href="https://canonical.example.org/original"',
    )
        ->and($xml)->not->toContain(
            '<loc>https://canonical.example.org/original</loc>',
            '<loc>https://example.test/duplicate</loc>',
        );
});

it('treats unregistered runtime owner types as advisory until management is enabled', function (): void {
    $owner = TestSeoOwner::query()->create(['name' => 'Unregistered runtime owner']);
    app(SyncSeoProfileAction::class)->execute(
        $owner,
        SeoProfilePayload::from([
            'translations' => [
                'en' => ['path' => '/runtime-owner'],
            ],
        ]),
    );
    config()->set('seo.owners', []);
    $doctor = app(SeoDoctor::class);
    $advisory = collect($doctor->inspect())->firstWhere('key', 'configuration.owners');

    expect($advisory?->passed)->toBeFalse()
        ->and($advisory?->severity)->toBe('warning');

    config()->set('seo.management.enabled', true);
    $required = collect($doctor->inspect())->firstWhere('key', 'configuration.owners');

    expect($required?->passed)->toBeFalse()
        ->and($required?->severity)->toBe('error');
});

it('rejects import sources that exceed the requested bounded page', function (): void {
    $owner = TestSeoOwner::query()->create(['name' => 'Bounded import owner']);
    $record = new SeoImportRecordData(
        ownerAlias: 'article',
        ownerId: (string) $owner->getKey(),
        scope: null,
        profile: SeoProfilePayload::from([
            'translations' => [
                'en' => ['path' => '/bounded-import'],
            ],
        ]),
    );
    $source = new class($record) implements SeoImportSource
    {
        public function __construct(
            private readonly SeoImportRecordData $record,
        ) {}

        public function page(?string $cursor, int $limit): SeoImportPageData
        {
            return new SeoImportPageData(
                items: new DataCollection(
                    SeoImportRecordData::class,
                    [$this->record, $this->record],
                ),
                nextCursor: null,
            );
        }
    };

    expect(fn () => app(ImportSeoProfilesAction::class)->execute(
        $source,
        limit: 1,
    ))
        ->toThrow(LogicException::class, 'more than its requested')
        ->and(SeoProfile::query()->count())->toBe(0);
});

it('fails closed for invalid management middleware and doctor output formats', function (): void {
    config()->set('seo.management.middleware', []);

    expect(fn () => require __DIR__.'/../../routes/management.php')
        ->toThrow(InvalidArgumentException::class, 'non-empty middleware');

    $this->artisan('nvl:seo:doctor', ['--format' => 'yaml'])
        ->assertExitCode(Command::INVALID);
});

it('publishes sitemap xml to immutable filesystem artifacts and clears them atomically', function (): void {
    Storage::fake('seo-artifacts');
    config()->set('seo.sitemap.disk', 'seo-artifacts');
    $cache = app(SitemapCache::class);
    $namespace = $cache->namespace('default');

    $artifact = "nvl-seo/sitemaps/{$namespace}/chunk-1.xml";

    $this->artisan('nvl:seo:sitemap:warm')
        ->assertSuccessful();

    expect(Storage::disk('seo-artifacts')->exists($artifact))->toBeTrue();

    $this->artisan('nvl:seo:sitemap:clear')
        ->assertSuccessful();

    expect(Storage::disk('seo-artifacts')->exists($artifact))->toBeFalse()
        ->and($cache->namespace('default'))->not->toBe($namespace);

    $this->artisan('nvl:seo:sitemap:clear', ['--scope' => 'invalid scope'])
        ->assertExitCode(Command::INVALID);

    config()->set('seo.sitemap.cache_seconds', 0);
    $this->artisan('nvl:seo:sitemap:warm')
        ->assertExitCode(Command::INVALID);
});

it('prefers localized redirects and falls back to locale-neutral redirects', function (): void {
    $action = app(SyncSeoRedirectAction::class);
    $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/legacy',
        'target' => '/global-destination',
    ]));
    $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/legacy',
        'target' => '/localized-destination',
        'locale' => 'bg',
    ]));
    $resolver = app(SeoRedirectResolver::class);

    expect($resolver->resolve('/legacy', 'bg')?->target)->toBe('/localized-destination')
        ->and($resolver->resolve('/legacy', 'en')?->target)->toBe('/global-destination')
        ->and($resolver->resolve('/legacy')?->target)->toBe('/global-destination');
});

it('rejects direct and chained loops through same-site absolute urls', function (): void {
    $action = app(SyncSeoRedirectAction::class);

    expect(fn () => $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/self',
        'target' => 'https://example.test/self?campaign=legacy',
    ])))->toThrow(SeoRedirectLoopException::class);

    $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/return',
        'target' => '/origin',
    ]));

    expect(fn () => $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/origin',
        'target' => 'https://example.test/return',
    ])))->toThrow(SeoRedirectLoopException::class);

    $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/absolute-return',
        'target' => 'https://example.test/absolute-origin',
    ]));

    expect(fn () => $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/absolute-origin',
        'target' => '/absolute-return',
    ])))->toThrow(SeoRedirectLoopException::class);
});

it('increments a restored redirect revision exactly once', function (): void {
    $action = app(SyncSeoRedirectAction::class);
    $redirect = $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/restored',
        'target' => '/original-target',
        'metadata' => ['source' => 'legacy-import'],
        'expectedRevision' => 0,
    ]));
    $redirect->delete();
    $restored = $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/restored',
        'target' => '/current-target',
        'expectedRevision' => 0,
    ]));

    expect($restored->revision)->toBe($redirect->revision + 1)
        ->and($restored->target)->toBe('/current-target')
        ->and($restored->metadata)->toBe(['source' => 'legacy-import'])
        ->and($restored->trashed())->toBeFalse();
});

it('prunes only redirect tombstones outside the requested retention window', function (): void {
    $action = app(SyncSeoRedirectAction::class);
    $old = $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/old-deleted',
        'target' => '/destination',
    ]));
    $recent = $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/recently-deleted',
        'target' => '/destination',
    ]));
    $old->delete();
    $recent->delete();
    SeoRedirect::onlyTrashed()->whereKey($old->id)->update([
        'deleted_at' => now()->subDays(31),
    ]);

    $this->artisan('nvl:seo:redirects:prune', ['--days' => '30'])
        ->assertSuccessful();

    expect(SeoRedirect::withTrashed()->find($old->id))->toBeNull()
        ->and(SeoRedirect::withTrashed()->find($recent->id))->toBeInstanceOf(SeoRedirect::class);
});

it('rejects robots directive injection and oversized output', function (): void {
    config()->set('seo.robots.allow', ["/safe\r\nDisallow: /private"]);

    expect(fn () => app(RobotsGenerator::class)->generate())
        ->toThrow(LogicException::class, 'non-empty crawler path');

    config()->set([
        'seo.robots.allow' => ['/'],
        'seo.robots.maximum_bytes' => 20,
    ]);

    expect(fn () => app(RobotsGenerator::class)->generate())
        ->toThrow(LogicException::class, 'exceeds the configured')
        ->and(collect(app(SeoDoctor::class)->inspect())
            ->firstWhere('key', 'configuration.robots')?->passed)
        ->toBeFalse();
});

it('rolls package migrations back cleanly and fails loudly on an occupied table', function (): void {
    $this->artisan('migrate:rollback', ['--step' => 4, '--force' => true])
        ->assertSuccessful();

    expect(Schema::hasTable(SeoTables::Profiles))->toBeFalse()
        ->and(Schema::hasTable(SeoTables::I18n))->toBeFalse()
        ->and(Schema::hasTable(SeoTables::Redirects))->toBeFalse();

    Schema::create(SeoTables::Profiles, function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });

    expect(fn () => $this->artisan('migrate', ['--force' => true])->run())
        ->toThrow(QueryException::class, 'already exists');

    Schema::drop(SeoTables::Profiles);

    $this->artisan('migrate', ['--force' => true])
        ->assertSuccessful();

    expect(Schema::hasTable(SeoTables::Profiles))->toBeTrue()
        ->and(Schema::hasTable(SeoTables::I18n))->toBeTrue()
        ->and(Schema::hasTable(SeoTables::Redirects))->toBeTrue();
});
