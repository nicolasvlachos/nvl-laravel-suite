<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Models\TranslationEntry;

/**
 * Records management API authorization checks without imposing consumer policy.
 */
final class TranslationApiAuthorizationFake implements TranslationsAuthorization
{
    /**
     * @var list<TranslationsAbility>
     */
    public array $abilities = [];

    /**
     * Record one authorized management ability.
     */
    public function authorize(
        TranslationsAbility $ability,
        ?TranslationEntry $entry = null,
    ): void {
        $this->abilities[] = $ability;
    }
}

beforeEach(function (): void {
    $this->apiRoot = storage_path('framework/testing/nvl-translations-api-'.Str::uuid());
    $this->apiSource = $this->apiRoot.'/source';
    $this->apiTarget = $this->apiRoot.'/target';
    $this->apiAuthorization = new TranslationApiAuthorizationFake;

    File::ensureDirectoryExists($this->apiSource);

    config([
        'translations.paths.app' => $this->apiSource,
        'translations.discovery.modules' => false,
        'translations.discovery.vendor' => false,
        'translations.custom_scopes' => [],
        'translations.export_targets' => [
            'source' => [],
            'generated' => [
                'app' => $this->apiTarget,
            ],
        ],
        'translations.backup.directory' => $this->apiRoot.'/backups',
        'translations.routes.enabled' => true,
        'translations.routes.prefix' => 'api/v1',
        'translations.routes.middleware' => ['api'],
        'translations.routes.management_middleware' => [],
    ]);
    app()->instance(TranslationsAuthorization::class, $this->apiAuthorization);

    require __DIR__.'/../../routes/api.php';
});

afterEach(function (): void {
    File::deleteDirectory($this->apiRoot);
});

test('management API lists a bounded filterable catalog with stable option shapes', function (): void {
    foreach ([
        ['locale' => 'en', 'format' => 'json', 'group' => '*', 'key' => 'Alpha'],
        ['locale' => 'en', 'format' => 'php', 'group' => 'messages', 'key' => 'save'],
        ['locale' => 'bg', 'format' => 'json', 'group' => '*', 'key' => 'Beta'],
    ] as $row) {
        TranslationEntry::query()->create([
            ...$row,
            'scope_type' => 'app',
            'scope_name' => 'app',
            'value' => 'Value',
            'source_hash' => hash('sha256', "string\0Value"),
            'is_missing' => false,
        ]);
    }

    $this->getJson('/api/v1/translations?per_page=1&filter[locale]=en&sort=key')
        ->assertSuccessful()
        ->assertJsonPath('data.entries.items.0.key', 'Alpha')
        ->assertJsonPath('data.entries.meta.perPage', 1)
        ->assertJsonPath('data.entries.meta.total', 2)
        ->assertJsonPath('data.scopeTypes', ['app'])
        ->assertJsonPath('data.scopeNames', ['app'])
        ->assertJsonPath('data.locales', ['bg', 'en'])
        ->assertJsonPath('data.groups', ['messages']);

    $this->getJson('/api/v1/translations?filter[raw_sql]=unsafe')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.raw_sql']);

    expect($this->apiAuthorization->abilities)
        ->toContain(TranslationsAbility::ListEntries);
});

test('management API requires force for writes and separately authorizes pruning', function (): void {
    File::put($this->apiSource.'/en.json', '{"Save":"Save"}');

    $this->postJson('/api/v1/translations/import', [
        'scope' => ['app'],
        'format' => 'json',
    ])->assertSuccessful();

    $this->postJson('/api/v1/translations/export', [
        'scope' => ['app'],
        'format' => 'json',
        'target' => 'generated',
        'dryRun' => true,
    ])->assertSuccessful();

    $this->postJson('/api/v1/translations/export', [
        'scope' => ['app'],
        'format' => 'json',
        'target' => 'generated',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['force']);

    $this->postJson('/api/v1/translations/export', [
        'scope' => ['app'],
        'format' => 'json',
        'target' => 'generated',
        'prune' => true,
        'force' => true,
    ])->assertSuccessful()
        ->assertJsonPath('code', 'exported');

    expect($this->apiAuthorization->abilities)
        ->toContain(TranslationsAbility::Synchronize)
        ->toContain(TranslationsAbility::Export)
        ->toContain(TranslationsAbility::Prune);
});

test('management API exposes stable conflict and lock response codes', function (): void {
    File::put($this->apiSource.'/en.json', '{"Save":"Save"}');
    $this->postJson('/api/v1/translations/import', [
        'scope' => ['app'],
        'format' => 'json',
    ])->assertSuccessful();
    $entry = TranslationEntry::query()->sole();
    expect(TranslationEntry::query()->whereKey($entry->id)->exists())->toBeTrue();

    $response = $this->patchJson("/api/v1/translations/entries/{$entry->id}", [
        'value' => 'Stale',
        'expectedRevision' => $entry->revision + 1,
    ]);
    $response->assertConflict()
        ->assertJsonPath('code', 'stale_translation_workspace');

    $lock = cache()->lock('nvl:translations:workspace', 30);
    expect($lock->get())->toBeTrue();

    try {
        $this->postJson('/api/v1/translations/import', [
            'scope' => ['app'],
            'format' => 'json',
        ])->assertStatus(423)
            ->assertJsonPath('code', 'translation_workspace_locked');
    } finally {
        $lock->release();
    }
});

test('management API renders invalid scope input as a validation response', function (): void {
    $this->postJson('/api/v1/translations/import', [
        'scope' => ['custom:missing'],
        'format' => 'json',
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_translation_input');
});

test('management API rejects ambiguous lists, oversized tokens, and missing update values', function (): void {
    $this->postJson('/api/v1/translations/import', [
        'scope' => ['named' => 'app'],
        'format' => 'json',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['scope']);

    $this->postJson('/api/v1/translations/import', [
        'scope' => str_repeat('a', 256),
        'format' => 'json',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['scope']);

    File::put($this->apiSource.'/en.json', '{"Save":"Save"}');
    $this->postJson('/api/v1/translations/import', [
        'scope' => ['app'],
        'format' => 'json',
    ])->assertSuccessful();
    $entry = TranslationEntry::query()->sole();

    $this->patchJson("/api/v1/translations/entries/{$entry->id}", [
        'expectedRevision' => $entry->revision,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['value']);
});
