<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Http\QueryFilterSetFactory;
use Nvl\Translations\Actions\Entries\GetTranslationCatalogStatisticsAction;
use Nvl\Translations\Actions\Entries\ListTranslationEntriesAction;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Data\TranslationCatalogStatisticsData;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Exceptions\TranslationConflictException;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Http\Controllers\Api\TranslationsApiController;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Rules\StringOrList;
use Nvl\Translations\Services\TranslationEntryFilterSchema;
use Nvl\Translations\Services\TranslationsDoctor;
use Nvl\Translations\Support\TranslationConfiguration;
use Nvl\Translations\Support\TranslationValueHash;

beforeEach(function (): void {
    $this->consumerRoot = storage_path('framework/testing/translations-consumer-'.Str::uuid());
    $this->consumerSource = $this->consumerRoot.'/lang';
    $this->consumerTarget = $this->consumerRoot.'/generated';
    $this->consumerCode = $this->consumerRoot.'/app';

    File::ensureDirectoryExists($this->consumerSource.'/en');
    File::ensureDirectoryExists($this->consumerCode);
    File::put($this->consumerSource.'/en/messages.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'used' => 'Used message',
    'unused' => 'Unused message',
];
PHP);
    File::put($this->consumerSource.'/en.json', <<<'JSON'
{
    "JSON Used": "JSON Used",
    "JSON Unused": "JSON Unused"
}
JSON);
    File::put($this->consumerCode.'/Consumer.php', <<<'PHP'
<?php

__('messages.used');
__('JSON Used');
PHP);

    config()->set([
        'translations.paths.app' => $this->consumerSource,
        'translations.discovery.modules' => false,
        'translations.discovery.vendor' => false,
        'translations.custom_scopes' => [],
        'translations.export_targets' => [
            'source' => [],
            'generated' => ['app' => $this->consumerTarget],
        ],
        'translations.import.conflict_strategy' => 'prefer_database',
        'translations.import.fail_on_error' => true,
        'translations.backup.enabled' => true,
        'translations.backup.directory' => $this->consumerRoot.'/backups',
        'translations.scan.paths' => [$this->consumerCode],
        'translations.scan.extensions' => ['php'],
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->consumerRoot);
});

test('a consumer can run the complete safe translation command workflow', function (): void {
    $this->artisan('nvl:translations:sync', [
        '--scope' => ['app'],
        '--format' => 'both',
        '--dry-run' => true,
        '--output' => 'json',
    ])
        ->expectsOutputToContain('"dryRun": true')
        ->assertSuccessful();
    $this->artisan('nvl:translations:sync', [
        '--scope' => ['app'],
        '--format' => 'both',
        '--strategy' => 'prefer-database',
    ])
        ->expectsOutputToContain('Synchronized 4 entries')
        ->assertSuccessful();
    $this->artisan('nvl:translations:sync', [
        '--scope' => ['app'],
        '--format' => 'json',
        '--strategy' => 'interactive',
    ])
        ->expectsChoice(
            'How should conflicts be resolved?',
            'prefer-database',
            ['fail', 'prefer-file', 'prefer-database'],
        )
        ->assertSuccessful();

    $this->artisan('nvl:translations:status', ['--scope' => ['app']])
        ->expectsOutputToContain('app')
        ->assertSuccessful();
    $this->artisan('nvl:translations:scan')
        ->expectsOutputToContain('captured 2 usage hits')
        ->assertSuccessful();
    $this->artisan('nvl:translations:unused', [
        '--scope' => ['app'],
        '--limit' => 1,
    ])
        ->expectsOutputToContain('Unused entries: 2')
        ->expectsOutputToContain('1 more rows omitted')
        ->assertSuccessful();

    $this->artisan('nvl:translations:export', [
        '--scope' => ['app'],
        '--format' => 'both',
        '--target' => 'generated',
        '--dry-run' => true,
        '--output' => 'json',
    ])
        ->expectsOutputToContain('"dryRun": true')
        ->assertSuccessful();
    $this->artisan('nvl:translations:export', [
        '--scope' => ['app'],
        '--format' => 'both',
        '--target' => 'generated',
    ])
        ->expectsConfirmation('Replace selected translation artifacts after creating backups?', 'no')
        ->expectsOutputToContain('Translation export cancelled')
        ->assertFailed();
    $this->artisan('nvl:translations:export', [
        '--scope' => ['app'],
        '--format' => 'both',
        '--target' => 'generated',
        '--force' => true,
    ])
        ->expectsOutputToContain('Resaved 2 files')
        ->assertSuccessful();
    $this->artisan('nvl:translations:prune', [
        '--scope' => ['app'],
        '--format' => 'both',
        '--target' => 'generated',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Would prune')
        ->assertSuccessful();

    $this->artisan('nvl:translations:doctor')
        ->expectsOutputToContain('PASS')
        ->assertSuccessful();
});

test('keeps translation catalog list queries independent of result size', function (): void {
    $create = static function (int $index): void {
        TranslationEntry::query()->create([
            'scope_type' => 'app',
            'scope_name' => 'app',
            'locale' => 'en',
            'format' => 'json',
            'group' => '*',
            'key' => "Query {$index}",
            'value' => "Value {$index}",
            'source_hash' => hash('sha256', "Value {$index}"),
            'is_missing' => false,
        ]);
    };
    $measure = static function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $page = app(ListTranslationEntriesAction::class)->execute(100, FilterSet::none());
        $queryCount = count(DB::getQueryLog());
        collect($page->items())->each(static fn (TranslationEntry $entry): string => $entry->key);

        expect(DB::getQueryLog())->toHaveCount($queryCount);
        DB::disableQueryLog();

        return $queryCount;
    };

    $create(1);
    $singleQueryCount = $measure();

    foreach (range(2, 25) as $index) {
        $create($index);
    }

    $populatedQueryCount = $measure();

    expect($singleQueryCount)->toBeLessThanOrEqual(2)
        ->and($populatedQueryCount)->toBe($singleQueryCount);
});

test('returns authorized bounded translation catalog statistics with list filter parity', function (): void {
    foreach ([
        ['scope_type' => 'app', 'scope_name' => 'app', 'locale' => 'en', 'key' => 'One', 'sync_status' => 'synchronized', 'source_hash' => hash('sha256', 'One')],
        ['scope_type' => 'app', 'scope_name' => 'app', 'locale' => 'en', 'key' => 'Two', 'sync_status' => 'edited', 'source_hash' => hash('sha256', 'Two')],
        ['scope_type' => 'module', 'scope_name' => 'website', 'locale' => 'bg', 'key' => 'Three', 'sync_status' => 'conflict', 'source_hash' => hash('sha256', 'Three')],
        ['scope_type' => 'module', 'scope_name' => 'website', 'locale' => 'bg', 'key' => 'Four', 'sync_status' => 'edited', 'source_hash' => hash('sha256', 'Four')],
        ['scope_type' => 'custom', 'scope_name' => 'shared', 'locale' => 'de', 'key' => 'Five', 'sync_status' => 'missing', 'source_hash' => hash('sha256', 'Five'), 'is_missing' => true],
        ['scope_type' => 'custom', 'scope_name' => 'shared', 'locale' => 'fr', 'key' => 'Six', 'sync_status' => 'edited', 'source_hash' => null, 'is_missing' => true],
    ] as $attributes) {
        TranslationEntry::query()->create([
            'format' => 'json',
            'group' => '*',
            'value' => $attributes['key'],
            'is_missing' => false,
            ...$attributes,
        ]);
    }

    $authorization = new class implements TranslationsAuthorization
    {
        /** @var list<TranslationsAbility> */
        public array $abilities = [];

        public function authorize(TranslationsAbility $ability, ?TranslationEntry $entry = null): void
        {
            $this->abilities[] = $ability;
        }
    };
    app()->instance(TranslationsAuthorization::class, $authorization);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $statistics = app(GetTranslationCatalogStatisticsAction::class)->execute();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $grammar = DB::connection()->getQueryGrammar();
    $localeQuery = collect($queries)->first(
        static fn (array $query): bool => str_contains($query['query'], 'aggregate_key'),
    );
    $scopeQuery = collect($queries)->first(
        static fn (array $query): bool => str_contains($query['query'], 'aggregate_type'),
    );

    expect($statistics)->toBeInstanceOf(TranslationCatalogStatisticsData::class)
        ->and($statistics->toArray())->toBe([
            'total' => 6,
            'missing' => 2,
            'conflicts' => 1,
            'changed' => 3,
            'locales' => ['bg' => 2, 'en' => 2, 'de' => 1, 'fr' => 1],
            'scopes' => ['app' => 2, 'custom:shared' => 2, 'module:website' => 2],
        ])
        ->and($queries)->toHaveCount(3)
        ->and($localeQuery['query'] ?? null)->toBeString()
        ->toContain('group by '.$grammar->wrap('aggregate_key'))
        ->and($scopeQuery['query'] ?? null)->toBeString()
        ->toContain('group by '.$grammar->wrap('aggregate_type'))
        ->toContain($grammar->wrap('aggregate_name'))
        ->and($authorization->abilities)->toBe([TranslationsAbility::ListEntries]);

    $schema = app(TranslationEntryFilterSchema::class)->make();
    $filters = app(QueryFilterSetFactory::class)->fromQuery([
        'filter' => ['locale' => 'bg'],
        'sort' => 'key',
    ], $schema);
    $page = app(ListTranslationEntriesAction::class)->execute(25, $filters);
    $filtered = app(GetTranslationCatalogStatisticsAction::class)->execute($filters);

    expect($page->total())->toBe(2)
        ->and($filtered->toArray())->toBe([
            'total' => 2,
            'missing' => 0,
            'conflicts' => 1,
            'changed' => 2,
            'locales' => ['bg' => 2],
            'scopes' => ['module:website' => 2],
        ]);
});

test('returns empty catalog statistics and caps dimensions at one hundred sorted keys', function (): void {
    $authorization = new class implements TranslationsAuthorization
    {
        public function authorize(TranslationsAbility $ability, ?TranslationEntry $entry = null): void {}
    };
    app()->instance(TranslationsAuthorization::class, $authorization);

    $empty = app(GetTranslationCatalogStatisticsAction::class)->execute();

    expect($empty->toArray())->toBe([
        'total' => 0,
        'missing' => 0,
        'conflicts' => 0,
        'changed' => 0,
        'locales' => [],
        'scopes' => [],
    ]);

    foreach (range(0, 104) as $index) {
        $key = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
        TranslationEntry::query()->create([
            'scope_type' => 'custom',
            'scope_name' => "scope-{$key}",
            'locale' => "locale-{$key}",
            'format' => 'json',
            'group' => '*',
            'key' => "Key {$key}",
            'value' => "Value {$key}",
            'source_hash' => hash('sha256', $key),
            'is_missing' => false,
        ]);
    }

    foreach ([
        ['scope_type' => 'app', 'scope_name' => 'app', 'key' => 'Canonical app'],
        ['scope_type' => ' app ', 'scope_name' => 'legacy', 'key' => 'Legacy app'],
    ] as $attributes) {
        TranslationEntry::query()->create([
            'locale' => 'locale-000',
            'format' => 'json',
            'group' => '*',
            'value' => $attributes['key'],
            'source_hash' => hash('sha256', $attributes['key']),
            'is_missing' => false,
            ...$attributes,
        ]);
    }

    $bounded = app(GetTranslationCatalogStatisticsAction::class)->execute();

    expect($bounded->locales)->toHaveCount(100)
        ->and(array_key_first($bounded->locales))->toBe('locale-000')
        ->and(array_key_last($bounded->locales))->toBe('locale-099')
        ->and($bounded->scopes)->toHaveCount(100)
        ->and(array_key_first($bounded->scopes))->toBe('app')
        ->and($bounded->scopes['app'])->toBe(2)
        ->and(array_key_last($bounded->scopes))->toBe('custom:scope-098');
});

test('serializes numeric-looking locale aggregate keys without a type error', function (): void {
    $authorization = new class implements TranslationsAuthorization
    {
        public function authorize(TranslationsAbility $ability, ?TranslationEntry $entry = null): void {}
    };
    app()->instance(TranslationsAuthorization::class, $authorization);
    foreach (['0', '1'] as $locale) {
        TranslationEntry::query()->create([
            'scope_type' => 'app',
            'scope_name' => 'app',
            'locale' => $locale,
            'format' => 'json',
            'group' => '*',
            'key' => "Numeric locale {$locale}",
            'value' => "Numeric locale {$locale}",
            'source_hash' => hash('sha256', $locale),
            'is_missing' => false,
        ]);
    }

    $statistics = app(GetTranslationCatalogStatisticsAction::class)->execute();
    $json = json_encode($statistics, JSON_THROW_ON_ERROR);

    expect($statistics->locales)->toBe([0 => 1, 1 => 1])
        ->and($statistics->toArray()['locales'])->toBe([0 => 1, 1 => 1])
        ->and($json)->toContain('"locales":{"0":1,"1":1}')
        ->and($statistics->toJson(JSON_THROW_ON_ERROR))->toBe($json);
});

test('denies translation statistics before the first catalog query', function (): void {
    $authorization = new class implements TranslationsAuthorization
    {
        public function authorize(TranslationsAbility $ability, ?TranslationEntry $entry = null): void
        {
            throw new AuthorizationException('Denied.');
        }
    };
    app()->instance(TranslationsAuthorization::class, $authorization);

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect(fn () => app(GetTranslationCatalogStatisticsAction::class)->execute())
        ->toThrow(AuthorizationException::class, 'Denied.');
    expect(DB::getQueryLog())->toBeEmpty();

    DB::disableQueryLog();
});

test('shares one translation entry schema across model http and consumer adapters', function (): void {
    $serviceSchema = app(TranslationEntryFilterSchema::class)->make();
    $modelSchema = (new TranslationEntry)->filterSchema();
    $indexParameters = (new ReflectionMethod(
        TranslationsApiController::class,
        'index',
    ))->getParameters();

    expect(array_map(static fn ($definition): string => $definition->alias, $modelSchema->filters))
        ->toBe(array_map(static fn ($definition): string => $definition->alias, $serviceSchema->filters))
        ->and(array_map(static fn ($definition): string => $definition->alias, $modelSchema->sorts))
        ->toBe(array_map(static fn ($definition): string => $definition->alias, $serviceSchema->sorts))
        ->and(collect($indexParameters)->contains(
            static fn (ReflectionParameter $parameter): bool => $parameter->getType() instanceof ReflectionNamedType
                && $parameter->getType()->getName() === TranslationEntryFilterSchema::class,
        ))->toBeTrue()
        ->and((new ReflectionMethod(TranslationsApiController::class, 'index'))->getNumberOfRequiredParameters())
        ->toBe(4);

    expect(app(QueryFilterSetFactory::class)->fromQuery([
        'filter' => ['is_missing' => true],
    ], $serviceSchema))->toBeInstanceOf(FilterSet::class);
});

test('commands reject every ambiguous or unsafe option before doing work', function (): void {
    $this->artisan('nvl:translations:sync', ['--format' => 'yaml'])
        ->expectsOutput('Invalid --format option. Allowed values: php, json, both.')
        ->assertFailed();
    $this->artisan('nvl:translations:sync', ['--strategy' => 'overwrite'])
        ->expectsOutput('Invalid conflict strategy.')
        ->assertFailed();
    $this->artisan('nvl:translations:sync', ['--output' => 'yaml'])
        ->expectsOutput('Invalid --output option. Allowed values: text, json.')
        ->assertFailed();

    $this->artisan('nvl:translations:export', ['--format' => 'yaml'])
        ->expectsOutput('Invalid --format option. Allowed values: php, json, both.')
        ->assertFailed();
    $this->artisan('nvl:translations:export', ['--target' => ' '])
        ->expectsOutput('The --target option must name a configured translations.export_targets entry.')
        ->assertFailed();
    $this->artisan('nvl:translations:export', ['--output' => 'yaml'])
        ->expectsOutput('Invalid --output option. Allowed values: text, json.')
        ->assertFailed();

    $this->artisan('nvl:translations:prune')
        ->expectsOutput('Translation pruning requires --force or --dry-run.')
        ->assertFailed();
    $this->artisan('nvl:translations:prune', [
        '--dry-run' => true,
        '--format' => 'yaml',
    ])
        ->expectsOutput('Invalid --format option. Allowed values: php, json, both.')
        ->assertFailed();
    $this->artisan('nvl:translations:prune', [
        '--dry-run' => true,
        '--target' => ' ',
    ])
        ->expectsOutput('The --target option must name a configured translations.export_targets entry.')
        ->assertFailed();

    $this->artisan('nvl:translations:unused', ['--days' => -1])
        ->expectsOutput('The --days option must be an integer between 0 and 3650.')
        ->assertFailed();
    $this->artisan('nvl:translations:unused', ['--limit' => 0])
        ->expectsOutput('The --limit option must be an integer between 1 and 10000.')
        ->assertFailed();
    $this->artisan('nvl:translations:status', ['--format' => 'yaml'])
        ->expectsOutput('Invalid --format option. Allowed values: text, json.')
        ->assertFailed();
    $this->artisan('nvl:translations:doctor', ['--format' => 'yaml'])
        ->expectsOutput('Invalid --format option. Allowed values: text, json.')
        ->assertFailed();
});

test('configured authorization fails closed and passes ability plus entry to the consumer gate', function (): void {
    $authorization = app(TranslationsAuthorization::class);

    expect(fn () => $authorization->authorize(TranslationsAbility::ListEntries))
        ->toThrow(AuthorizationException::class, 'requires an authorization binding');

    config()->set('translations.authorization.ability', 'manage-translations');
    Gate::define(
        'manage-translations',
        static fn ($user, string $ability, ?TranslationEntry $entry = null): bool => $ability === 'update_entry'
            && $entry instanceof TranslationEntry,
    );
    $entry = TranslationEntry::query()->create([
        'scope_type' => 'app',
        'scope_name' => 'app',
        'locale' => 'en',
        'format' => 'json',
        'group' => '*',
        'key' => 'Authorized',
        'value' => 'Authorized',
        'source_hash' => hash('sha256', 'Authorized'),
        'is_missing' => false,
    ]);

    Gate::shouldReceive('authorize')
        ->once()
        ->with('manage-translations', ['update_entry', $entry]);
    $authorization->authorize(TranslationsAbility::UpdateEntry, $entry);
});

test('typed configuration and hashes reject invalid consumer values', function (): void {
    config()->set([
        'translations.consumer.string' => 'value',
        'translations.consumer.positive' => 2,
        'translations.consumer.non_negative' => 0,
    ]);

    expect(TranslationConfiguration::string('translations.consumer.string', 'fallback'))->toBe('value')
        ->and(TranslationConfiguration::positiveInteger('translations.consumer.positive', 1))->toBe(2)
        ->and(TranslationConfiguration::nonNegativeInteger('translations.consumer.non_negative', 1))->toBe(0)
        ->and(TranslationValueHash::make(null))->toBe(hash('sha256', "null\0"))
        ->and(TranslationValueHash::make('value'))->toBe(hash('sha256', "string\0value"));

    config()->set('translations.consumer.string', []);
    expect(fn () => TranslationConfiguration::string('translations.consumer.string', 'fallback'))
        ->toThrow(TranslationsException::class, 'must be a string');

    config()->set('translations.consumer.positive', 0);
    expect(fn () => TranslationConfiguration::positiveInteger('translations.consumer.positive', 1))
        ->toThrow(TranslationsException::class, 'positive integer');

    config()->set('translations.consumer.non_negative', -1);
    expect(fn () => TranslationConfiguration::nonNegativeInteger('translations.consumer.non_negative', 1))
        ->toThrow(TranslationsException::class, 'non-negative integer');
});

test('workspace filters preserve exact missing changed and search semantics', function (): void {
    foreach ([
        [
            'key' => 'JSON Used',
            'value' => 'JSON Used',
            'sync_status' => 'synchronized',
            'is_missing' => false,
        ],
        [
            'key' => 'JSON Missing',
            'value' => null,
            'sync_status' => 'edited',
            'is_missing' => true,
        ],
        [
            'key' => 'Empty value',
            'value' => '',
            'sync_status' => 'conflict',
            'is_missing' => false,
        ],
        [
            'key' => 'Literal 100%_safe',
            'value' => 'Kept',
            'sync_status' => 'synchronized',
            'is_missing' => false,
        ],
    ] as $attributes) {
        TranslationEntry::query()->create([
            'scope_type' => 'app',
            'scope_name' => 'app',
            'locale' => 'en',
            'format' => 'json',
            'group' => '*',
            'source_hash' => hash('sha256', (string) ($attributes['value'] ?? '')),
            ...$attributes,
        ]);
    }
    $filters = new TranslationEntry;

    expect($filters->filterSearch(TranslationEntry::query(), 'missing')->count())->toBe(1)
        ->and($filters->filterSearch(TranslationEntry::query(), '100%_safe')->count())->toBe(1)
        ->and($filters->filterSearch(TranslationEntry::query(), null)->count())->toBe(4)
        ->and($filters->filterMissingValue(TranslationEntry::query(), true)->count())->toBe(2)
        ->and($filters->filterMissingValue(TranslationEntry::query(), false)->count())->toBe(2)
        ->and($filters->filterMissingValue(TranslationEntry::query(), 'invalid')->count())->toBe(4)
        ->and($filters->filterChangedSinceImport(TranslationEntry::query(), true)->count())->toBe(2)
        ->and($filters->filterChangedSinceImport(TranslationEntry::query(), false)->count())->toBe(2)
        ->and($filters->filterChangedSinceImport(TranslationEntry::query(), 'invalid')->count())->toBe(4)
        ->and($filters->filterIsMissing(TranslationEntry::query(), true)->count())->toBe(1)
        ->and($filters->filterIsMissing(TranslationEntry::query(), false)->count())->toBe(3)
        ->and($filters->filterIsMissing(TranslationEntry::query(), 'invalid')->count())->toBe(4);
});

test('public list validation conflict responses and catalog keys are deterministic', function (): void {
    $rule = new StringOrList(maximumItems: 1, maximumItemLength: 3);

    expect(Validator::make(['value' => 'one'], ['value' => [$rule]])->passes())->toBeTrue()
        ->and(Validator::make(['value' => 'one,two'], ['value' => [$rule]])->fails())->toBeTrue()
        ->and(Validator::make(['value' => 'long'], ['value' => [$rule]])->fails())->toBeTrue()
        ->and(Validator::make(['value' => ['one', 'two']], ['value' => [$rule]])->fails())->toBeTrue()
        ->and(Validator::make(['value' => ['named' => 'one']], ['value' => [$rule]])->fails())->toBeTrue()
        ->and(Validator::make(['value' => 42], ['value' => [$rule]])->fails())->toBeTrue();

    $phpEntry = new TranslationEntry([
        'format' => 'php',
        'group' => 'messages',
        'key' => 'save',
    ]);
    $jsonEntry = new TranslationEntry([
        'format' => 'json',
        'group' => '*',
        'key' => 'Save',
    ]);
    $conflict = TranslationConflictException::forIdentity('app', 'en:json:Save');
    $response = $conflict->render(Request::create('/api/v1/translations/import', 'POST'));

    expect($phpEntry->fullKey())->toBe('messages.save')
        ->and($jsonEntry->fullKey())->toBe('Save')
        ->and($response->getStatusCode())->toBe(409)
        ->and($response->getData(true))->toMatchArray([
            'message' => 'Translation sync conflict for [app:en:json:Save].',
            'code' => 'translation_sync_conflict',
        ]);
});

test('doctor reports invalid configuration as structured checks without mutation', function (
    array $values,
): void {
    config()->set('translations', require __DIR__.'/../../config/translations.php');
    config()->set('translations.paths.app', $this->consumerSource);
    config()->set($values);

    expect(collect(app(TranslationsDoctor::class)->inspect())->contains(
        static fn ($check): bool => ! $check->passed,
    ))->toBeTrue();
})->with([
    'enabled route boundary' => [[
        'translations.routes.enabled' => true,
        'translations.routes.management_middleware' => [],
    ]],
    'relative app path' => [['translations.paths.app' => 'relative/path']],
    'custom scopes' => [['translations.custom_scopes' => 'shared']],
    'export targets' => [['translations.export_targets' => 'generated']],
    'reserved source target' => [['translations.export_targets.source' => ['app' => '/tmp/output']]],
    'backup path' => [['translations.backup.directory' => []]],
    'lock seconds' => [['translations.lock.seconds' => 0]],
    'lock wait' => [['translations.lock.wait_seconds' => -1]],
    'scan paths' => [['translations.scan.paths' => 'app']],
    'scan extensions' => [['translations.scan.extensions' => []]],
    'scan retention' => [['translations.scan.retention_days' => -1]],
    'scan patterns' => [['translations.scan.patterns' => ['/[/']]],
]);
