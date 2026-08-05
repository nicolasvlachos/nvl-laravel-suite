<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Exceptions\TranslationConflictException;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Rules\StringOrList;
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
