<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Nvl\Seo\Actions\ArchiveSeoProfileAction;
use Nvl\Seo\Actions\GetOwnerSeoProfileAction;
use Nvl\Seo\Actions\GetOwnerSeoRevisionAction;
use Nvl\Seo\Actions\GetSeoProfileAction;
use Nvl\Seo\Actions\ImportSeoProfilesAction;
use Nvl\Seo\Actions\ListOwnerSeoProfilesAction;
use Nvl\Seo\Actions\ListSeoProfilesAction;
use Nvl\Seo\Actions\SyncSeoProfileAction;
use Nvl\Seo\Actions\SyncSeoRedirectAction;
use Nvl\Seo\Contracts\SeoAuthorization;
use Nvl\Seo\Contracts\SeoImportSource;
use Nvl\Seo\Contracts\StructuredDataProvider;
use Nvl\Seo\Data\Import\SeoImportPageData;
use Nvl\Seo\Data\Import\SeoImportRecordData;
use Nvl\Seo\Data\Mutations\SeoProfilePayload;
use Nvl\Seo\Data\Mutations\SeoRedirectPayload;
use Nvl\Seo\Data\SeoImage;
use Nvl\Seo\Data\SeoOwnerRevisionData;
use Nvl\Seo\Data\SeoProfileData;
use Nvl\Seo\Data\SeoProfileQuery;
use Nvl\Seo\Data\StructuredDataContextData;
use Nvl\Seo\Data\StructuredDataNodeData;
use Nvl\Seo\Definitions\Tables\SeoTables;
use Nvl\Seo\Enums\SeoAbility;
use Nvl\Seo\Exceptions\InvalidSeoMutationException;
use Nvl\Seo\Exceptions\SeoPathConflictException;
use Nvl\Seo\Exceptions\StaleSeoProfileException;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Providers\SeoServiceProvider;
use Nvl\Seo\Rules\UniqueSeoPath;
use Nvl\Seo\Services\ConfiguredSeoAuthorization;
use Nvl\Seo\Services\DirectSeoImageResolver;
use Nvl\Seo\Services\SeoDoctor;
use Nvl\Seo\Services\SeoOwnerRegistry;
use Nvl\Seo\Services\SitemapGenerator;
use Nvl\Seo\Services\StructuredDataBuilder;
use Nvl\Seo\Services\StructuredDataRegistry;
use Nvl\Seo\Support\DatabaseConstraintViolation;
use Nvl\Seo\Support\SeoAuthorizationContext;
use Nvl\Seo\Support\SeoModelIdentifier;
use Nvl\Seo\Support\SeoRouteConfiguration;
use Nvl\Seo\Support\StructuredDataLimits;
use Nvl\Seo\Tests\Fixtures\TestIntegerSeoOwner;
use Nvl\Seo\Tests\Fixtures\TestSeoOwner;
use Spatie\LaravelData\DataCollection;

function seoConsumerOwner(string $name = 'Consumer owner'): TestSeoOwner
{
    return TestSeoOwner::query()->create(['name' => $name]);
}

it('returns management profile details and pages as stable DTOs without mapping queries', function (): void {
    $olderOwner = seoConsumerOwner('Older management profile');
    $newerOwner = seoConsumerOwner('Newer management profile');
    $older = app(SyncSeoProfileAction::class)->execute(
        $olderOwner,
        SeoProfilePayload::from([
            'translations' => ['en' => ['title' => 'Older profile']],
        ]),
    );
    $newer = app(SyncSeoProfileAction::class)->execute(
        $newerOwner,
        SeoProfilePayload::from([
            'translations' => ['en' => ['title' => 'Newer profile']],
        ]),
    );
    SeoProfile::query()->whereKey($older->id)->update(['updated_at' => now()->subMinute()]);
    SeoProfile::query()->whereKey($newer->id)->update(['updated_at' => now()]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $profiles = app(ListSeoProfilesAction::class)->execute(new SeoProfileQuery(
            page: 1,
            perPage: 1,
        ));
        $queryCount = count(DB::getQueryLog());
        $item = $profiles->items()[0] ?? null;
        $serialized = $item?->toArray();

        expect(DB::getQueryLog())->toHaveCount($queryCount)
            ->and($queryCount)->toBeLessThanOrEqual(3)
            ->and($profiles->total())->toBe(2)
            ->and($profiles->currentPage())->toBe(1)
            ->and($profiles->perPage())->toBe(1)
            ->and($profiles->getOptions()['path'] ?? null)->toBe($profiles->path())
            ->and($profiles->appends(['status' => 'active'])->url(2))->toContain(
                'page=2',
                'status=active',
            )
            ->and($item)->toBeInstanceOf(SeoProfileData::class)
            ->and($item?->id)->toBe($newer->id)
            ->and($item?->ownerAlias)->toBe('article')
            ->and($item?->translations['en']->title ?? null)->toBe('Newer profile')
            ->and(array_keys($serialized ?? []))->toContain(
                'id',
                'ownerAlias',
                'ownerId',
                'translations',
            );
    } finally {
        DB::disableQueryLog();
    }

    $profile = app(GetSeoProfileAction::class)->execute($newer->id);
    $archived = app(ArchiveSeoProfileAction::class)->execute(
        $newer,
        true,
        $newer->revision,
    );

    expect($profile)->toBeInstanceOf(SeoProfileData::class)
        ->and($profile->id)->toBe($newer->id)
        ->and($profile->translations['en']->title ?? null)->toBe('Newer profile')
        ->and($older)->toBeInstanceOf(SeoProfile::class)
        ->and($archived)->toBeInstanceOf(SeoProfile::class);
});

it('returns authorized owner-centric profile and revision projections', function (): void {
    $owner = seoConsumerOwner('Owner-centric reads');
    $profile = app(SyncSeoProfileAction::class)->execute(
        $owner,
        SeoProfilePayload::from([
            'translations' => [
                'en' => ['path' => '/owner-centric', 'title' => 'Owner-centric'],
            ],
        ]),
        'catalog',
    );
    $authorization = new class implements SeoAuthorization
    {
        /** @var list<SeoAuthorizationContext> */
        public array $contexts = [];

        public function authorize(SeoAuthorizationContext $context): void
        {
            $this->contexts[] = $context;
        }
    };
    app()->instance(SeoAuthorization::class, $authorization);

    $profileData = app(GetOwnerSeoProfileAction::class)->execute($owner, ' CATALOG ');
    $revisionData = app(GetOwnerSeoRevisionAction::class)->execute($owner, 'catalog');
    $missingProfile = app(GetOwnerSeoProfileAction::class)->execute($owner, 'missing');
    $missingOwner = seoConsumerOwner('Owner without SEO');
    $missing = app(GetOwnerSeoRevisionAction::class)->execute($missingOwner, 'catalog');

    expect($profileData)->toBeInstanceOf(SeoProfileData::class)
        ->and($profileData?->id)->toBe($profile->id)
        ->and($profileData?->ownerAlias)->toBe('article')
        ->and($profileData?->translations)->toHaveKey('en')
        ->and($revisionData)->toBeInstanceOf(SeoOwnerRevisionData::class)
        ->and($revisionData->toArray())->toBe([
            'ownerAlias' => 'article',
            'ownerId' => (string) $owner->getKey(),
            'scope' => 'catalog',
            'profileId' => $profile->id,
            'revision' => $profile->revision,
        ])
        ->and($missingProfile)->toBeNull()
        ->and($missing->ownerAlias)->toBe('article')
        ->and($missing->ownerId)->toBe((string) $missingOwner->getKey())
        ->and($missing->scope)->toBe('catalog')
        ->and($missing->profileId)->toBeNull()
        ->and($missing->revision)->toBe(0)
        ->and($authorization->contexts)->toHaveCount(4)
        ->and(array_map(
            static fn (SeoAuthorizationContext $context): string => $context->scope ?? '',
            $authorization->contexts,
        ))->toBe(['catalog', 'catalog', 'missing', 'catalog']);

    foreach ($authorization->contexts as $context) {
        expect($context->ability)->toBe(SeoAbility::View)
            ->and($context->ownerAlias)->toBe('article');
    }
});

it('denies owner-centric SEO reads before querying profiles', function (): void {
    $owner = seoConsumerOwner('Denied owner-centric read');
    app()->instance(SeoAuthorization::class, new class implements SeoAuthorization
    {
        public function authorize(SeoAuthorizationContext $context): void
        {
            throw new AuthorizationException('Denied owner-centric SEO read.');
        }
    });
    DB::flushQueryLog();
    DB::enableQueryLog();

    expect(fn () => app(GetOwnerSeoRevisionAction::class)->execute($owner))
        ->toThrow(AuthorizationException::class, 'Denied owner-centric SEO read.');
    $profileQueries = array_filter(
        DB::getQueryLog(),
        static fn (array $query): bool => str_contains($query['query'], SeoTables::Profiles),
    );

    expect($profileQueries)->toBeEmpty();
});

it('returns authorized owner SEO profiles positionally with fixed query counts', function (): void {
    $owners = [seoConsumerOwner('Bulk owner 01')];
    app(SyncSeoProfileAction::class)->execute(
        $owners[0],
        SeoProfilePayload::from([
            'translations' => ['en' => ['title' => 'Bulk profile 01']],
        ]),
        'catalog',
    );
    $authorization = new class implements SeoAuthorization
    {
        /** @var list<SeoAuthorizationContext> */
        public array $contexts = [];

        public function authorize(SeoAuthorizationContext $context): void
        {
            $this->contexts[] = $context;
        }
    };
    app()->instance(SeoAuthorization::class, $authorization);
    $measure = static function (array $owners): array {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $profiles = app(ListOwnerSeoProfilesAction::class)->execute(
                $owners,
                ' CATALOG ',
            );
            $queryCount = count(DB::getQueryLog());

            foreach ($profiles as $profile) {
                $profile?->toArray();
            }

            expect(DB::getQueryLog())->toHaveCount($queryCount);

            return [$profiles, $queryCount];
        } finally {
            DB::disableQueryLog();
        }
    };
    [$single, $singleQueries] = $measure($owners);

    foreach (range(2, 25) as $index) {
        $owner = seoConsumerOwner("Bulk owner {$index}");
        $owners[] = $owner;
        app(SyncSeoProfileAction::class)->execute(
            $owner,
            SeoProfilePayload::from([
                'translations' => ['en' => ['title' => "Bulk profile {$index}"]],
            ]),
            'catalog',
        );
    }

    [$populated, $populatedQueries] = $measure($owners);

    expect($single)->toHaveCount(1)
        ->and($single[0])->toBeInstanceOf(SeoProfileData::class)
        ->and($single[0]?->ownerId)->toBe((string) $owners[0]->getKey())
        ->and($singleQueries)->toBeLessThanOrEqual(2)
        ->and($populated)->toHaveCount(25)
        ->and($populated[24]?->ownerId)->toBe((string) $owners[24]->getKey())
        ->and($populatedQueries)->toBe($singleQueries)
        ->and($authorization->contexts)->toHaveCount(26);
});

it('denies bulk owner SEO reads before profile queries and bounds owner input', function (): void {
    $owners = [seoConsumerOwner('Denied bulk owner')];
    app()->instance(SeoAuthorization::class, new class implements SeoAuthorization
    {
        public function authorize(SeoAuthorizationContext $context): void
        {
            throw new AuthorizationException('Denied bulk owner SEO read.');
        }
    });
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        expect(fn () => app(ListOwnerSeoProfilesAction::class)->execute($owners))
            ->toThrow(AuthorizationException::class, 'Denied bulk owner SEO read.')
            ->and(collect(DB::getQueryLog())->contains(
                static fn (array $query): bool => str_contains(
                    $query['query'],
                    SeoTables::Profiles,
                ),
            ))->toBeFalse();
    } finally {
        DB::disableQueryLog();
    }

    $repeated = array_fill(0, 101, $owners[0]);

    expect(fn () => app(ListOwnerSeoProfilesAction::class)->execute($repeated))
        ->toThrow(InvalidArgumentException::class, 'at most 100 owner entries');
});

it('preserves mixed owner types and absent profiles in positional results', function (): void {
    $uuidOwner = seoConsumerOwner('Mixed UUID owner');
    $integerOwner = TestIntegerSeoOwner::query()->create(['name' => 'Mixed integer owner']);
    $missingOwner = seoConsumerOwner('Mixed missing owner');
    app(SyncSeoProfileAction::class)->execute(
        $uuidOwner,
        SeoProfilePayload::from([]),
        'catalog',
    );
    app(SyncSeoProfileAction::class)->execute(
        $integerOwner,
        SeoProfilePayload::from([]),
        'catalog',
    );
    app()->instance(SeoAuthorization::class, new class implements SeoAuthorization
    {
        public function authorize(SeoAuthorizationContext $context): void {}
    });

    $profiles = app(ListOwnerSeoProfilesAction::class)->execute(
        [$uuidOwner, $integerOwner, $missingOwner],
        'catalog',
    );

    expect($profiles)->toHaveCount(3)
        ->and($profiles[0]?->ownerAlias)->toBe('article')
        ->and($profiles[0]?->ownerId)->toBe((string) $uuidOwner->getKey())
        ->and($profiles[1]?->ownerAlias)->toBe('integer-article')
        ->and($profiles[1]?->ownerId)->toBe((string) $integerOwner->getKey())
        ->and($profiles[2])->toBeNull();
});

it('exercises the complete opt-in management API lifecycle', function (): void {
    config()->set([
        'seo.management.enabled' => true,
        'seo.management.path' => 'api/consumer/seo',
        'seo.management.name' => 'consumer.seo',
        'seo.management.middleware' => ['api'],
        'seo.authorization.ability' => 'manage-consumer-seo',
    ]);
    Gate::define(
        'manage-consumer-seo',
        static fn (
            ?Authenticatable $actor,
            SeoAuthorizationContext $context,
        ): bool => $context->ability->value !== '',
    );
    require __DIR__.'/../../routes/management.php';
    app('router')->getRoutes()->refreshNameLookups();

    $owner = seoConsumerOwner();
    $created = $this->postJson('/api/consumer/seo/profiles', [
        'ownerAlias' => 'article',
        'ownerId' => $owner->getKey(),
        'profile' => [
            'translations' => [
                'en' => [
                    'path' => '/consumer-owner',
                    'title' => 'Consumer title',
                ],
            ],
        ],
    ])->assertCreated();
    $profileId = $created->json('data.id');
    $revision = $created->json('data.revision');

    expect($profileId)->toBeString()
        ->and($revision)->toBeInt();

    $this->getJson('/api/consumer/seo/profiles?ownerAlias=article&perPage=10')
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.items.0.id', $profileId);
    $this->getJson('/api/consumer/seo/profiles/status')
        ->assertOk()
        ->assertJsonPath('data.active', 1)
        ->assertJsonPath('data.total', 1);
    $this->getJson("/api/consumer/seo/profiles/{$profileId}")
        ->assertOk()
        ->assertJsonPath('data.id', $profileId);
    $this->getJson("/api/consumer/seo/profiles/{$profileId}/preview?locale=en")
        ->assertOk()
        ->assertJsonPath('data.canonicalUrl', 'https://example.test/consumer-owner');

    $target = seoConsumerOwner('Duplicate target');
    $duplicate = $this->postJson("/api/consumer/seo/profiles/{$profileId}/duplicate", [
        'ownerAlias' => 'article',
        'ownerId' => $target->getKey(),
        'scope' => 'secondary',
        'copyPaths' => false,
    ])->assertCreated();
    $duplicateId = $duplicate->json('data.id');
    $duplicateRevision = $duplicate->json('data.revision');

    expect($duplicateId)->toBeString()
        ->and($duplicateRevision)->toBeInt();

    $archived = $this->patchJson("/api/consumer/seo/profiles/{$duplicateId}/archive", [
        'archived' => true,
        'expectedRevision' => $duplicateRevision,
    ])->assertOk();

    $this->patchJson("/api/consumer/seo/profiles/{$duplicateId}/archive", [
        'archived' => false,
        'expectedRevision' => $archived->json('data.revision'),
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
    $this->deleteJson("/api/consumer/seo/profiles/{$profileId}", [
        'expectedRevision' => $revision,
    ])
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(SeoProfile::query()->whereKey($profileId)->exists())->toBeFalse();
});

it('covers bounded import success and stable continuation data', function (): void {
    $owner = seoConsumerOwner('Imported owner');
    $record = new SeoImportRecordData(
        ownerAlias: 'article',
        ownerId: (string) $owner->getKey(),
        scope: 'catalog',
        profile: SeoProfilePayload::from([
            'translations' => [
                'en' => ['path' => '/imported-owner', 'title' => 'Imported'],
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
                items: new DataCollection(SeoImportRecordData::class, [$this->record]),
                nextCursor: $cursor === null ? 'next-page' : null,
            );
        }
    };

    $result = app(ImportSeoProfilesAction::class)->execute($source, limit: 50);

    expect($result->processed)->toBe(1)
        ->and($result->nextCursor)->toBe('next-page')
        ->and($owner->seoProfile('catalog'))->toBeInstanceOf(SeoProfile::class);
});

it('validates public structured-data builders and their resource limits', function (): void {
    $builder = app(StructuredDataBuilder::class);
    $node = StructuredDataNodeData::make(
        'Thing',
        ['name' => 'Consumer', '@context' => 'ignored', '@type' => 'ignored'],
        'https://example.test/consumer#thing',
    );
    $graph = $builder->graph([$node, ['@type' => 'Organization', 'name' => 'NVL']]);

    expect($graph['@graph'])->toHaveCount(2)
        ->and($node->toJsonLd())->not->toHaveKey('@context')
        ->and($builder->reference('#local'))->toBe(['@id' => '#local'])
        ->and($builder->breadcrumbs([
            ['name' => 'Home', 'url' => 'https://example.test'],
        ]))
        ->toHaveKey('@type', 'BreadcrumbList')
        ->and(StructuredDataLimits::accepts(null))->toBeTrue()
        ->and(StructuredDataLimits::accepts('invalid'))->toBeFalse()
        ->and(StructuredDataLimits::accepts([
            '@context' => 'https://invalid.test',
            '@type' => 'Thing',
        ]))->toBeFalse()
        ->and(StructuredDataLimits::accepts([
            '@context' => 'https://schema.org',
            '@graph' => [],
        ]))->toBeFalse()
        ->and(StructuredDataLimits::accepts([
            '@type' => 'Thing',
            'value' => INF,
        ]))->toBeFalse()
        ->and(fn () => $builder->graph([]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $builder->reference('relative'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $builder->breadcrumbs([
            ['name' => '', 'url' => 'https://example.test'],
        ]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => StructuredDataNodeData::make('Invalid-Type'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => StructuredDataNodeData::make('Thing', id: 'relative'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SeoImage('https://example.test/image.jpg', width: 0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SeoImage('https://example.test/image.jpg', height: -1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SeoImage('https://example.test/image.jpg', mimeType: 'text/html'))
        ->toThrow(InvalidArgumentException::class);

    config()->set('seo.structured_data.maximum_bytes', 10);
    expect(StructuredDataLimits::accepts([
        '@type' => 'Thing',
        'name' => 'Too many items',
    ]))->toBeFalse();
    config()->set([
        'seo.structured_data.maximum_bytes' => 262_144,
        'seo.structured_data.maximum_depth' => 1,
    ]);
    expect(StructuredDataLimits::accepts([
        '@type' => 'Thing',
        'nested' => ['value' => ['deep' => true]],
    ]))->toBeFalse();
    config()->set([
        'seo.structured_data.maximum_depth' => 16,
        'seo.structured_data.maximum_items' => 1,
    ]);
    expect(StructuredDataLimits::accepts([
        '@type' => 'Thing',
        'name' => 'Too many items',
    ]))->toBeFalse();
});

it('covers owner, route uniqueness, and database constraint boundaries', function (): void {
    $owner = seoConsumerOwner('Unique path owner');
    $profile = app(SyncSeoProfileAction::class)->execute(
        $owner,
        SeoProfilePayload::from([
            'translations' => [
                'en' => ['path' => '/unique-consumer-path'],
            ],
        ]),
    );
    $failures = [];
    (new UniqueSeoPath('en'))->validate(
        'path',
        '/unique-consumer-path',
        static function (string $message) use (&$failures): void {
            $failures[] = $message;
        },
    );
    $ignoredFailures = [];
    (new UniqueSeoPath('en', ignoreProfileId: $profile->id))->validate(
        'path',
        '/unique-consumer-path',
        static function (string $message) use (&$ignoredFailures): void {
            $ignoredFailures[] = $message;
        },
    );
    (new UniqueSeoPath('en'))->validate('path', [], static function (): void {});

    expect($failures)->toHaveCount(1)
        ->and($ignoredFailures)->toBeEmpty()
        ->and(SeoModelIdentifier::normalize(42))->toBe('42')
        ->and(fn () => SeoModelIdentifier::normalize(''))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SeoModelIdentifier::normalize(str_repeat('a', 256)))
        ->toThrow(InvalidArgumentException::class);

    $unsupportedKey = new class extends Model
    {
        public function getKey(): mixed
        {
            return ['unsupported'];
        }
    };
    $oversizedKey = new class extends Model
    {
        public function getKey(): mixed
        {
            return str_repeat('x', 256);
        }
    };

    expect(fn () => SeoModelIdentifier::required($unsupportedKey))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SeoModelIdentifier::required($oversizedKey))
        ->toThrow(InvalidArgumentException::class);

    $integrity = new PDOException('duplicate seo_profiles_scope_owner_unique', 23000);
    $integrity->errorInfo = ['23000', 19, 'seo_profiles_scope_owner_unique'];
    $integrityQuery = new QueryException('sqlite', 'insert into seo_profiles', [], $integrity);
    $syntax = new PDOException('syntax error', 42000);
    $syntax->errorInfo = ['42000', 1, 'syntax error'];
    $syntaxQuery = new QueryException('sqlite', 'invalid sql', [], $syntax);

    expect(DatabaseConstraintViolation::isIntegrityViolation($integrityQuery))->toBeTrue()
        ->and(DatabaseConstraintViolation::matches(
            $integrityQuery,
            ['seo_profiles_scope_owner_unique'],
        ))->toBeTrue()
        ->and(DatabaseConstraintViolation::matches($integrityQuery, ['other']))->toBeFalse()
        ->and(DatabaseConstraintViolation::isIntegrityViolation($syntaxQuery))->toBeFalse();

    $registry = new SeoOwnerRegistry;
    expect(fn () => $registry->modelClass('missing'))
        ->toThrow(InvalidSeoMutationException::class)
        ->and(fn () => $registry->aliasForMorphType('missing'))
        ->toThrow(InvalidSeoMutationException::class);

    config()->set('seo.owners', [
        'first' => TestSeoOwner::class,
        'second' => TestSeoOwner::class,
    ]);
    expect(fn () => (new SeoOwnerRegistry)->configured())
        ->toThrow(InvalidArgumentException::class);
    config()->set('seo.owners', 'invalid');
    expect(fn () => (new SeoOwnerRegistry)->configured())
        ->toThrow(InvalidArgumentException::class);
});

it('reports invalid consumer configuration through the doctor contract', function (): void {
    $this->artisan('nvl:seo:doctor')->assertSuccessful();
    $this->artisan('nvl:seo:doctor', ['--format' => 'json'])->assertSuccessful();

    $owner = seoConsumerOwner('Doctor owner');
    $profile = app(SyncSeoProfileAction::class)->execute(
        $owner,
        SeoProfilePayload::from([
            'translations' => ['en' => ['path' => '/doctor-owner']],
        ]),
    );
    $redirect = app(SyncSeoRedirectAction::class)->execute(
        null,
        SeoRedirectPayload::from([
            'sourcePath' => '/doctor-redirect',
            'target' => '/destination',
        ]),
    );
    DB::table(SeoTables::Profiles)->where('id', $profile->id)->update([
        'revision' => 0,
        'status' => 'invalid',
    ]);
    DB::table(SeoTables::Redirects)->where('id', $redirect->id)->update([
        'source_hash' => 'short',
    ]);
    config()->set([
        'seo.site.base_url' => 'relative',
        'seo.owners' => 'invalid',
        'seo.structured_data.mode' => 'invalid',
        'seo.sitemap.max_urls' => 0,
        'seo.routes.sitemap_scopes' => [false],
        'seo.robots.maximum_bytes' => 0,
        'seo.routes.name' => 'invalid route name',
        'seo.management.enabled' => true,
        'seo.management.path' => '../unsafe',
    ]);

    $checks = collect(app(SeoDoctor::class)->inspect())->keyBy('key');

    expect($checks['schema.profile-row-integrity']->passed)->toBeFalse()
        ->and($checks['schema.redirect-source-hashes']->passed)->toBeFalse()
        ->and($checks['configuration.site']->passed)->toBeFalse()
        ->and($checks['configuration.owners']->passed)->toBeFalse()
        ->and($checks['configuration.structured-data']->passed)->toBeFalse()
        ->and($checks['configuration.sitemap']->passed)->toBeFalse()
        ->and($checks['configuration.robots']->passed)->toBeFalse()
        ->and($checks['routes.public']->passed)->toBeFalse()
        ->and($checks['routes.management']->passed)->toBeFalse();

    $this->artisan('nvl:seo:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertFailed();
});

it('fails closed for invalid package bindings, routes, and authorization', function (): void {
    config()->set('seo.image_resolver', stdClass::class);
    expect(fn () => (new SeoServiceProvider(app()))->register())
        ->toThrow(InvalidArgumentException::class, 'image_resolver');

    config()->set([
        'seo.image_resolver' => DirectSeoImageResolver::class,
        'seo.sitemap.artifact_store' => stdClass::class,
    ]);
    expect(fn () => (new SeoServiceProvider(app()))->register())
        ->toThrow(InvalidArgumentException::class, 'artifact_store')
        ->and(fn () => SeoRouteConfiguration::sitemapChunkPath())
        ->not->toThrow(InvalidArgumentException::class);

    config()->set('seo.routes.sitemap_chunk_path', 'sitemap.xml');
    expect(fn () => SeoRouteConfiguration::sitemapChunkPath())
        ->toThrow(InvalidArgumentException::class, '{chunk}')
        ->and(fn () => app(ConfiguredSeoAuthorization::class)->authorize(
            new SeoAuthorizationContext(ability: SeoAbility::List),
        ))
        ->toThrow(AuthorizationException::class);

    $exception = InvalidSeoMutationException::because('Consumer error');
    expect($exception->render(Request::create('/consumer', 'GET')))->toBeFalse();
});

it('returns stable failures for invalid owners, stale creates, and route conflicts', function (): void {
    expect(fn () => app(SyncSeoProfileAction::class)->execute(
        new TestSeoOwner,
        SeoProfilePayload::from([]),
    ))->toThrow(InvalidArgumentException::class, 'persisted model');

    expect(fn () => app(SyncSeoProfileAction::class)->execute(
        seoConsumerOwner('Stale create owner'),
        SeoProfilePayload::from(['expectedRevision' => 2]),
    ))->toThrow(StaleSeoProfileException::class);

    $request = Request::create('/consumer', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    $conflict = SeoPathConflictException::forRoute(
        'default',
        'en',
        '/claimed',
        'existing-profile',
    )->render($request);
    $concurrent = SeoPathConflictException::concurrent('catalog')->render($request);

    expect($conflict->getStatusCode())->toBe(409)
        ->and($conflict->getData(true)['error']['code'])->toBe('seo_path_conflict')
        ->and($conflict->getData(true)['error']['context'])->toBe([
            'scope' => 'default',
            'locale' => 'en',
            'path' => '/claimed',
            'profileId' => 'existing-profile',
        ])
        ->and($concurrent->getStatusCode())->toBe(409)
        ->and($concurrent->getData(true)['error']['code'])->toBe('seo_path_conflict')
        ->and($concurrent->getData(true)['error']['context'])->toBe([
            'scope' => 'catalog',
        ]);
});

it('guards the structured-data extension boundary', function (): void {
    $context = new StructuredDataContextData(
        resourceType: 'article',
        resourceId: 'consumer',
        profileId: 'profile',
        locale: 'en',
        scope: 'default',
        canonicalUrl: 'https://example.test/consumer',
        title: 'Consumer',
        description: null,
        imageUrl: null,
        siteName: 'Example',
        siteUrl: 'https://example.test',
    );
    $provider = new class implements StructuredDataProvider
    {
        public function provide(Model $resource, StructuredDataContextData $context): iterable
        {
            yield 'invalid-node';
        }
    };
    $registry = new StructuredDataRegistry;

    expect(fn () => $registry->register('', TestSeoOwner::class, $provider))
        ->toThrow(InvalidArgumentException::class, 'empty or duplicated')
        ->and(fn () => $registry->register('invalid-resource', stdClass::class, $provider))
        ->toThrow(InvalidArgumentException::class, 'Eloquent model');

    $registry->register('consumer', TestSeoOwner::class, $provider);

    expect(fn () => $registry->register('consumer', TestSeoOwner::class, $provider))
        ->toThrow(InvalidArgumentException::class, 'empty or duplicated')
        ->and(fn () => $registry->resolve(seoConsumerOwner(), $context))
        ->toThrow(InvalidArgumentException::class, 'invalid node');
});

it('rejects invalid uncached sitemap chunks and disabled required indexes', function (): void {
    config()->set('seo.sitemap.cache_seconds', 0);

    expect(fn () => app(SitemapGenerator::class)->generateChunk(0))
        ->toThrow(OutOfBoundsException::class)
        ->and(fn () => app(SitemapGenerator::class)->generateChunk(2))
        ->toThrow(OutOfBoundsException::class);

    $owner = seoConsumerOwner('Multi-chunk owner');
    app(SyncSeoProfileAction::class)->execute(
        $owner,
        SeoProfilePayload::from([
            'translations' => [
                'en' => ['path' => '/multi-chunk-en'],
                'bg' => ['path' => '/multi-chunk-bg'],
            ],
        ]),
    );
    config()->set([
        'seo.sitemap.max_urls' => 1,
        'seo.sitemap.index_enabled' => false,
    ]);

    expect(fn () => app(SitemapGenerator::class)->generate())
        ->toThrow(LogicException::class, 'requires an index');
});
