<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nvl\Translations\Actions\Entries\UpdateTranslationEntryAction;
use Nvl\Translations\Actions\Sync\ExportTranslationsAction;
use Nvl\Translations\Actions\Sync\ImportTranslationsAction;
use Nvl\Translations\Data\UpdateTranslationEntryPayload;
use Nvl\Translations\Events\TranslationEntryUpdated;
use Nvl\Translations\Events\TranslationsExported;
use Nvl\Translations\Events\TranslationsImported;
use Nvl\Translations\Events\TranslationsScanned;
use Nvl\Translations\Exceptions\StaleTranslationWorkspaceException;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Exceptions\TranslationWorkspaceLockedException;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Services\TranslationPathGuard;
use Nvl\Translations\Services\TranslationScopeResolver;

beforeEach(function (): void {
    $this->translationSyncRoot = storage_path('framework/testing/nvl-translations-'.Str::uuid());
    $this->translationSource = $this->translationSyncRoot.'/source';
    $this->translationTarget = $this->translationSyncRoot.'/target';

    File::ensureDirectoryExists($this->translationSource);

    config([
        'translations.paths.app' => $this->translationSource,
        'translations.discovery.modules' => false,
        'translations.discovery.vendor' => false,
        'translations.custom_scopes' => [],
        'translations.export_targets' => [
            'source' => [],
            'generated' => [
                'app' => $this->translationTarget,
            ],
        ],
        'translations.import.conflict_strategy' => 'prefer_database',
        'translations.import.fail_on_error' => true,
        'translations.backup.directory' => $this->translationSyncRoot.'/backups',
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->translationSyncRoot);
});

test('PHP and JSON files round trip through editable database rows into a configured target', function (): void {
    File::ensureDirectoryExists($this->translationSource.'/en');
    File::put($this->translationSource.'/en/messages.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'actions' => [
        'save' => 'Save',
    ],
];
PHP);
    File::put($this->translationSource.'/bg.json', <<<'JSON'
{
    "Save": "Запази"
}
JSON);

    $imported = app(ImportTranslationsAction::class)->execute(['app']);

    expect($imported)
        ->toMatchArray([
            'scopes' => 1,
            'files' => 2,
            'entries' => 2,
            'created' => 2,
            'warnings' => [],
        ])
        ->and(TranslationEntry::query()->count())->toBe(2);

    $phpEntry = TranslationEntry::query()
        ->where('format', 'php')
        ->where('locale', 'en')
        ->firstOrFail();

    app(UpdateTranslationEntryAction::class)->execute(
        $phpEntry,
        UpdateTranslationEntryPayload::validateAndCreate([
            'value' => 'Save changes',
            'expectedRevision' => $phpEntry->revision,
        ]),
    );

    $reimported = app(ImportTranslationsAction::class)->execute(['app']);

    expect($reimported['preserved'])->toBe(1)
        ->and($phpEntry->fresh()->value)->toBe('Save changes');

    $exported = app(ExportTranslationsAction::class)->execute(
        ['app'],
        null,
        'both',
        'generated',
    );

    expect($exported)->toMatchArray([
        'files' => 2,
        'deleted' => 0,
        'target' => 'generated',
    ]);

    $phpPayload = include $this->translationTarget.'/en/messages.php';
    $jsonPayload = json_decode(
        (string) File::get($this->translationTarget.'/bg.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($phpPayload)->toBe(['actions' => ['save' => 'Save changes']])
        ->and($jsonPayload)->toBe(['Save' => 'Запази']);

    app(ImportTranslationsAction::class)->execute(['app']);

    expect($phpEntry->fresh()->value)->toBe('Save changes');
});

test('JSON-only locales import without requiring a matching PHP locale directory', function (): void {
    File::put($this->translationSource.'/fr.json', '{"Continue":"Continuer"}');

    $result = app(ImportTranslationsAction::class)->execute(['app'], 'json');

    expect($result['files'])->toBe(1)
        ->and($result['entries'])->toBe(1)
        ->and(TranslationEntry::query()->firstOrFail())
        ->locale->toBe('fr')
        ->group->toBe('*')
        ->format->toBe('json')
        ->value->toBe('Continuer');
});

test('format selection leaves unselected catalog formats untouched', function (): void {
    File::ensureDirectoryExists($this->translationSource.'/en');
    File::put($this->translationSource.'/en/messages.php', "<?php\n\nreturn ['save' => 'Save'];\n");
    File::put($this->translationSource.'/en.json', '{"Save":"Save"}');

    app(ImportTranslationsAction::class)->execute(['app'], 'json');

    expect(TranslationEntry::query()->pluck('format')->all())->toBe(['json']);
});

test('strict malformed input does not partially mutate the database catalog', function (): void {
    TranslationEntry::query()->create([
        'scope_type' => 'app',
        'scope_name' => 'app',
        'locale' => 'en',
        'format' => 'json',
        'group' => '*',
        'key' => 'Existing',
        'value' => 'Keep me',
        'source_hash' => hash('sha256', 'Keep me'),
        'is_missing' => false,
    ]);
    File::put($this->translationSource.'/en.json', '{"Existing":"Changed"}');
    File::put($this->translationSource.'/bg.json', '{"Broken":');

    expect(fn () => app(ImportTranslationsAction::class)->execute(['app'], 'json'))
        ->toThrow(TranslationsException::class, 'stopped before database synchronization');

    $entry = TranslationEntry::query()->firstOrFail();

    expect($entry->value)->toBe('Keep me')
        ->and($entry->is_missing)->toBeFalse();
});

test('pruning is explicit and limited to the selected locale and configured target', function (): void {
    File::ensureDirectoryExists($this->translationSource.'/en');
    File::put($this->translationSource.'/en/messages.php', "<?php\n\nreturn ['save' => 'Save'];\n");
    app(ImportTranslationsAction::class)->execute(['app'], 'php');

    File::ensureDirectoryExists($this->translationTarget.'/en');
    File::ensureDirectoryExists($this->translationTarget.'/bg');
    File::put($this->translationTarget.'/en/stale.php', "<?php\n\nreturn ['stale' => 'Stale'];\n");
    File::put($this->translationTarget.'/en/keep.txt', 'not a translation file');
    File::put($this->translationTarget.'/bg/stale.php', "<?php\n\nreturn ['stale' => 'Остаряло'];\n");

    $withoutPrune = app(ExportTranslationsAction::class)->execute(
        ['app'],
        ['en'],
        'php',
        'generated',
        false,
    );

    expect($withoutPrune['deleted'])->toBe(0)
        ->and(File::exists($this->translationTarget.'/en/stale.php'))->toBeTrue();

    $withPrune = app(ExportTranslationsAction::class)->execute(
        ['app'],
        ['en'],
        'php',
        'generated',
        true,
    );

    expect($withPrune['deleted'])->toBe(1)
        ->and(File::exists($this->translationTarget.'/en/stale.php'))->toBeFalse()
        ->and(File::exists($this->translationTarget.'/en/keep.txt'))->toBeTrue()
        ->and(File::exists($this->translationTarget.'/bg/stale.php'))->toBeTrue();
});

test('scope and destination selection only accepts configured names and safe locales', function (): void {
    $resolver = app(TranslationScopeResolver::class);

    expect(fn () => $resolver->resolveScopes(['custom:missing']))
        ->toThrow(TranslationsException::class, 'Unknown translation scope')
        ->and(fn () => app(ExportTranslationsAction::class)->execute(
            ['app'],
            null,
            'json',
            'arbitrary-path',
        ))
        ->toThrow(TranslationsException::class, 'Unknown translation export target')
        ->and(fn () => app(ExportTranslationsAction::class)->execute(
            ['app'],
            ['../outside'],
            'json',
            'generated',
        ))
        ->toThrow(TranslationsException::class, 'Invalid translation locale');
});

test('custom file scopes resolve from configuration', function (): void {
    $customPath = $this->translationSyncRoot.'/shared';
    config([
        'translations.custom_scopes' => [
            'shared' => $customPath,
        ],
        'translations.export_targets.generated.custom:shared' => $this->translationSyncRoot.'/shared-output',
    ]);

    $scope = app(TranslationScopeResolver::class)->resolveScopes(['custom:shared'])[0];

    expect($scope->token())->toBe('custom:shared')
        ->and($scope->path)->toBe($customPath)
        ->and(app(TranslationScopeResolver::class)->resolveExportPath($scope, 'generated'))
        ->toBe($this->translationSyncRoot.'/shared-output');
});

test('translation synchronization events are commit-aware', function (): void {
    expect(is_subclass_of(TranslationEntryUpdated::class, ShouldDispatchAfterCommit::class))->toBeTrue()
        ->and(is_subclass_of(TranslationsImported::class, ShouldDispatchAfterCommit::class))->toBeTrue()
        ->and(is_subclass_of(TranslationsExported::class, ShouldDispatchAfterCommit::class))->toBeTrue()
        ->and(is_subclass_of(TranslationsScanned::class, ShouldDispatchAfterCommit::class))->toBeTrue();

    Event::fake([TranslationsImported::class]);
    File::put($this->translationSource.'/en.json', '{"Save":"Save"}');
    app(ImportTranslationsAction::class)->execute(['app'], 'json');

    Event::assertDispatched(TranslationsImported::class);
});

test('workspace edits require the current optimistic revision', function (): void {
    File::put($this->translationSource.'/en.json', '{"Save":"Save"}');
    app(ImportTranslationsAction::class)->execute(['app'], 'json');
    $entry = TranslationEntry::query()->sole();
    $revision = $entry->revision;
    $action = app(UpdateTranslationEntryAction::class);

    $updated = $action->execute(
        $entry,
        UpdateTranslationEntryPayload::validateAndCreate([
            'value' => 'Save changes',
            'expectedRevision' => $revision,
        ]),
    );

    expect($updated->revision)->toBe($revision + 1)
        ->and($updated->sync_status->value)->toBe('edited')
        ->and(fn () => $action->execute(
            $updated,
            UpdateTranslationEntryPayload::validateAndCreate([
                'value' => 'Overwrite',
                'expectedRevision' => $revision,
            ]),
        ))->toThrow(StaleTranslationWorkspaceException::class);
});

test('dry runs report import and export plans without changing state', function (): void {
    File::put($this->translationSource.'/en.json', '{"Save":"Save"}');

    $sync = app(ImportTranslationsAction::class)->execute(['app'], 'json', true);

    expect($sync['created'])->toBe(1)
        ->and(TranslationEntry::query()->count())->toBe(0);

    app(ImportTranslationsAction::class)->execute(['app'], 'json');
    $plan = app(ExportTranslationsAction::class)->execute(
        ['app'],
        null,
        'json',
        'generated',
        false,
        true,
    );

    expect($plan['files'])->toBe(1)
        ->and(File::exists($this->translationTarget.'/en.json'))->toBeFalse();
});

test('conflicts fail safely and explicit strategies retain conflict metadata', function (): void {
    File::put($this->translationSource.'/en.json', '{"Save":"Save"}');
    app(ImportTranslationsAction::class)->execute(['app'], 'json');
    $entry = TranslationEntry::query()->sole();
    app(UpdateTranslationEntryAction::class)->execute(
        $entry,
        UpdateTranslationEntryPayload::validateAndCreate([
            'value' => 'Database edit',
            'expectedRevision' => $entry->revision,
        ]),
    );
    File::put($this->translationSource.'/en.json', '{"Save":"File edit"}');

    config()->set('translations.import.conflict_strategy', 'fail');

    expect(fn () => app(ImportTranslationsAction::class)->execute(['app'], 'json'))
        ->toThrow(TranslationsException::class, 'sync conflict');

    config()->set('translations.import.conflict_strategy', 'prefer_file');
    $result = app(ImportTranslationsAction::class)->execute(['app'], 'json');
    $resolved = $entry->fresh();

    expect($result['conflicts'])->toBe(1)
        ->and($resolved->value)->toBe('File edit')
        ->and($resolved->sync_status->value)->toBe('conflict')
        ->and($resolved->conflict_metadata)->toMatchArray([
            'strategy' => 'prefer_file',
        ]);
});

test('export re-reads authoritative files and creates replacement backups', function (): void {
    File::put($this->translationSource.'/en.json', '{"Save":"Save"}');
    app(ImportTranslationsAction::class)->execute(['app'], 'json');
    File::put($this->translationSource.'/en.json', '{"Save":"File edit"}');

    app(ExportTranslationsAction::class)->execute(
        ['app'],
        null,
        'json',
        'generated',
    );

    expect(TranslationEntry::query()->sole()->value)->toBe('File edit')
        ->and(json_decode(
            (string) File::get($this->translationTarget.'/en.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ))->toBe(['Save' => 'File edit']);

    $entry = TranslationEntry::query()->sole();
    app(UpdateTranslationEntryAction::class)->execute(
        $entry,
        UpdateTranslationEntryPayload::validateAndCreate([
            'value' => 'Database replacement',
            'expectedRevision' => $entry->revision,
        ]),
    );
    config()->set('translations.import.conflict_strategy', 'prefer_database');
    app(ExportTranslationsAction::class)->execute(['app'], null, 'json', 'source');

    expect(File::allFiles($this->translationSyncRoot.'/backups'))->not->toBeEmpty();
});

test('path guards reject symlink escapes and workspace locks reject concurrent sync', function (): void {
    $outside = $this->translationSyncRoot.'/outside';
    File::ensureDirectoryExists($outside);
    symlink($outside, $this->translationSource.'/linked');

    expect(fn () => app(TranslationPathGuard::class)
        ->child($this->translationSource, 'linked', 'en.json'))
        ->toThrow(TranslationsException::class, 'symbolic link')
        ->and(app(TranslationPathGuard::class)->root('/'))->toBe('/');

    File::delete($this->translationSource.'/linked');
    File::put($this->translationSource.'/en.json', '{"Save":"Save"}');
    $lock = Cache::lock('nvl:translations:workspace', 30);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(ImportTranslationsAction::class)->execute(['app'], 'json'))
            ->toThrow(TranslationWorkspaceLockedException::class);
    } finally {
        $lock->release();
    }
});

test('doctor and status commands report a healthy standalone workspace', function (): void {
    $this->artisan('nvl:translations:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertSuccessful();

    $this->artisan('nvl:translations:status', [
        '--scope' => ['app'],
        '--format' => 'json',
    ])->assertSuccessful();
});

test('doctor strict mode promotes disabled backup warnings to failures', function (): void {
    config()->set('translations.backup.enabled', false);

    $this->artisan('nvl:translations:doctor', [
        '--format' => 'json',
    ])->assertSuccessful();

    $this->artisan('nvl:translations:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertFailed();
});
