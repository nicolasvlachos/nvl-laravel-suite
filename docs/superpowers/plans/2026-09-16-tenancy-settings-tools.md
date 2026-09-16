# Tenancy Settings and Tools Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Separate platform configuration and source-language catalogs from tenant values, and preserve ownership across filtering, DTO input, and CSV process boundaries.

**Architecture:** Settings and Translations own their value stores and policy. CSV adopts the inert Tenancy library for queued work; Filterable and Data stay tenancy-neutral and are tested with caller-owned predicates and mutation contracts. No tenant setting changes process-wide Laravel configuration.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4, Eloquent, existing cache/queue/filesystem infrastructure.

**Spec:** [Execution contracts](../specs/2026-09-16-tenancy-execution-contracts.md) and [suite design](../specs/2026-09-16-configurable-tenancy-design.md).

## Global Constraints

- PHP 8.4 and Laravel 13 are the execution baseline.
- No new external Composer dependency is required.
- All tenancy types and runtime logic live in `Nvl\Tenancy`, including the disabled implementation. Support remains free of tenant domain logic.
- Missing context in enabled mode fails closed. Platform access, central identity use cases, and tenant operations are explicit and separate.
- Released migrations are immutable.
- Tenant IDs are server-owned fields, never ordinary mutation DTO input or filter aliases.
- This document is a plan. Execute no migrations, runtime changes, tests, dependency updates, or commits during the planning review.

## Delivery order and test conventions

**Tasks 1 and 2 are prerequisites for the first Auth/Media tenant proof.** Task 1 blocks tenant-enabled boot with Settings installed. Task 2 establishes generic predicate preservation and ownership-input proof before early adopters use Filterable/Data. Full Settings overlays, Translations overlays, and queued CSV support can follow later; their tenant features remain unavailable until their tasks pass.

Load backend architecture, Settings/Translations/Filterable/Data, Pest, and testing-practices skills as applicable. Run Boost `search-docs` before implementation. Preserve existing test topology: pure DTO tests stay Unit; Eloquent behavior is Feature; serialization/workers/concurrency is Integration. Do not copy the entire suite into HTTP tests.

Run package tests from repository root with the actual package configuration and root bootstrap. For example:

```bash
vendor/bin/pest --test-directory=packages/nvl/settings/tests --configuration=packages/nvl/settings/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/settings/tests/Tenancy/TenantSettingsTest.php
```

The early ConfigOverride test below deliberately uses a fully defined test-only context. It proves admission before SQL and does not pretend to test directory/adoption. For database tenant tests create local `tests/TenancyTestCase.php` and `tests/Fixtures/TenantScenario.php` in Settings, Translations and CSV using the complete code in [Workflow concrete test setup](2026-09-16-tenancy-workflows.md#concrete-test-setup-shared-by-these-plans). These are local copies, not a shared development dependency. Replace namespaces/providers with the package's `tests/TestCase.php` providers, set `tenancy.resources` to the corresponding family and call `TenantScenario::activate(['settings'])`, `['translations']`, or `['csv']` after migrations. CSV leaves `tenancy.resources=[]` and registers `src/Tenancy/CsvAdoptionAdapter.php` under adoption package key `csv`, with `resources(): array` returning `[]`; its prepare/backfill/verify/activate methods verify/version or drain legacy manifests and record readiness without inventing a tenant Eloquent root or resource-family override. Copy Settings discovery/cache environment values verbatim. Bind the helper's directory/platform/maintenance adapters in `defineEnvironment()`; load core migrations via the shown provider ReflectionClass path; route only `tests/Tenancy` to this case. Never wrap these tests in `RefreshDatabase` transactions or insert active markers manually.

## File and ownership map

| Surface | Existing files | New files |
|---|---|---|
| Platform bootstrap | `packages/nvl/settings/src/Services/ConfigOverrideApplier.php`, `src/Providers/SettingsServiceProvider.php` | `packages/nvl/settings/src/Services/PlatformSettingsReader.php` |
| Tenant settings | `packages/nvl/settings/src/SettingManager.php`, `src/Services/SettingCache.php`, `src/Observers/SettingCacheObserver.php`, `src/Support/DefinitionRepository.php` | `packages/nvl/settings/src/Services/SettingCacheIdentity.php`, `src/Tenancy/SettingsResourceRegistrar.php`, `src/Tenancy/SettingsAdoptionAdapter.php` |
| Source catalogs/overlays | `packages/nvl/translations/src/Actions/Entries/ListTranslationEntriesAction.php`, `src/Services/TranslationScopeResolver.php`, `src/Support/TranslationIdentity.php` | `packages/nvl/translations/src/Contracts/TenantTranslationRepository.php`, `src/Services/DatabaseTenantTranslationRepository.php`, `src/Models/TenantTranslationOverride.php`, `src/Actions/ExportTenantTranslationsAction.php` |
| Generic input/query proof | `packages/nvl/filterable/src/Services/EloquentFilterApplier.php`, `packages/nvl/data/src/Traits/DataTransform.php` | `packages/nvl/filterable/tests/Feature/PredicatePreservationTest.php`, `packages/nvl/data/tests/Feature/OwnershipInputProjectionTest.php` |
| CSV boundary | `packages/nvl/csv/src/Services/CSVAsyncProcessor.php`, `src/Jobs/ProcessCSVChunkJob.php` | `packages/nvl/csv/src/Contracts/CSVRowHandler.php`, `src/ValueObjects/CSVWorkReference.php`, `src/Services/CSVWorkStore.php`, `src/Services/CSVHandlerRegistry.php` |

The `src/` entries in a table row belong to the package named at the start of that row. Subsequent tasks list exact test paths and interfaces.

### Task 1: Make Settings bootstrap platform-only before any tenant application starts

**Files:** Modify `packages/nvl/settings/src/Services/ConfigOverrideApplier.php`, `packages/nvl/settings/src/Providers/SettingsServiceProvider.php`, `packages/nvl/settings/composer.json`; create `packages/nvl/settings/src/Services/PlatformSettingsReader.php`, `PlatformSettingsBootstrap.php`, `PlatformConfigWriter.php`, `packages/nvl/settings/tests/Feature/TenantBootstrapSafetyTest.php`, `packages/nvl/settings/tests/Tenancy/PlatformSettingsProviderBootTest.php`.

**Interfaces:** Consume `TenantContext::snapshot(): TenantContextSnapshot`. Produce `PlatformSettingsReader::records(): Collection<int, Setting>` restricted to explicit platform rows and allowlisted config-mapped definitions. Add `PlatformSettingsBootstrap::apply(): void`, admitted only while the application reports `isBooted() === false`; it reads through this fixed reader and never enters Platform context. `PlatformConfigWriter::apply(Collection $records): void` owns the existing mapping/default/denied-key loop and remains an internal implementation. `ConfigOverrideApplier::apply(): void` retains its signature: Disabled preserves current behavior, explicitly authorized Platform uses the fixed reader, and Tenant/Unresolved calls reject before SQL or Config mutation. Runtime reapplication requires the existing `TenantRunner::platform` admission; bootstrap does not authorize runtime platform operations.

- [ ] Write this failing public-boundary test. Its anonymous context is complete; no fake tenant harness method is implied.

```php
use Nvl\Settings\Services\ConfigOverrideApplier;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantId;

test('tenant runtime cannot apply process configuration overrides', function (): void {
    $tenant = new TenantId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $this->app->instance(TenantContext::class, new class($tenant) implements TenantContext {
        public function __construct(private TenantId $id) {}
        public function snapshot(): TenantContextSnapshot
        {
            return new TenantContextSnapshot(TenantContextMode::Tenant, $this->id);
        }
        public function requireTenant(): TenantId { return $this->id; }
    });
    config()->set('settings.overrides.enabled', true);
    $before = config()->all();

    expect(fn () => app(ConfigOverrideApplier::class)->apply())
        ->toThrow(TenantBoundaryViolation::class);
    expect(config()->all())->toBe($before);
});
```

Add a companion disabled-mode test using the existing override fixtures: mapped defaults and persisted overrides still work, and no tenancy tables are required. Add an actual enabled provider-boot pass test in `PlatformSettingsProviderBootTest.php`. Its local `PlatformSettingsBootTestCase` extends Orchestra Testbench with `DatabaseMigrations`, copies the existing Settings provider/environment setup plus TenancyServiceProvider, creates a `tempnam(sys_get_temp_dir(), 'settings-boot-')` SQLite file before `parent::setUp()`, and configures that exact path in `defineEnvironment()`. Initial boot sets overrides and tenancy off; normal package migrations run. This prerequisite test intentionally uses the legacy platform store, which exists before tenant Settings adoption in Task 3. Add a valid config-mapped platform fixture definition (`branding.name`, text, default `Platform name`, target `app.name`), use the existing `SettingRepository::set('branding.name', 'Persisted platform')` in that disabled application, then set a test-case boolean `enableBootstrap=true` and call the real Testbench `refreshApplication()`. Its `defineEnvironment()` sets both tenancy and overrides from that boolean and discovers the same fixture source file on every boot. Assert `config('app.name') === 'Persisted platform'` and `TenantContext::snapshot()->mode === Unresolved` immediately after refresh. No migrations or adoption helper rerun on this manual refresh; the SQLite file preserves the real platform row. A second test points the reboot at an unavailable database and requires the original connection exception. Delete the temporary file in teardown after closing database connections. Also assert a direct `PlatformSettingsBootstrap::apply()` call after boot rejects. In Task 3 replay this same reboot scenario starting with enabled mode and real `TenantScenario::activate(['settings'])`, set the platform value inside `TenantRunner::platform`, and prove tenant overrides of config-mapped definitions are forbidden. That adopted-marker variant depends on Task 3's real adapter and must not be simulated in this prerequisite.

- [ ] Run `vendor/bin/pest --test-directory=packages/nvl/settings/tests --configuration=packages/nvl/settings/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/settings/tests/Feature/TenantBootstrapSafetyTest.php`. Expected red: tenant invocation currently mutates Config or fails for an unrelated database reason.
- [ ] Implement the runtime admission branch before reading Settings. Change `SettingsServiceProvider::applyConfigOverrides()` to invoke `PlatformSettingsBootstrap` during provider `boot()`, before `Application::isBooted()` becomes true; remove its current `app->booted(...)` deferral. Its fixed platform reader checks explicit table absence and installation state without using the tenant repository. Missing tables on an unadopted install preserve current no-op behavior; prepared/adopted/schema/connection failures propagate. At Task 1, an unadopted legacy Settings table is the platform store; after Task 3 it requires `ownership_key=platform`. This is a narrow config projection, not general platform admission.

```php
$mode = $this->context->snapshot()->mode;
if ($mode === TenantContextMode::Tenant || $mode === TenantContextMode::Unresolved) {
    throw new TenantBoundaryViolation('Settings overrides require the platform bootstrap boundary.');
}
$records = $this->platformSettings->records();
```

The bootstrap service performs `if ($this->app->isBooted()) { throw new TenantBoundaryViolation('Platform configuration bootstrap has ended.'); }`, then calls the same internal writer with `PlatformSettingsReader::records()`. Neither service mutates TenantContext or grants a Platform lease. The reader/writer are package-internal services and cannot expose tenant values through an unresolved default query.

Keep platform defaults/overrides and tenant value resolution separate. Source definitions remain immutable singletons. Convert services capturing context to scoped bindings; do not retain a tenant resolver inside a singleton. Preserve the global mail testing interceptor. Replace `applyConfigOverrides()`'s broad database-error suppression with an explicit missing-table branch plus propagated connection/schema failure. Add Tenancy dependency/provider closure without enabling its feature or migrations.

- [ ] Rerun the targeted test and existing `packages/nvl/settings/tests` suite. Expected green: tenant invocation fails before SQL/Config writes; disabled bootstrap remains compatible.
- [ ] Review and commit only this prerequisite: `git commit -m "feat(settings): isolate platform configuration bootstrap"` after staging the exact task files and required manifest/contract updates.

### Task 2: Prove predicate preservation and server-owned DTO fields early

**Files:** Create `packages/nvl/filterable/tests/Feature/PredicatePreservationTest.php`, `packages/nvl/data/tests/Feature/OwnershipInputProjectionTest.php`; modify `packages/nvl/filterable/src/Services/EloquentFilterApplier.php` only if the proof fails. Add tenant mutation-input cases to each early adopter's own DTO/Action tests. Do not add Tenancy to Data or Filterable's runtime dependencies.

**Interfaces:** Preserve `EloquentFilterApplier::apply(Builder $query, FilterSet $set, FilterSchema $schema): Builder`. Custom handlers remain trusted code, but ordinary OR predicates must be grouped inside the existing ownership predicate. DataTransform preserves declared fields; domain DTOs explicitly prohibit `tenant_id`, `tenantId`, `ownership_key`, and `ownershipKey` at HTTP validation and never pass them to persistence.

- [ ] Write the following self-contained failing regression. Use its explicit local model/schema; it does not import Tenancy or require a host application.

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Services\EloquentFilterApplier;

final class PredicateRecord extends Model
{
    protected $table = 'predicate_records';
    protected $guarded = [];
    public $timestamps = false;
}

test('custom OR filter preserves the caller ownership predicate', function (): void {
    Schema::create('predicate_records', function (Blueprint $table): void {
        $table->id();
        $table->string('owner');
        $table->string('name');
    });
    try {
        $a = PredicateRecord::query()->create(['owner' => 'a', 'name' => 'match']);
        PredicateRecord::query()->create(['owner' => 'b', 'name' => 'match']);
        $schema = new FilterSchema([
            new FilterDefinition('search', 'name', handler:
                static fn (Builder $query, FilterCriterion $criterion): Builder =>
                    $query->orWhere('name', $criterion->value)),
        ], []);
        $set = new FilterSet([new FilterCriterion('search', FilterOperator::Equals, 'match')]);
        $query = PredicateRecord::query()->where('owner', 'a');
        $ids = app(EloquentFilterApplier::class)->apply($query, $set, $schema)->pluck('id')->all();
        expect($ids)->toBe([$a->id]);
    } finally {
        Schema::dropIfExists('predicate_records');
    }
});
```

- [ ] Run the single Filterable test with its package configuration/root bootstrap. Expected red: the direct handler OR broadens the caller query.
- [ ] Group custom predicate handlers with Eloquent nested `where`, preserving the outer builder and its scopes. Keep handlers predicate-only; sorting stays in declared sort definitions. Review existing handlers that add joins/selects/order clauses and move such query setup into the caller before filtering, with an upgrading note; do not silently discard their effects.

```php
if ($definition->handler !== null) {
    $query->where(function (Builder $nested) use ($definition, $criterion): void {
        ($definition->handler)($nested, $criterion);
    });
    return;
}
```

Add relation-filter cases with both related tenant predicates and corrupt cross-owner relationship rows; related ownership belongs to the domain relationship/registered scope. Add `not_equals`, null, multiple custom filters, sorts, pagination/count, empty sets, and normal disabled consumers. Explicitly document that arbitrary trusted callbacks/raw SQL can bypass application authorization; this is not a sandbox.

For Data add this complete fixture/test. Separately use each domain DTO's `validateAndCreate()` with every forged ownership alias and assert `ValidationException`, then execute its Action and check server-derived ownership. Do not encode a universal ban on the word `tenant` inside generic Data infrastructure.

```php
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;

final class OwnershipProjectionData extends Data
{
    use DataTransform;
    public function __construct(public readonly string $name) {}
}

test('undeclared ownership input is absent from persistence projection', function (): void {
    $data = OwnershipProjectionData::from(['name' => 'safe', 'tenantId' => 'forged']);
    expect($data->toModelFiltered())->toBe(['name' => 'safe']);
});
```

- [ ] Rerun Filterable and Data package suites plus the early adopter's exact DTO tests. Expected green: existing caller predicates survive every supported filter, and ownership is never client-selected.
- [ ] Commit the focused generic proof and any necessary predicate grouping with `git commit -m "fix(filterable): preserve caller predicates in custom filters"`; keep domain DTO validation in its owning package's commit.

### Task 3: Add tenant Settings values, immutable definitions, and captured cache identities

**Files:** Modify `packages/nvl/settings/src/SettingManager.php`, `packages/nvl/settings/src/Services/SettingCache.php`, `packages/nvl/settings/src/Observers/SettingCacheObserver.php`, `packages/nvl/settings/src/Support/DefinitionRepository.php`, `packages/nvl/settings/src/Providers/SettingsServiceProvider.php`; create `packages/nvl/settings/src/Services/SettingCacheIdentity.php`, `packages/nvl/settings/src/Tenancy/SettingsResourceRegistrar.php`, `packages/nvl/settings/src/Tenancy/SettingsAdoptionAdapter.php`, `packages/nvl/settings/database/tenancy-migrations/2026_09_16_180001_add_settings_ownership.php`, `packages/nvl/settings/tests/Tenancy/TenantSettingsTest.php`.

**Interfaces:** Existing `SettingRepository::{get,set,setMany,forget,has}` signatures remain. `SettingCacheIdentity` is an immutable value with `?string $store` and `string $key`; `SettingCache::identity(): SettingCacheIdentity`, `flushIdentity(SettingCacheIdentity $identity): void`, and `identityFor(Setting $setting): SettingCacheIdentity` are internal methods. Register `settings.values` with `allowsPlatformRows=true` and no shared-catalog grant; immutable source definitions are a fixed platform catalog. Extend source definitions with explicit `tenant_override: bool` defaulting to false. A config-mapped definition cannot also permit tenant overrides.

- [ ] Add tests through `SettingRepository`: tenant A sets `interface.theme=dark`; tenant B reads code default `light`; A resets and returns to `light`. Test null override versus missing override; same key in A/B; one-query bulk reads; stale definitions; orphan definitions; scheduled effectiveness; unauthorized input; missing context; platform override cannot leak into tenant default. Use the existing `tests/Fixtures/settings/interface.settings.php` source fixture and add its explicit `tenant_override` setting.

```php
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;

test('tenant reset returns to the shared code default', function (): void {
    $runner = app(TenantRunner::class);
    $a = new TenantId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $b = new TenantId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
    $runner->run($a, function (): void {
        app(SettingRepository::class)->set('interface.theme', 'dark');
    });
    expect($runner->run($b, fn () => app(SettingRepository::class)->get('interface.theme')))->toBe('light');
    $runner->run($a, fn () => app(SettingRepository::class)->forget('interface.theme'));
    expect($runner->run($a, fn () => app(SettingRepository::class)->get('interface.theme')))->toBe('light');
});
```

- [ ] Run `TenantSettingsTest.php`. Expected red: current global identity/cache makes B see A or rejects the duplicate key.
- [ ] Add ownership columns using the mixed platform/tenant discriminator from the execution contract, unique `(ownership_key, namespace, scope, key)`, tenant-leading indexes, and immutable ownership guards. Preserve `scope` as definition scope, not a tenant identifier. Derive source-definition hash at resolution; tenant values may override only explicitly permitted definitions. Synchronization runs in explicit platform mode and validates existing tenant overrides through bounded tenant runs without changing them implicitly.

Capture cache identity before registering callbacks; observers derive it from the persisted row. Include effective connection, ownership, and definition version/hash in identity. Never cache hydrated settings/models/tenant results in the singleton DefinitionRepository.

```php
$identity = $this->identity();
$connection->afterCommit(fn () => $this->flushIdentity($identity));
```

`SettingChanged` captures scalar ownership along with its existing value-free subject. Its consumers must use that captured ownership; no setting value goes into events/audit. Add serialized-cache A→B→unresolved tests, rollback tests, and a real outer-commit test whose callback runs after the caller's context lifetime; verify A's key is invalidated and B's is untouched.

- [ ] Run targeted Settings tests, the existing cache/override suite, and the PostgreSQL migration/locking fixture. Expected green includes actual database indexes, no ambient-context invalidation, and no query-budget regression beyond the separately measured foundation probe.
- [ ] Commit `feat(settings): isolate tenant values and cache invalidation` with registrar, adoption, docs and tests together.

### Task 4: Keep source translation tools platform-only and provide bounded tenant copy overrides

**Files:** Modify `packages/nvl/translations/src/Providers/TranslationsServiceProvider.php`, `packages/nvl/translations/src/Actions/Entries/ListTranslationEntriesAction.php`, `packages/nvl/translations/src/Actions/Entries/GetTranslationCatalogStatisticsAction.php`, `packages/nvl/translations/src/Actions/Entries/UpdateTranslationEntryAction.php`, `packages/nvl/translations/src/Actions/Sync/ImportTranslationsAction.php`, `packages/nvl/translations/src/Actions/Sync/ExportTranslationsAction.php`, `packages/nvl/translations/src/Actions/Sync/ScanTranslationsAction.php`; create the three overlay classes in the file map, `packages/nvl/translations/src/Actions/ExportTenantTranslationsAction.php`, `packages/nvl/translations/database/tenancy-migrations/2026_09_16_180002_create_tenant_translation_overrides.php`, `packages/nvl/translations/tests/Feature/TenantTranslationBoundaryTest.php`.

**Interfaces:** Register fixed platform source resources `translations.catalog`, `translations.usages`, `translations.scans` and tenant resource `translations.overrides`. New `TenantTranslationRepository` declares `get(string $key, string $locale): ?string`, `set(string $key, string $locale, string $value, int $expectedRevision): void`, and `forget(string $key, string $locale, int $expectedRevision): void`. Config `translations.tenant_overrides.keys` is a literal allowlist of source keys; no source paths. `ExportTenantTranslationsAction::execute(string $disk): TenantTranslationExportData` returns the newly defined immutable DTO `{disk:string,path:string,sha256:string,bytes:int}` from `src/Data/TenantTranslationExportData.php`.

- [ ] Write failing tests that call the real source `ListTranslationEntriesAction`, statistics, update, import, export, scan, and prune Actions in Tenant and Unresolved modes. Every source operation is denied before source SQL or filesystem access. Explicit platform invocation retains existing behavior. Add overlay tests: same key/locale differs between A/B; nonallowlisted key denied; source-file checksums unchanged; expectedRevision conflict; fallback returns null for missing tenant override rather than reading B.

```php
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Translations\Actions\Entries\ListTranslationEntriesAction;

test('tenant cannot browse the source synchronization workspace', function (): void {
    expect(fn () => app(ListTranslationEntriesAction::class)->execute())
        ->toThrow(TenantBoundaryViolation::class);
});
```

This case runs under the active-A fixture, and its platform/disabled dataset runs under separately booted fixtures. Do not rely solely on HTTP `TranslationsAuthorization`: direct public Actions are covered.

- [ ] Run the focused translation boundary test. Expected red: the current source Action returns catalog rows in tenant context.
- [ ] Add a narrow source-workspace admission service invoked by every source entry point; use registered fixed platform classification and existing authorization. Keep `TranslationScopeResolver`, `TranslationIdentity`, language files, scanner usages, and scan runs semantically unchanged. Do not add tenant identity to filesystem scope tokens.

Create a distinct override table with UUID, non-null tenant ID, allowlisted key, locale, value, revision, timestamps, and unique `(tenant_id,key,locale)`. Resolution order is explicit caller overlay → platform code translator; no implicit Laravel global translator mutation. The repository returns only the active tenant value or null. Exports write a private artifact under `tenants/<uuid>/translations/<uuid>.json`; caller authorizes download, and the result records the actual disk/path. Source export and tenant artifact export never share their command/route or overwrite path.

- [ ] Run translation source roundtrip tests, the new overlay tests, and an A→B→unresolved worker sequence. Expected green: source catalogs/files remain platform-owned; tenant artifacts and overlays remain isolated.
- [ ] Commit `feat(translations): separate tenant copy overrides from source catalogs` with explicit docs, schema registration and generated DTO types.

### Task 5: Make CSV queued imports explicit, serializable tenant work

**Files:** Modify `packages/nvl/csv/src/Services/CSVAsyncProcessor.php`, `packages/nvl/csv/src/Jobs/ProcessCSVChunkJob.php`, `packages/nvl/csv/composer.json`; create `packages/nvl/csv/src/Contracts/CSVRowHandler.php`, `packages/nvl/csv/src/Services/CSVHandlerRegistry.php`, `packages/nvl/csv/src/Services/CSVWorkStore.php`, `packages/nvl/csv/src/ValueObjects/CSVWorkReference.php`, `packages/nvl/csv/tests/Tenancy/TenantCsvBoundaryTest.php`, `packages/nvl/csv/tests/Tenancy/TenantCsvWorkerTest.php`.

**Interfaces:** `CSVRowHandler::process(array $row, int $rowNumber): void` with `array<string,mixed>` PHPDoc is a class-resolved domain write adapter. `CSVHandlerRegistry::register(string $alias, string $handlerClass): void` and `resolve(string $alias): CSVRowHandler` retain class names only. `CSVWorkReference` carries readonly string `workId`, `tenantId`, `disk`, `manifestPath`, `handlerAlias`, and int `handlerVersion`. `CSVWorkStore::write(CSVWorkReference $work, array $manifest): void`, `read(CSVWorkReference $work): array`, and `delete(CSVWorkReference $work): void` use bounded `array<string,mixed>` JSON-only manifests and verify tenant/work checksum. Define these under the named paths before using them. Add `CSVAsyncProcessor::usingHandler(string $alias): self`; tenant async mode requires a registered handler, while disabled callers retain the closure API. Preserve zero-argument construction and `make()` for disabled consumers by adding optional constructor dependencies `?TenantContext $context=null`, `?CSVWorkStore $workStore=null`, `?CSVHandlerRegistry $handlers=null`, `?TenantQueueContext $queueContext=null`. The provider binds transient fully injected processors; enabled processing requires all dependencies and rejects a legacy `make()` processor before staging. Closure setters reject when tenancy is enabled and `processAsync()` rechecks all accumulated callbacks/transforms. No service lookup is added inside the processor.

- [ ] Write failing tests against `CSVAsyncProcessor` and real serialized `ProcessCSVChunkJob`. A tenant invocation using a row/batch/completion closure is rejected before staging or dispatch; a registered scalar handler writes under its captured tenant after a real queue roundtrip. A forged manifest path belonging to B fails before reading/deleting B's file. Job cancellation and `failed()` clean only the exact persisted work reference.

```php
use Nvl\Csv\Services\CSVAsyncProcessor;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

test('tenant csv refuses arbitrary serialized callbacks', function (): void {
    $processor = app(CSVAsyncProcessor::class);
    expect(fn () => $processor->processRow(static fn (array $row): null => null))
        ->toThrow(TenantBoundaryViolation::class);
});
```

The existing setter is `processRow(Closure): self`. Apply the same tenant-mode admission to `onProgress`, `onBatchComplete`, `onComplete`, and closure-backed field transformations. Disabled compatibility retains current callback serialization.

- [ ] Run `TenantCsvBoundaryTest.php` and existing `CsvAsyncProcessingTest.php`. Expected red: the existing implementation accepts tenant closures without context or work ownership.
- [ ] Add the Tenancy dependency and use foundation queue capture before serialization. Persist a JSON-only manifest on the configured disk, including tenant/work/chunk identity and checksums, then dispatch scalar references. Establish context before deserialization; reload/verify the manifest before callback/handler lookup. Restore it for failed/retry/batch completion paths. `CSVHandlerRegistry` resolves per operation after context, never retains handler instances.

```text
authorize source + current tenant
validate handler/version + reject closures/models/resources in tenant payload
stage chunks under tenants/<tenant>/csv/<work>/<chunk>.json
persist manifest with checksums and exact disk/path
dispatch scalar jobs with foundation context metadata
worker restores context -> verifies manifest -> resolves handler -> calls domain Action
cleanup verifies exact work ownership on success, failure, cancellation and retry
```

Each row processor still calls its domain package's public Action; CSV never imports arbitrary rows directly into package tables. Partition import idempotency at the host Action/work boundary. Synchronous CSV processing consumes the caller's current context without adding a tenant data model. Export queries must be supplied already authorized; exports record tenant and actual storage path, and their host download endpoint verifies ownership.

- [ ] Run a real worker A→B→missing sequence including handler exception, retry, cancellation, batch completion, and rejected model-capturing closure. Use the existing root queue/Redis/storage integration fixture; assert no raw row data or private paths appear in another tenant's batch metadata/logs. Expected green includes clean residual context and exact cleanup.
- [ ] Commit `feat(csv): bind queued work and artifacts to tenant context` with disabled regression proof.

### Task 6: Adoption, diagnostics, distribution, and release gate

**Files:** Each participating package's provider, `composer.json`, `README.md`, `UPGRADING.md`, `CHANGELOG.md`, canonical `resources/boost/skills/nvl-*/SKILL.md`; `tools/package-contracts.json`; existing suite Doctor/configuration checks and consumer audit fixtures. Create package-owned Settings/Translations adoption adapters and tenant migration tests; do not let the suite write their tables.

**Interfaces:** Use foundation adoption `prepare/backfill/verify/activate` lifecycle. Register platform-only source catalogs explicitly; inherit tenant ownership for Settings events and translation export artifacts. Settings maps each legacy row to platform or a reviewed tenant; source Translations rows remain platform; no silent bulk assignment to a first tenant.

- [ ] Add failing fresh-install, disabled-install, and existing-data rehearsals. Prepared marker denies ordinary reads; feature-disabled adopted storage is rejected; absent Tenancy provider cannot reopen it; repeated backfill checkpoint is idempotent; changed mapping fingerprint is rejected; no newly optional migration was previously recorded as a conditional no-op.
- [ ] Run only those new package adoption tests; expected red is missing ownership/registration rather than unavailable services.
- [ ] Implement adapters and Doctor evidence for definitions disallowing tenant override, conflicting config mappings, unknown source keys, unusable CSV handlers, unsafe closure use, wrong connections, and missing cache/store capabilities. Backfill in bounded resumable batches, maintenance-window writes paused, old queues drained/versioned. Do not offer flag-disable as rollback after duplicate keys exist.
- [ ] Run affected package regressions and `vendor/bin/pint --dirty --format agent`; run package PHPStan and contract checks. Execute SQLite plus PostgreSQL and supported MySQL/MariaDB schema cases, real Redis invalidation/locks, real worker sequences, config/route cache, and isolated Composer/archive install. Disabled packages must require no tenant tables, with the bounded foundation marker probe measured separately.
- [ ] Review and commit `test(tenancy): verify settings and tool adoption contracts`. Only mark these features tenant-ready after the evidence passes.

## Acceptance summary

- Tasks 1–2 are required before the first tenant application proof; Tasks 3–6 do not delay that proof if their features remain explicitly unavailable in tenant operations.
- Tenant overrides cannot change SMTP, filesystems, database connections, auth, service bindings, global translation files, or process configuration.
- Generic Filterable/Data remain independently installable without Tenancy.
- CSV queued support is tenant-ready only after real serialization and batch/failure cleanup proof.
- Runtime implementation is intentionally outside this planning deliverable.
