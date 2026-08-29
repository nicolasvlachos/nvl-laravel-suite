<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Seo\Actions\ArchiveSeoProfileAction;
use Nvl\Seo\Actions\DeleteSeoProfileAction;
use Nvl\Seo\Actions\DuplicateSeoProfileAction;
use Nvl\Seo\Actions\ListSeoProfilesAction;
use Nvl\Seo\Actions\PreviewSeoProfileAction;
use Nvl\Seo\Actions\SeoProfileStatusAction;
use Nvl\Seo\Actions\SyncSeoProfileAction;
use Nvl\Seo\Actions\SyncSeoRedirectAction;
use Nvl\Seo\Contracts\SeoAuthorization;
use Nvl\Seo\Contracts\SitemapSource;
use Nvl\Seo\Contracts\StructuredDataProvider;
use Nvl\Seo\Data\Mutations\SeoProfilePayload;
use Nvl\Seo\Data\Mutations\SeoRedirectPayload;
use Nvl\Seo\Data\SeoImage;
use Nvl\Seo\Data\SeoProfileQuery;
use Nvl\Seo\Data\SitemapEntry;
use Nvl\Seo\Data\StructuredDataContextData;
use Nvl\Seo\Data\StructuredDataNodeData;
use Nvl\Seo\Definitions\Tables\SeoTables;
use Nvl\Seo\Enums\StructuredDataType;
use Nvl\Seo\Exceptions\InvalidSeoMutationException;
use Nvl\Seo\Exceptions\SeoPathConflictException;
use Nvl\Seo\Exceptions\SeoRedirectLoopException;
use Nvl\Seo\Exceptions\StaleSeoProfileException;
use Nvl\Seo\Exceptions\StaleSeoRedirectException;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Rules\ValidStructuredData;
use Nvl\Seo\Services\AbsoluteUrl;
use Nvl\Seo\Services\SeoDoctor;
use Nvl\Seo\Services\SeoManager;
use Nvl\Seo\Services\SeoMetadataResolver;
use Nvl\Seo\Services\SeoRedirectResolver;
use Nvl\Seo\Services\SeoRouteResolver;
use Nvl\Seo\Services\SitemapCache;
use Nvl\Seo\Services\SitemapGenerator;
use Nvl\Seo\Services\SitemapRegistry;
use Nvl\Seo\Services\StructuredDataBuilder;
use Nvl\Seo\Services\StructuredDataRegistry;
use Nvl\Seo\Support\SeoAuthorizationContext;
use Nvl\Seo\Tests\Fixtures\TestIntegerSeoOwner;
use Nvl\Seo\Tests\Fixtures\TestSeoOwner;
use Nvl\Translatable\Actions\SyncTranslationResourceAction;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Data\TranslationMutationData;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Services\TranslationResourceVersioner;

function seoOwner(string $name = 'Article'): TestSeoOwner
{
    return TestSeoOwner::query()->create(['name' => $name]);
}

function seoPayload(array $overrides = []): SeoProfilePayload
{
    return SeoProfilePayload::from(array_replace_recursive([
        'isIndexable' => true,
        'isFollowable' => true,
        'sitemapIncluded' => true,
        'sitemapPriority' => '0.8',
        'sitemapChangeFrequency' => 'weekly',
        'translations' => [
            'en' => [
                'path' => '/products/red-dress/',
                'title' => 'Red Dress',
                'description' => 'A red dress.',
                'imageUrl' => 'https://cdn.example.test/red.jpg',
                'imageAlt' => 'Red dress',
                'structuredData' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => 'Red Dress',
                ],
            ],
            'bg' => [
                'path' => '/bg/produkti/chervena-roklya',
                'title' => 'Червена рокля',
                'description' => 'Червена рокля.',
            ],
        ],
    ], $overrides));
}

it('registers migrations, type sources, and centralized translation management', function (): void {
    expect(Schema::hasTable(SeoTables::Profiles))->toBeTrue()
        ->and(Schema::hasTable(SeoTables::I18n))->toBeTrue()
        ->and(app(TranslationResourceRegistry::class)->has('seo.profiles'))->toBeTrue()
        ->and(collect(app(TypeScriptSourceRegistry::class)->all())
            ->contains(fn (string $path): bool => str_ends_with($path, '/packages/nvl/seo/src')))
        ->toBeTrue()
        ->and(app(StructuredDataRegistry::class)->keys())
        ->toContain('test.configured-resource');
});

it('atomically attaches localized SEO to any persisted model', function (): void {
    $owner = seoOwner();
    $profile = app(SyncSeoProfileAction::class)->execute($owner, seoPayload());

    expect($profile)->toBeInstanceOf(SeoProfile::class)
        ->and($profile->seoable_type)->toBe($owner->getMorphClass())
        ->and($profile->seoable_id)->toBe($owner->getKey())
        ->and($profile->translations)->toHaveCount(2)
        ->and($profile->getTranslation('en')?->getAttribute('path'))->toBe('/products/red-dress')
        ->and($owner->seoProfile()?->is($profile))->toBeTrue();
});

it('normalizes scopes and locales consistently across writes and route reads', function (): void {
    $owner = seoOwner();
    $profile = app(SyncSeoProfileAction::class)->execute(
        owner: $owner,
        data: seoPayload(),
        scope: ' Store.Front ',
    );

    expect($profile->scope)->toBe('store.front')
        ->and($owner->seoProfile('STORE.FRONT')?->is($profile))->toBeTrue()
        ->and(app(SeoRouteResolver::class)->resolve(
            '/products/red-dress',
            'EN',
            'STORE.FRONT',
        )?->is($profile))->toBeTrue()
        ->and(fn () => app(SyncSeoProfileAction::class)->execute(
            seoOwner('Invalid scope'),
            seoPayload(),
            'invalid scope',
        ))->toThrow(InvalidSeoMutationException::class);
});

it('supports explicit patch and replace translation semantics', function (): void {
    $owner = seoOwner();
    $action = app(SyncSeoProfileAction::class);
    $profile = $action->execute($owner, seoPayload());

    $action->execute($owner, SeoProfilePayload::from([
        'translations' => [
            'en' => ['title' => 'Updated title'],
        ],
    ]));
    $profile->refresh()->load('translations');

    expect($profile->translations)->toHaveCount(2)
        ->and($profile->getTranslation('en')?->getAttribute('title'))->toBe('Updated title')
        ->and($profile->getTranslation('en')?->getAttribute('description'))->toBe('A red dress.');

    $action->execute(
        $owner,
        SeoProfilePayload::from([
            'translations' => [
                'en' => ['title' => 'Only English'],
            ],
        ]),
        translationMode: TranslationSyncMode::Replace,
    );
    $profile->refresh()->load('translations');

    expect($profile->translations)->toHaveCount(1)
        ->and($profile->translations->first()?->locale)->toBe('en');
});

it('enforces unique normalized paths per scope and locale in the database', function (): void {
    $action = app(SyncSeoProfileAction::class);
    $action->execute(seoOwner('One'), seoPayload());

    expect(fn () => $action->execute(
        seoOwner('Two'),
        seoPayload([
            'translations' => [
                'en' => [
                    'path' => 'products//red-dress',
                    'title' => 'Duplicate',
                ],
                'bg' => [
                    'path' => '/bg/other',
                    'title' => 'Other',
                ],
            ],
        ]),
    ))->toThrow(SeoPathConflictException::class);
});

it('resolves canonical hreflang social robots and structured metadata', function (): void {
    $owner = seoOwner();
    app(SyncSeoProfileAction::class)->execute($owner, seoPayload([
        'isIndexable' => false,
        'maxSnippet' => 120,
    ]));

    $seo = app(SeoMetadataResolver::class)->resolve($owner, 'bg');

    expect($seo->title)->toBe('Червена рокля | Example')
        ->and($seo->canonicalUrl)->toBe('https://example.test/bg/produkti/chervena-roklya')
        ->and($seo->robots)->toContain('noindex', 'max-snippet:120')
        ->and($seo->alternates)->toHaveKeys(['en', 'bg', 'x-default'])
        ->and($seo->openGraph['og:title'])->toBe('Червена рокля')
        ->and($seo->openGraphLocales)->toBe(['en'])
        ->and($seo->twitter['twitter:card'])->toBe('summary_large_image');
});

it('falls back each localized field while preserving intentional empty values', function (): void {
    $owner = seoOwner();
    app(SyncSeoProfileAction::class)->execute($owner, seoPayload([
        'translations' => [
            'bg' => [
                'title' => null,
                'description' => '',
                'imageUrl' => null,
                'imageAlt' => null,
                'structuredData' => null,
            ],
        ],
    ]));

    $seo = app(SeoMetadataResolver::class)->resolve($owner, 'bg');

    expect($seo->title)->toBe('Red Dress | Example')
        ->and($seo->description)->toBeNull()
        ->and($seo->openGraph['og:image'])->toBe('https://cdn.example.test/red.jpg')
        ->and($seo->openGraph['og:image:alt'])->toBe('Red dress')
        ->and($seo->structuredData)->toHaveCount(1);
});

it('rejects non-http absolute urls and renders every configured default robots directive', function (): void {
    config()->set([
        'seo.defaults.robots.max_snippet' => 160,
        'seo.defaults.robots.max_video_preview' => 30,
    ]);

    $seo = app(SeoMetadataResolver::class)->resolve(seoOwner(), 'en');

    expect(app(AbsoluteUrl::class)->resolve('ftp://files.example.test/catalog.xml'))->toBeNull()
        ->and(app(AbsoluteUrl::class)->resolve('/catalog'))->toBe('https://example.test/catalog')
        ->and($seo->robots)->toContain(
            'max-snippet:160',
            'max-image-preview:large',
            'max-video-preview:30',
        );
});

it('rejects invalid crawler assets and protects structured data identity', function (): void {
    $builder = app(StructuredDataBuilder::class);
    $schema = $builder->schema('Article', [
        '@context' => 'https://malicious.example.test',
        '@type' => 'WrongType',
        'headline' => 'Safe headline',
    ]);

    expect($schema['@context'])->toBe('https://schema.org')
        ->and($schema['@type'])->toBe('Article')
        ->and(fn () => new SeoImage('/relative.jpg'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SitemapEntry('javascript:alert(1)'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $builder->breadcrumbs([]))
        ->toThrow(InvalidArgumentException::class);
});

it('composes resource-aware providers and editor overrides into one linked graph', function (): void {
    $registry = app(StructuredDataRegistry::class);
    $registry->register(
        key: 'catalog.product',
        resourceClass: TestSeoOwner::class,
        provider: new class implements StructuredDataProvider
        {
            public function provide(
                Model $resource,
                StructuredDataContextData $context,
            ): iterable {
                yield StructuredDataNodeData::make(
                    type: StructuredDataType::Product,
                    id: $context->canonicalUrl.'#product',
                    properties: [
                        'name' => $resource->getAttribute('name'),
                        'url' => $context->canonicalUrl,
                        'mainEntityOfPage' => ['@id' => $context->canonicalUrl.'#webpage'],
                        'brand' => [
                            '@type' => 'Organization',
                            'name' => 'Generated brand',
                        ],
                    ],
                );
            }
        },
        priority: 100,
    );
    $owner = seoOwner('Generated product name');
    app(SyncSeoProfileAction::class)->execute($owner, seoPayload([
        'translations' => [
            'en' => [
                'structuredData' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    '@id' => 'https://example.test/products/red-dress#product',
                    'name' => 'Editor product name',
                    'sku' => 'SKU-1',
                ],
            ],
        ],
    ]));

    $resolved = app(SeoMetadataResolver::class)->resolve($owner, 'en');
    $graph = $resolved->structuredData[0]['@graph'];
    $nodes = collect($graph)->keyBy('@id');

    expect($resolved->structuredData)->toHaveCount(1)
        ->and($resolved->structuredData[0]['@context'])->toBe('https://schema.org')
        ->and($nodes)->toHaveKeys([
            'https://example.test#website',
            'https://example.test/products/red-dress#webpage',
            'https://example.test/products/red-dress#product',
        ])
        ->and($nodes['https://example.test/products/red-dress#product']['name'])
        ->toBe('Editor product name')
        ->and($nodes['https://example.test/products/red-dress#product']['sku'])->toBe('SKU-1')
        ->and($nodes['https://example.test/products/red-dress#product']['brand']['name'])
        ->toBe('Generated brand');
});

it('validates JSON-LD graph grammar without restricting future schema types', function (): void {
    $failures = [];
    $rule = new ValidStructuredData;

    $rule->validate('structuredData', [
        '@context' => 'https://untrusted.example.test',
        '@type' => 'Product',
    ], static function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    expect($failures)->toHaveCount(1)
        ->and(fn () => app(StructuredDataRegistry::class)->register(
            'duplicate',
            TestSeoOwner::class,
            new class implements StructuredDataProvider
            {
                public function provide(
                    Model $resource,
                    StructuredDataContextData $context,
                ): iterable {
                    yield StructuredDataNodeData::make('FutureSchemaType');
                }
            },
        ))->not->toThrow(InvalidArgumentException::class)
        ->and(app(StructuredDataBuilder::class)->schema('FutureSchemaType', []))
        ->toHaveKey('@type', 'FutureSchemaType');
});

it('renders escaped head tags and script-safe json ld', function (): void {
    $owner = seoOwner();
    app(SyncSeoProfileAction::class)->execute($owner, seoPayload([
        'translations' => [
            'en' => [
                'title' => '<script>alert(1)</script>',
                'description' => '"quoted"',
                'structuredData' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'Thing',
                    'name' => '</script><script>alert(1)</script>',
                ],
            ],
        ],
    ]));

    $html = app(SeoManager::class)->for($owner, 'en')->toHtml();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->toContain('&quot;quoted&quot;')
        ->and($html)->toContain('property="og:locale:alternate" content="bg"')
        ->and($html)->toContain('\\u003C/script\\u003E')
        ->and($html)->not->toContain('</script><script>alert(1)</script>');
});

it('resolves routes, generates localized sitemap XML, and serves public files', function (): void {
    $owner = seoOwner();
    $profile = app(SyncSeoProfileAction::class)->execute($owner, seoPayload());

    expect(app(SeoRouteResolver::class)->resolve(
        'products/red-dress/',
        'en',
    )?->is($profile))->toBeTrue();

    $xml = app(SitemapGenerator::class)->generate();

    expect($xml)->toContain('<urlset')
        ->and($xml)->toContain('https://example.test/products/red-dress')
        ->and($xml)->toContain('hreflang="bg"');

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('content-type', 'application/xml; charset=UTF-8')
        ->assertSee('https://example.test/products/red-dress', false);
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=UTF-8')
        ->assertSee('Sitemap: https://example.test/sitemap.xml', false);
});

it('deletes profiles and cascades localized rows', function (): void {
    $owner = seoOwner();
    $profile = app(SyncSeoProfileAction::class)->execute($owner, seoPayload());

    expect(app(DeleteSeoProfileAction::class)->execute($profile))->toBeTrue()
        ->and(SeoProfile::query()->count())->toBe(0)
        ->and(DB::table(SeoTables::I18n)->count())->toBe(0);
});

it('rejects stale profile writes and excludes archived profiles from runtime resolution', function (): void {
    $owner = seoOwner();
    $action = app(SyncSeoProfileAction::class);
    $profile = $action->execute($owner, seoPayload());
    $revision = $profile->revision;

    $updated = $action->execute($owner, seoPayload([
        'expectedRevision' => $revision,
        'translations' => ['en' => ['title' => 'Fresh']],
    ]));

    expect(fn () => $action->execute($owner, seoPayload([
        'expectedRevision' => $revision,
        'translations' => ['en' => ['title' => 'Stale']],
    ])))->toThrow(StaleSeoProfileException::class);

    app(ArchiveSeoProfileAction::class)->execute($updated, true, $updated->revision);

    expect(app(SeoRouteResolver::class)->resolve('/products/red-dress', 'en'))->toBeNull();
});

it('provides typed management actions while management routes stay disabled', function (): void {
    app()->instance(SeoAuthorization::class, new class implements SeoAuthorization
    {
        public function authorize(SeoAuthorizationContext $context): void {}
    });
    $owner = seoOwner();
    $profile = app(SyncSeoProfileAction::class)->execute($owner, seoPayload());
    $target = seoOwner('Duplicate target');
    $duplicate = app(DuplicateSeoProfileAction::class)->execute($profile, $target, 'secondary');
    $page = app(ListSeoProfilesAction::class)->execute(new SeoProfileQuery(perPage: 10));
    $preview = app(PreviewSeoProfileAction::class)->execute($profile, 'en');
    $status = app(SeoProfileStatusAction::class)->execute();

    expect($duplicate->seoable_id)->toBe($target->getKey())
        ->and($page->total())->toBe(2)
        ->and($preview->title)->toBe('Red Dress | Example')
        ->and($status->toArray())->toBe(['active' => 2, 'archived' => 0, 'total' => 2])
        ->and(fn () => app(DuplicateSeoProfileAction::class)->execute(
            $profile,
            $target,
            'secondary',
        ))->toThrow(StaleSeoProfileException::class);

    $this->getJson('/api/v1/seo/profiles')->assertNotFound();
});

it('normalizes redirect identity and rejects unsupported locales and stale creates', function (): void {
    $action = app(SyncSeoRedirectAction::class);
    $redirect = $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/localized',
        'target' => '/destination',
        'locale' => 'EN',
        'expectedRevision' => 0,
    ]));

    expect($redirect->locale)->toBe('en')
        ->and(fn () => $action->execute(null, SeoRedirectPayload::from([
            'sourcePath' => '/localized',
            'target' => '/replacement',
            'locale' => 'en',
            'expectedRevision' => 0,
        ])))->toThrow(StaleSeoRedirectException::class)
        ->and(fn () => $action->execute(null, SeoRedirectPayload::from([
            'sourcePath' => '/unsupported',
            'target' => '/destination',
            'locale' => 'fr',
        ])))->toThrow(InvalidSeoMutationException::class);
});

it('ignores expired redirects while flattening a new chain', function (): void {
    $action = app(SyncSeoRedirectAction::class);
    $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/expired',
        'target' => '/old-target',
        'expiresAt' => now()->subMinute()->toAtomString(),
    ]));
    $redirect = $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/source',
        'target' => '/expired',
    ]));

    expect($redirect->target)->toBe('/expired');
});

it('preserves safe query strings and fragments on internal redirect targets', function (): void {
    $action = app(SyncSeoRedirectAction::class);
    $redirect = $action->execute(
        null,
        SeoRedirectPayload::from([
            'sourcePath' => '/campaign',
            'target' => '/landing/?utm_source=legacy#offer',
            'metadata' => ['source' => 'legacy-import'],
        ]),
    );
    $updated = $action->execute(
        $redirect,
        SeoRedirectPayload::from([
            'sourcePath' => '/campaign',
            'target' => '/landing/?utm_source=current#offer',
            'expectedRevision' => $redirect->revision,
        ]),
    );

    expect($updated->target)->toBe('/landing?utm_source=current#offer')
        ->and($updated->metadata)->toBe(['source' => 'legacy-import'])
        ->and(fn () => $action->execute(
            null,
            SeoRedirectPayload::from([
                'sourcePath' => '/self-query',
                'target' => '/self-query?campaign=legacy',
            ]),
        ))->toThrow(SeoRedirectLoopException::class)
        ->and(fn () => app(SyncSeoRedirectAction::class)->execute(
            null,
            SeoRedirectPayload::from([
                'sourcePath' => '/network-path',
                'target' => '//malicious.example.test/path',
            ]),
        ))->toThrow(InvalidSeoMutationException::class);
});

it('exposes an authorized alias-based management API with mandatory revisions', function (): void {
    config()->set([
        'seo.management.enabled' => true,
        'seo.management.path' => 'api/internal/seo',
        'seo.management.name' => 'internal.seo',
        'seo.management.middleware' => ['api'],
        'seo.authorization.ability' => 'manage-seo',
    ]);
    Gate::define(
        'manage-seo',
        static fn (
            ?Authenticatable $actor,
            SeoAuthorizationContext $context,
        ): bool => $context->owner?->getAttribute('name') !== 'Denied owner',
    );
    require __DIR__.'/../../routes/management.php';
    app('router')->getRoutes()->refreshNameLookups();
    $owner = seoOwner('Managed owner');

    expect(Route::has('internal.seo.profiles.index'))->toBeTrue()
        ->and(Route::has('nvl.seo.management.profiles.index'))->toBeFalse();

    $this->postJson('/api/internal/seo/profiles', [
        'ownerAlias' => 'article',
        'ownerId' => $owner->getKey(),
        'profile' => [
            'translations' => [
                'en' => [
                    'path' => '/misspelled',
                    'titel' => 'Misspelled title',
                ],
            ],
        ],
    ])->assertUnprocessable();

    $created = $this->postJson('/api/internal/seo/profiles', [
        'ownerAlias' => 'article',
        'ownerId' => $owner->getKey(),
        'profile' => [
            'translations' => [
                'en' => [
                    'path' => '/managed',
                    'title' => 'Managed title',
                ],
            ],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.ownerAlias', 'article')
        ->assertJsonPath('data.ownerMorphType', $owner->getMorphClass());
    $profileId = $created->json('data.id');
    $revision = $created->json('data.revision');

    expect($profileId)->toBeString()
        ->and($revision)->toBeInt();

    $this->postJson('/api/internal/seo/profiles', [
        'ownerAlias' => 'article',
        'ownerId' => $owner->getKey(),
        'profile' => [
            'expectedRevision' => $revision,
            'translations' => [
                'en' => ['title' => 'Create endpoint update'],
            ],
        ],
    ])->assertUnprocessable();

    $this->putJson("/api/internal/seo/profiles/{$profileId}", [
        'translations' => ['en' => ['title' => 'Missing revision']],
    ])->assertUnprocessable();

    expect(SeoProfile::query()->find($profileId))->toBeInstanceOf(SeoProfile::class);
    $this->putJson("/api/internal/seo/profiles/{$profileId}", [
        'expectedRevision' => $revision,
        'translations' => ['en' => ['title' => 'Updated title']],
        'titel' => 'Unsupported root field',
    ])->assertUnprocessable();
    $this->putJson("/api/internal/seo/profiles/{$profileId}", [
        'expectedRevision' => $revision,
        'translations' => ['en' => ['title' => 'Updated title']],
    ])
        ->assertOk()
        ->assertJsonPath('data.translations.en.title', 'Updated title');
    $this->putJson("/api/internal/seo/profiles/{$profileId}", [
        'expectedRevision' => $revision,
        'translations' => ['en' => ['title' => 'Stale title']],
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'stale_seo_profile')
        ->assertJsonPath('error.context.profileId', $profileId);

    $integerOwner = TestIntegerSeoOwner::query()->create([
        'name' => 'Integer managed owner',
    ]);
    $this->postJson('/api/internal/seo/profiles', [
        'ownerAlias' => 'integer-article',
        'ownerId' => $integerOwner->getKey(),
        'profile' => [
            'translations' => [
                'en' => [
                    'path' => '/managed-integer',
                    'title' => 'Managed integer owner',
                ],
            ],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.ownerAlias', 'integer-article')
        ->assertJsonPath('data.ownerId', (string) $integerOwner->getKey());

    $denied = seoOwner('Denied owner');
    $this->postJson('/api/internal/seo/profiles', [
        'ownerAlias' => 'article',
        'ownerId' => $denied->getKey(),
        'profile' => ['translations' => ['en' => ['title' => 'Denied']]],
    ])->assertForbidden();
});

it('accepts valid schema types that begin with digits', function (): void {
    $failures = [];
    $rule = new ValidStructuredData;
    $rule->validate('structuredData', [
        '@context' => 'https://schema.org',
        '@type' => '3DModel',
        'name' => 'Product model',
    ], static function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    expect($failures)->toBeEmpty()
        ->and(StructuredDataNodeData::make('3DModel')->type)->toBe('3DModel');
});

it('requires SEO domain actions for centralized translation mutations', function (): void {
    $profile = app(SyncSeoProfileAction::class)->execute(seoOwner(), seoPayload());
    $revision = $profile->revision;
    $cache = app(SitemapCache::class);
    $key = $cache->key($profile->scope);
    $version = app(TranslationResourceVersioner::class)->version($profile);

    expect(fn () => app(SyncTranslationResourceAction::class)->execute(
        resourceKey: 'seo.profiles',
        id: $profile->id,
        mutation: new TranslationMutationData(
            translations: ['en' => ['title' => 'Central write']],
            expectedVersion: $version,
        ),
        actor: TranslationActorData::system(),
    ))->toThrow(TranslationResourceException::class, 'package domain action')
        ->and($profile->fresh()?->revision)->toBe($revision)
        ->and($cache->key($profile->scope))->toBe($key);
});

it('caches sitemap builds, returns etags, and rejects duplicate source keys', function (): void {
    $source = new class implements SitemapSource
    {
        public int $scans = 0;

        public function entries(string $scope): iterable
        {
            $this->scans++;

            yield new SitemapEntry('https://example.test/additional');
        }
    };
    $registry = app(SitemapRegistry::class);
    $registry->register($source, 'test.counting');
    $generator = app(SitemapGenerator::class);
    $generator->generate();
    $generator->generate();
    $response = $this->get('/sitemap.xml')->assertOk();
    $etag = $response->headers->get('ETag');

    expect($source->scans)->toBe(1)
        ->and($etag)->toBeString()
        ->and(fn () => $registry->register($source, 'test.counting'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register($source, '   '))
        ->toThrow(InvalidArgumentException::class);

    $this->withHeader('If-None-Match', $etag)->get('/sitemap.xml')
        ->assertStatus(304);
});

it('enforces the configured uncompressed sitemap byte limit', function (): void {
    config()->set([
        'seo.sitemap.cache_seconds' => 0,
        'seo.sitemap.max_bytes' => 100,
    ]);
    app(SyncSeoProfileAction::class)->execute(seoOwner(), seoPayload());

    expect(fn () => app(SitemapGenerator::class)->generate())
        ->toThrow(LogicException::class, 'byte artifact limit');
});

it('flattens redirect chains, rejects loops and stale revisions, and records hits', function (): void {
    $action = app(SyncSeoRedirectAction::class);
    $first = $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/old',
        'target' => '/new',
    ]));
    $second = $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/older',
        'target' => '/old',
    ]));

    expect($second->target)->toBe('/new')
        ->and(app(SeoRedirectResolver::class)->resolve('/older')?->target)->toBe('/new')
        ->and($second->fresh()?->hit_count)->toBe(1)
        ->and(fn () => $action->execute($first, SeoRedirectPayload::from([
            'sourcePath' => '/old',
            'target' => '/newer',
            'expectedRevision' => $first->revision - 1,
        ])))->toThrow(StaleSeoRedirectException::class)
        ->and(fn () => $action->execute(null, SeoRedirectPayload::from([
            'sourcePath' => '/new',
            'target' => '/older',
        ])))->toThrow(SeoRedirectLoopException::class)
        ->and(fn () => $action->execute(null, SeoRedirectPayload::from([
            'sourcePath' => '/unsafe',
            'target' => 'javascript:alert(1)',
        ])))->toThrow(InvalidSeoMutationException::class);

    $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/absolute-hop',
        'target' => 'https://example.test/final?campaign=legacy#offer',
    ]));
    $preserved = $action->execute(null, SeoRedirectPayload::from([
        'sourcePath' => '/before-absolute-hop',
        'target' => '/absolute-hop',
    ]));

    expect($preserved->target)
        ->toBe('https://example.test/final?campaign=legacy#offer');
});

it('generates bounded sitemap indexes and reports package health', function (): void {
    config()->set('seo.sitemap.max_urls', 1);
    app(SyncSeoProfileAction::class)->execute(seoOwner(), seoPayload());
    $generator = app(SitemapGenerator::class);

    expect($generator->generate())->toContain('<sitemapindex')
        ->and($generator->generate())->toContain('sitemap-1.xml', 'sitemap-2.xml')
        ->and($generator->generateChunk(1))->toContain('<urlset')
        ->and(fn () => $generator->generateChunk(3))->toThrow(OutOfBoundsException::class)
        ->and(collect(app(SeoDoctor::class)->inspect())->every(
            static fn ($check): bool => $check->passed,
        ))->toBeTrue();

    config()->set('seo.sitemap.cache_seconds', 0);

    expect($generator->generate())->toContain('<sitemapindex')
        ->and($generator->generateChunk(2))->toContain('<urlset')
        ->and($generator->chunkCount())->toBe(2);
});
