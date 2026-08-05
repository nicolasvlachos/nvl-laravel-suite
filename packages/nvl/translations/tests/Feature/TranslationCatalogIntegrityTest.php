<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nvl\Translations\Actions\Entries\UpdateTranslationEntryAction;
use Nvl\Translations\Actions\Sync\ExportTranslationsAction;
use Nvl\Translations\Actions\Sync\ImportTranslationsAction;
use Nvl\Translations\Actions\Sync\ListUnusedTranslationsAction;
use Nvl\Translations\Actions\Sync\ScanTranslationsAction;
use Nvl\Translations\Data\UpdateTranslationEntryPayload;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Exceptions\TranslationWorkspaceLockedException;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Models\TranslationScanRun;
use Nvl\Translations\Models\TranslationUsage;
use Nvl\Translations\Services\TranslationArtifactWriter;
use Nvl\Translations\Services\TranslationExportService;
use Nvl\Translations\Services\TranslationScopeResolver;

beforeEach(function (): void {
    $this->catalogRoot = storage_path('framework/testing/nvl-translations-integrity-'.Str::uuid());
    $this->catalogSource = $this->catalogRoot.'/source';
    $this->catalogTarget = $this->catalogRoot.'/target';

    File::ensureDirectoryExists($this->catalogSource);

    config([
        'translations.paths.app' => $this->catalogSource,
        'translations.discovery.modules' => false,
        'translations.discovery.vendor' => false,
        'translations.custom_scopes' => [],
        'translations.export_targets' => [
            'source' => [],
            'generated' => [
                'app' => $this->catalogTarget,
            ],
        ],
        'translations.import.conflict_strategy' => 'prefer_database',
        'translations.import.fail_on_error' => true,
        'translations.backup.directory' => $this->catalogRoot.'/backups',
        'translations.scan.paths' => [$this->catalogRoot.'/code'],
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->catalogRoot);
});

test('null values round trip exactly through PHP and JSON artifacts', function (): void {
    File::ensureDirectoryExists($this->catalogSource.'/en');
    File::put(
        $this->catalogSource.'/en/messages.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn ['nullable' => null];\n",
    );
    File::put($this->catalogSource.'/en.json', '{"Nullable":null}');

    app(ImportTranslationsAction::class)->execute(['app']);
    app(ExportTranslationsAction::class)->execute(['app'], null, 'both', 'generated');

    $php = include $this->catalogTarget.'/en/messages.php';
    $json = json_decode(
        (string) File::get($this->catalogTarget.'/en.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect(TranslationEntry::query()->whereNull('value')->count())->toBe(2)
        ->and($php)->toBe(['nullable' => null])
        ->and($json)->toBe(['Nullable' => null]);
});

test('non-string translation leaves are rejected instead of being coerced', function (): void {
    File::put($this->catalogSource.'/en.json', '{"Count":5,"Enabled":true}');

    expect(fn () => app(ImportTranslationsAction::class)->execute(['app'], 'json'))
        ->toThrow(TranslationsException::class, 'non-string translation')
        ->and(TranslationEntry::query()->count())->toBe(0);
});

test('PHP import rejects ambiguous dot segments and prevents source output leakage', function (): void {
    File::ensureDirectoryExists($this->catalogSource.'/en');
    File::put(
        $this->catalogSource.'/en/messages.php',
        "<?php\n\necho 'leak';\n\nreturn ['literal.dot' => 'Value'];\n",
    );

    expect(fn () => app(ImportTranslationsAction::class)->execute(['app'], 'php'))
        ->toThrow(TranslationsException::class, 'emitted output')
        ->and(TranslationEntry::query()->count())->toBe(0);

    File::put(
        $this->catalogSource.'/en/messages.php',
        "<?php\n\nreturn ['literal.dot' => 'Value'];\n",
    );

    expect(fn () => app(ImportTranslationsAction::class)->execute(['app'], 'php'))
        ->toThrow(TranslationsException::class, 'ambiguous PHP translation key segment')
        ->and(TranslationEntry::query()->count())->toBe(0);

    File::put(
        $this->catalogSource.'/en/messages.php',
        "<?php\n\nob_start();\n\nreturn ['safe' => 'Value'];\n",
    );

    expect(fn () => app(ImportTranslationsAction::class)->execute(['app'], 'php'))
        ->toThrow(TranslationsException::class, 'altered output buffering')
        ->and(TranslationEntry::query()->count())->toBe(0);
});

test('catalog identity preserves long case-sensitive and Unicode JSON keys', function (): void {
    $longKey = str_repeat('segment-', 60);
    $payload = [
        'Save' => 'Upper',
        'save' => 'Lower',
        '0' => 'Numeric string',
        $longKey => 'Long',
        'Здравей 🌍' => 'Unicode',
    ];
    File::put(
        $this->catalogSource.'/en.json',
        json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    );

    app(ImportTranslationsAction::class)->execute(['app'], 'json');
    app(ExportTranslationsAction::class)->execute(['app'], null, 'json', 'generated');

    $exported = json_decode(
        (string) File::get($this->catalogTarget.'/en.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $expected = $payload;
    ksort($expected);

    expect(TranslationEntry::query()->count())->toBe(5)
        ->and(TranslationEntry::query()->where('key', '0')->exists())->toBeTrue()
        ->and(TranslationEntry::query()->distinct()->count('identity_hash'))->toBe(5)
        ->and($exported)->toBe($expected);
});

test('export dry runs import current files inside a rolled-back simulation', function (): void {
    File::put($this->catalogSource.'/fr.json', '{"Continue":"Continuer"}');

    $result = app(ExportTranslationsAction::class)->execute(
        ['app'],
        null,
        'json',
        'generated',
        false,
        true,
    );

    expect($result['files'])->toBe(1)
        ->and($result['locales'])->toBe(1)
        ->and(TranslationEntry::query()->count())->toBe(0)
        ->and(File::exists($this->catalogTarget.'/fr.json'))->toBeFalse();
});

test('imports report source rows that became missing in dry-run and real modes', function (): void {
    File::put($this->catalogSource.'/en.json', '{"Save":"Save"}');
    app(ImportTranslationsAction::class)->execute(['app'], 'json');
    File::delete($this->catalogSource.'/en.json');

    $planned = app(ImportTranslationsAction::class)->execute(['app'], 'json', true);
    $applied = app(ImportTranslationsAction::class)->execute(['app'], 'json');
    $repeated = app(ImportTranslationsAction::class)->execute(['app'], 'json');

    expect($planned['missing'])->toBe(1)
        ->and($applied['missing'])->toBe(1)
        ->and($repeated['missing'])->toBe(0)
        ->and(TranslationEntry::query()->sole()->is_missing)->toBeTrue();
});

test('mail is an application group convention and no longer an overlapping scope', function (): void {
    expect(fn () => app(TranslationScopeResolver::class)->resolveScopes(['mail']))
        ->toThrow(TranslationsException::class, 'Unknown translation scope');
});

test('duplicate discovered scope names and overlapping export destinations fail fast', function (): void {
    $firstRoot = $this->catalogRoot.'/modules-a';
    $secondRoot = $this->catalogRoot.'/modules-b';
    File::ensureDirectoryExists($firstRoot.'/Billing/lang');
    File::ensureDirectoryExists($secondRoot.'/Billing/lang');
    config([
        'translations.discovery.modules' => true,
        'translations.module_roots' => [$firstRoot, $secondRoot],
    ]);

    expect(fn () => app(TranslationScopeResolver::class)->discoverScopes())
        ->toThrow(TranslationsException::class, 'multiple directories');

    config([
        'translations.discovery.modules' => false,
        'translations.export_targets.generated.app' => $this->catalogSource.'/generated',
    ]);

    expect(fn () => app(TranslationScopeResolver::class)->resolveExportPaths(
        app(TranslationScopeResolver::class)->resolveScopes(['app']),
        'generated',
    ))->toThrow(TranslationsException::class, 'overlaps source scope');
});

test('ambiguous scanner namespaces require an explicit scope mapping', function (): void {
    $moduleRoot = $this->catalogRoot.'/modules';
    $customRoot = $this->catalogRoot.'/custom-foo';
    File::ensureDirectoryExists($moduleRoot.'/Foo/lang');
    File::ensureDirectoryExists($customRoot);
    config([
        'translations.discovery.modules' => true,
        'translations.module_roots' => [$moduleRoot],
        'translations.custom_scopes' => ['Foo' => $customRoot],
    ]);
    $resolver = app(TranslationScopeResolver::class);

    expect(fn () => $resolver->resolveNamespace('foo'))
        ->toThrow(TranslationsException::class, 'ambiguous');

    config(['translations.scan.namespaces.foo' => 'module:Foo']);

    expect($resolver->resolveNamespace('foo')?->token())->toBe('module:Foo');
});

test('usage identity preserves distinct namespaces referenced on the same line', function (): void {
    $fooRoot = $this->catalogRoot.'/foo';
    $barRoot = $this->catalogRoot.'/bar';
    $codeRoot = $this->catalogRoot.'/code';
    File::ensureDirectoryExists($fooRoot);
    File::ensureDirectoryExists($barRoot);
    File::ensureDirectoryExists($codeRoot);
    File::put(
        $codeRoot.'/Example.php',
        "<?php\n\n__('foo::messages.save'); __('bar::messages.save');\n",
    );
    config([
        'translations.custom_scopes' => [
            'foo' => $fooRoot,
            'bar' => $barRoot,
        ],
        'translations.scan.namespaces' => [
            'foo' => 'custom:foo',
            'bar' => 'custom:bar',
        ],
    ]);

    $result = app(ScanTranslationsAction::class)->execute();

    expect($result['hits'])->toBe(2)
        ->and(TranslationUsage::query()->distinct()->count('identity_hash'))->toBe(2)
        ->and(TranslationUsage::query()->orderBy('scope_name')->pluck('scope_name')->all())
        ->toBe(['bar', 'foo']);
});

test('workspace edits share the synchronization lock', function (): void {
    File::put($this->catalogSource.'/en.json', '{"Save":"Save"}');
    app(ImportTranslationsAction::class)->execute(['app'], 'json');
    $entry = TranslationEntry::query()->sole();
    $lock = cache()->lock('nvl:translations:workspace', 30);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(UpdateTranslationEntryAction::class)->execute(
            $entry,
            UpdateTranslationEntryPayload::validateAndCreate([
                'value' => 'Blocked',
                'expectedRevision' => $entry->revision,
            ]),
        ))->toThrow(TranslationWorkspaceLockedException::class);
    } finally {
        $lock->release();
    }
});

test('scanner records supported literal calls against their owning scope and prunes old history', function (): void {
    $codeRoot = $this->catalogRoot.'/code';
    File::ensureDirectoryExists($codeRoot);
    File::put($codeRoot.'/Example.php', <<<'PHP'
<?php

__('messages.save');
Lang::choice('messages.items', 2);
@choice('messages.people', 2)
__('unknown::messages.skipped');
PHP);
    File::put($codeRoot.'/Example.ts', <<<'JS'
t('Continue')
$t('Cancel')
JS);

    TranslationUsage::query()->create([
        'scope_type' => 'app',
        'scope_name' => 'app',
        'format' => 'json',
        'full_key' => 'Old',
        'file_path' => 'old.php',
        'line' => 1,
        'last_seen_at' => CarbonImmutable::now()->subDays(40),
    ]);

    $result = app(ScanTranslationsAction::class)->execute();

    expect($result['hits'])->toBe(5)
        ->and(TranslationUsage::query()->where('scope_type', 'app')->count())->toBe(5)
        ->and(TranslationUsage::query()->where('full_key', 'messages.skipped')->exists())->toBeFalse()
        ->and(TranslationUsage::query()->where('full_key', 'Old')->exists())->toBeFalse();
});

test('scanner rejects invalid patterns and symbolic-link file escapes', function (): void {
    config(['translations.scan.patterns' => ['/[/']]);

    expect(fn () => app(ScanTranslationsAction::class)->execute())
        ->toThrow(TranslationsException::class, 'Invalid translation scanner pattern');

    config(['translations.scan.patterns' => [
        "/(?:(?:__|trans)\\s*\\(\\s*['\"]([^'\"]+)['\"])/",
    ]]);
    $codeRoot = $this->catalogRoot.'/code';
    $outside = $this->catalogRoot.'/outside.php';
    File::ensureDirectoryExists($codeRoot);
    File::put($outside, "<?php\n\n__('Outside');\n");
    symlink($outside, $codeRoot.'/Linked.php');

    expect(fn () => app(ScanTranslationsAction::class)->execute())
        ->toThrow(TranslationsException::class, 'symbolic link');
});

test('latest zero-hit scans and usage formats drive exact unused reports', function (): void {
    $codeRoot = $this->catalogRoot.'/code';
    File::ensureDirectoryExists($codeRoot);
    File::put($codeRoot.'/Example.php', "<?php\n\n__('messages.save');\n");

    foreach ([
        ['format' => 'php', 'group' => 'messages', 'key' => 'save', 'is_missing' => false],
        ['format' => 'json', 'group' => '*', 'key' => 'messages.save', 'is_missing' => false],
        ['format' => 'json', 'group' => '*', 'key' => 'Missing', 'is_missing' => true],
    ] as $row) {
        TranslationEntry::query()->create([
            ...$row,
            'scope_type' => 'app',
            'scope_name' => 'app',
            'locale' => 'en',
            'value' => 'Value',
            'source_hash' => hash('sha256', "string\0Value"),
        ]);
    }

    app(ScanTranslationsAction::class)->execute();
    $firstReport = app(ListUnusedTranslationsAction::class)->execute(['app']);

    expect($firstReport['total'])->toBe(1)
        ->and($firstReport['rows'][0]['format'])->toBe('json')
        ->and($firstReport['rows'][0]['full_key'])->toBe('messages.save');

    File::put($codeRoot.'/Example.php', "<?php\n\n// no translation calls\n");
    app(ScanTranslationsAction::class)->execute();
    $secondReport = app(ListUnusedTranslationsAction::class)->execute(['app']);

    expect(TranslationScanRun::query()->count())->toBe(2)
        ->and(TranslationScanRun::query()->latest('scanned_at')->firstOrFail()->hits)->toBe(0)
        ->and($secondReport['total'])->toBe(2);
});

test('changed-since-import follows durable synchronization state after preserved edits', function (): void {
    File::put($this->catalogSource.'/en.json', '{"Save":"Save"}');
    app(ImportTranslationsAction::class)->execute(['app'], 'json');
    $entry = TranslationEntry::query()->sole();
    $updated = app(UpdateTranslationEntryAction::class)->execute(
        $entry,
        UpdateTranslationEntryPayload::validateAndCreate([
            'value' => 'Database edit',
            'expectedRevision' => $entry->revision,
        ]),
    );
    app(ImportTranslationsAction::class)->execute(['app'], 'json');

    $model = new TranslationEntry;
    $changedIds = $model
        ->filterChangedSinceImport(TranslationEntry::query(), true)
        ->pluck('id')
        ->all();

    expect($changedIds)->toContain($entry->id)
        ->and($entry->fresh()->sync_status->value)->toBe('edited');

    app(UpdateTranslationEntryAction::class)->execute(
        $entry->fresh(),
        UpdateTranslationEntryPayload::validateAndCreate([
            'value' => 'Save',
            'expectedRevision' => $updated->fresh()->revision,
        ]),
    );

    $unchangedIds = $model
        ->filterChangedSinceImport(TranslationEntry::query(), false)
        ->pluck('id')
        ->all();

    expect($unchangedIds)->toContain($entry->id)
        ->and($entry->fresh()->sync_status->value)->toBe('synchronized');
});

test('artifact batches stage every replacement before changing an existing file', function (): void {
    $existingPath = $this->catalogRoot.'/batch/existing.json';
    $blockingParent = $this->catalogRoot.'/batch/not-a-directory';
    File::ensureDirectoryExists(dirname($existingPath));
    File::put($existingPath, '{"state":"original"}');
    File::put($blockingParent, 'block');
    $originalInode = fileinode($existingPath);

    $failed = false;

    try {
        app(TranslationArtifactWriter::class)->apply([
            [
                'path' => $existingPath,
                'content' => '{"state":"changed"}',
                'target_root' => $this->catalogRoot.'/batch',
            ],
            [
                'path' => $blockingParent.'/second.json',
                'content' => '{"state":"second"}',
                'target_root' => $this->catalogRoot.'/batch',
            ],
        ], []);
    } catch (Throwable) {
        $failed = true;
    }

    expect($failed)->toBeTrue()
        ->and(File::get($existingPath))->toBe('{"state":"original"}')
        ->and(fileinode($existingPath))->toBe($originalInode);
});

test('artifact plans reject paths outside their declared target root', function (): void {
    expect(fn () => app(TranslationArtifactWriter::class)->validatePlan([
        [
            'path' => $this->catalogRoot.'/outside.json',
            'content' => '{"safe":true}',
            'target_root' => $this->catalogTarget,
        ],
    ], []))->toThrow(TranslationsException::class, 'escapes its configured root');
});

test('artifact plans reject case-insensitive destination collisions', function (): void {
    foreach (['en', 'EN'] as $locale) {
        TranslationEntry::query()->create([
            'scope_type' => 'app',
            'scope_name' => 'app',
            'locale' => $locale,
            'format' => 'json',
            'group' => '*',
            'key' => 'Save',
            'value' => 'Save',
            'source_hash' => hash('sha256', "string\0Save"),
            'is_missing' => false,
        ]);
    }

    expect(fn () => app(TranslationExportService::class)->execute(
        ['app'],
        null,
        'json',
        'generated',
        false,
        true,
    ))->toThrow(TranslationsException::class, 'targeted more than once');
});

test('PHP export rejects scalar and nested dot-key shape collisions', function (): void {
    foreach ([
        'settings' => 'Scalar',
        'settings.label' => 'Nested',
    ] as $key => $value) {
        TranslationEntry::query()->create([
            'scope_type' => 'app',
            'scope_name' => 'app',
            'locale' => 'en',
            'format' => 'php',
            'group' => 'messages',
            'key' => $key,
            'value' => $value,
            'source_hash' => hash('sha256', "string\0{$value}"),
            'is_missing' => false,
        ]);
    }

    expect(fn () => app(TranslationExportService::class)->execute(
        ['app'],
        ['en'],
        'php',
        'generated',
    ))->toThrow(TranslationsException::class, 'conflicts with a scalar PHP key')
        ->and(File::exists($this->catalogTarget.'/en/messages.php'))->toBeFalse();
});

test('PHP export rejects null leaf and nested dot-key shape collisions', function (): void {
    foreach ([
        'settings' => null,
        'settings.label' => 'Nested',
    ] as $key => $value) {
        TranslationEntry::query()->create([
            'scope_type' => 'app',
            'scope_name' => 'app',
            'locale' => 'en',
            'format' => 'php',
            'group' => 'messages',
            'key' => $key,
            'value' => $value,
            'source_hash' => hash(
                'sha256',
                $value === null ? "null\0" : "string\0{$value}",
            ),
            'is_missing' => false,
        ]);
    }

    expect(fn () => app(TranslationExportService::class)->execute(
        ['app'],
        ['en'],
        'php',
        'generated',
    ))->toThrow(TranslationsException::class, 'conflicts with a scalar PHP key')
        ->and(File::exists($this->catalogTarget.'/en/messages.php'))->toBeFalse();
});

test('prefer strategies resolve conflicts without a failing command exit code', function (): void {
    File::put($this->catalogSource.'/en.json', '{"Save":"Save"}');
    app(ImportTranslationsAction::class)->execute(['app'], 'json');
    $entry = TranslationEntry::query()->sole();
    app(UpdateTranslationEntryAction::class)->execute(
        $entry,
        UpdateTranslationEntryPayload::validateAndCreate([
            'value' => 'Database edit',
            'expectedRevision' => $entry->revision,
        ]),
    );
    File::put($this->catalogSource.'/en.json', '{"Save":"File edit"}');

    $this->artisan('nvl:translations:sync', [
        '--scope' => ['app'],
        '--format' => 'json',
        '--strategy' => 'prefer-file',
        '--output' => 'json',
    ])->assertSuccessful();

    expect($entry->fresh()->value)->toBe('File edit');
});

test('exports refuse to overwrite from an incomplete authoritative read', function (): void {
    File::put($this->catalogSource.'/en.json', '{"Save":"Save"}');
    app(ImportTranslationsAction::class)->execute(['app'], 'json');
    File::put($this->catalogSource.'/en.json', '{"Save":');
    config()->set('translations.import.fail_on_error', false);

    expect(fn () => app(ExportTranslationsAction::class)->execute(
        ['app'],
        null,
        'json',
        'generated',
    ))->toThrow(TranslationsException::class, 'authoritative source read was incomplete')
        ->and(File::exists($this->catalogTarget.'/en.json'))->toBeFalse()
        ->and(File::get($this->catalogSource.'/en.json'))->toBe('{"Save":');
});

test('status command constrains aggregates to explicitly selected scopes', function (): void {
    $customPath = $this->catalogRoot.'/custom';
    File::ensureDirectoryExists($customPath);
    config(['translations.custom_scopes.shared' => $customPath]);

    foreach ([
        ['scope_type' => 'app', 'scope_name' => 'app', 'key' => 'App', 'sync_status' => 'edited'],
        ['scope_type' => 'custom', 'scope_name' => 'shared', 'key' => 'Custom', 'sync_status' => 'missing'],
    ] as $row) {
        TranslationEntry::query()->create([
            ...$row,
            'locale' => 'en',
            'format' => 'json',
            'group' => '*',
            'value' => 'Value',
            'source_hash' => hash('sha256', "string\0Value"),
            'is_missing' => false,
        ]);
    }

    $this->artisan('nvl:translations:status', [
        '--scope' => ['app'],
        '--format' => 'json',
    ])->expectsOutputToContain('"edited": 1')
        ->doesntExpectOutputToContain('"missing"')
        ->assertSuccessful();
});
