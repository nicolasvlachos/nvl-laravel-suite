# Translatable Tenancy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve tenant ownership through both translation storage strategies before any tenant-enabled resource package uses translations.

**Architecture:** Domain packages own translation tables and ownership declarations. Translatable applies the domain resource boundary to canonical owners, carries that boundary through locale queries and child writes, and validates retained models before returning loaded translations. A translation does not acquire an independent tenancy mode or sharing grant.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4, existing Testbench, SQLite and PostgreSQL, supported MySQL/MariaDB release matrix.

**Spec:** [Execution contracts](../specs/2026-09-16-tenancy-execution-contracts.md), [suite design](../specs/2026-09-16-configurable-tenancy-design.md).

## Global Constraints

- PHP 8.4 and Laravel 13 are the execution baseline.
- No new external Composer dependency is required.
- All tenancy types and runtime logic live in `Nvl\Tenancy`, including the disabled implementation. Support remains free of tenant domain logic.
- Existing public Actions retain their normal signatures where possible. Context is injected; tenant IDs are not mass-assignable client DTO fields.
- Each package owns its new schema, query predicates, grants, adoption adapter, audit facts, and lifecycle. Tenancy never writes another package's tables.
- Missing context in enabled mode fails closed. Platform access, central identity use cases, and tenant operations are explicit and separate.
- Released migrations are immutable. Optional migration sets use distinct paths and explicit registration, never an `up()` that silently skips then records itself as applied.
- This is a planning artifact. Do not implement or run this plan during the architecture-review task.

## Prerequisites and delivery boundary

Execute after the foundation plan supplies `TenantContext`, `TenantBoundary`, `TenantRunner`, directory/access adapters, resource registration, adoption-state validation, and queue lifecycle capture. Execute before the resource plan. Auth is not a prerequisite: standalone Translatable must work with the inert Tenancy library and a host directory adapter.

The package has **no production migrations**. Do not introduce a generic translations table. The resource plan supplies Media, Metafields, and Taxonomy translation-table migrations. Host schemas are diagnosed, never generated from translation definitions.

The existing defect to reproduce is `SelfTranslatable::scopeLocale()`: its preferred-locale subquery calls `withoutGlobalScopesExcept([SoftDeletingScope::class])`. With equal group keys, another tenant's preferred locale can suppress the active tenant's fallback. `TranslationResourceLocator::loadTranslations()` and Gatherer subqueries also partition only by the group key.

## File map and interfaces

| Change | Exact paths | Responsibility |
|---|---|---|
| Modify | `packages/nvl/translatable/composer.json`; `src/Support/SuiteModuleCatalog.php`; `tools/package-contracts.json` | Require the inert Tenancy library; report dependency/provider closure without activating it |
| Modify | `packages/nvl/translatable/src/TranslationDefinition.php`; `RelatedTranslationDefinition.php`; `SelfTranslationDefinition.php`; `TranslatableOptions.php`; `SelfTranslatableOptions.php` | Explicit domain ownership declaration, preserved by legacy definition adapters |
| Create | `packages/nvl/translatable/src/Services/TranslationOwnership.php` | Connect translation operations to a domain-owned resource boundary |
| Modify | `packages/nvl/translatable/src/Translatable.php`; `SelfTranslatable.php` | Model-local reads, relations, locale scopes, identity immutability, writes and restore |
| Modify | `packages/nvl/translatable/src/Services/RelatedTranslationStore.php`; `SelfTranslationStore.php`; `TranslationWriter.php` | Owner validation, tenant-preserving creation/locking/deletion |
| Modify | `packages/nvl/translatable/src/Services/TranslationResourceLocator.php`; `TranslationResourceGatherer.php`; `TranslationResourceVersioner.php`; `TranslationDoctor.php`; `Providers/TranslatableServiceProvider.php` | Central query partitions, scoped lifetimes, schema evidence |
| Create | `packages/nvl/translatable/tests/Support/TenantTranslationScenario.php`; `TenantSelfEntry.php`; `TenantArticle.php`; `TenantArticleTranslation.php` | Explicit fixtures described below |
| Create | `packages/nvl/translatable/tests/Tenancy/Feature/TranslationTenancyTest.php`; `TranslationTenancySchemaTest.php`; `TranslationTenancyCatalogTest.php` | Portable ownership and central catalog proof |
| Create | `packages/nvl/translatable/tests/Tenancy/Integration/TranslationTenancyWorkerTest.php`; `TranslationTenancyConcurrencyTest.php` | Process-boundary and concurrent same-group creation proof |
| Modify | `packages/nvl/translatable/tests/TestCase.php`; `README.md`; `UPGRADING.md`; `CHANGELOG.md`; `resources/boost/skills/nvl-translatable/SKILL.md` within the package | Provider fixture and public contract documentation |

Paths in a row that omit the repeated package prefix are relative to the first path's package directory. No implementation step may create a new suite-wide testing framework.

Append `?string $ownershipResource = null` to the constructors of `TranslationDefinition`, `RelatedTranslationDefinition`, and `SelfTranslationDefinition`; preserve it in both options adapters. It is a code-owned resource key such as `media.assets`, not a configuration switch or request field. Existing disabled declarations remain valid. In enabled mode an undeclared owner fails with `TenantConfigurationInvalid`; platform owners also require an explicit registered key.

New service API:

```php
final readonly class TranslationOwnership
{
    public function __construct(
        private TenantBoundary $boundary,
        private TenantContext $context,
        private TenantResourceRegistry $resources,
        private TenantInstallationState $installation,
        private TenantOwnershipConfiguration $configuration,
    ) {}

    public function query(Builder $query, TranslationDefinition $definition): Builder;
    public function assertOwner(Model $owner, TranslationDefinition $definition): void;
    public function lockOwner(Model $owner, TranslationDefinition $definition): Model;
    /** @return array{tenant_id?: string|null, ownership_key?: string} */
    public function childAttributes(Model $owner, TranslationDefinition $definition): array;
    /** @return list<string> */
    public function partitionColumns(TranslationDefinition $definition): array;
    public function partitionKey(Model $owner, TranslationDefinition $definition): string;
}
```

`partitionColumns()` describes metadata; it does not admit undeclared storage. It may return `[]` for a disabled, undeclared definition without proving that storage is unadopted. Every row or SQL entry point must first establish admission through `assertOwner()` or `query()` on the actual owner/query connection. Disabled undeclared access uses the injected `TenantInstallationState::assertUnadopted()` on that exact `Connection`; it must not substitute the default/core connection or reuse another owner's admission. Declared metadata resolves the registered canonical storage and calls `assertUsable()` before returning `[]` for disabled, unadopted resources.

For enabled declarations, `partitionColumns()` returns `['ownership_key']` for mixed platform/tenant schemas or `['tenant_id']` for tenant-only schemas. Resolve `TenantResourceRegistry::get($definition->ownershipResource)` and follow inherited parent declarations to their roots, using the injected `TenantOwnershipConfiguration` for validated polymorphic parent allowlists and structural configuration. All allowed roots must select one deterministic partition schema; reject heterogeneous schemas with `TenantConfigurationInvalid`. The roots' validated `allowsPlatformCatalog`/`allowsPlatformRows` descriptors and adopted configuration select the schema; never infer it from a nullable model attribute. Owner-bearing `childAttributes()` and `partitionKey()` resolve persisted canonical parent identity under the registered allowlist. Carry the selected partition through SQL and relation matching. `partitionKey()` encodes the connection, table, ownership partition, and logical resource key with JSON plus SHA-256; it never joins unescaped strings with a delimiter.

`lockOwner()` reloads through `query()` and the model's persisted primary key using `lockForUpdate()`. Require an open transaction on that effective connection for enabled writes. It rejects dirty ownership attributes and verifies the persisted owner, including self-row group identity; it never uses a forged in-memory `tenant_id` as authority. Only locale-varying fields enter translation payloads. `tenant_id` and `ownership_key` are always structural and cannot be translated or copied as arbitrary `sharedFields`.

## Task 1: Add explicit ownership declarations and executable fixtures

**Files:** declaration/options/provider/composer files and the four new Support fixtures above; `TranslationTenancyTest.php`; `tests/TestCase.php`.

**Consumes:** frozen Tenancy interfaces and its resource registry. **Produces:** `TranslationOwnership` and the constructor argument above.

- [ ] Add the package dependency and provider ordering. The fixture registers Tenancy before Translatable; production Composer discovery provides the same order. Do not change existing test classes to tenant mode globally.
- [ ] Create these fixture models with strict types, documented persisted properties, `HasUuids`, explicit `$table`, `$fillable`, and typed definitions. `TenantSelfEntry`: table `tenant_test_entries`, fields `id`, non-null `tenant_id`, `entry_key`, `locale`, nullable `name`, timestamps, soft deletes; `SelfTranslationDefinition(groupKey: 'entry_key', fields: ['name'], ownershipResource: 'test.entries')`. `TenantArticle`: table `tenant_test_articles`, `id`, non-null `tenant_id`, `slug`, timestamps; related definition for `TenantArticleTranslation`, `article_id`, `name`, ownership resource `test.articles`. Translation table: `id`, `tenant_id`, `article_id`, locale length 35, nullable `name`, timestamps. Neither model permits mass-assigned ownership.
- [ ] Define `TenantTranslationScenario` with the following complete test-facing surface:

```php
final class TenantTranslationScenario
{
    public const string A = '00000000-0000-4000-8000-00000000000a';
    public const string B = '00000000-0000-4000-8000-00000000000b';

    public static function install(): self;
    public function run(string $id, Closure $callback): mixed;
    public function entry(string $tenant, string $group, string $locale, string $name): TenantSelfEntry;
    public function article(string $tenant, string $slug, array $translations): TenantArticle;
}
```

`install()` enables tenancy for this test application and binds an anonymous `TenantDirectory` whose `find(TenantId $id): TenantDescriptor` accepts exactly A/B as Active and throws `TenantNotFound` otherwise. Register the models using the exact code below. Create `tests/Support/TenantTranslationFixtureAdoptionAdapter.php`: this test-only **owner** adapter implements `TenantAdoptionAdapter`, returns those three keys from `resources()`, creates the three explicit tables/constraints in `prepare()`, returns `new TenantBackfillResult(null, 0)` from `backfill()` after asserting the tables were initially empty, verifies column/index/FK contracts in `verify()`, and performs no additional DDL in `activate()`. It owns only fixture tables; production Translatable gets no adoption adapter. The coordinator owns marker writes. Register both roots in `TranslationResourceRegistry`. Do not mock `TenantBoundary` or mark prepared data as readable.

```php
$resources = app(TenantResourceRegistry::class);
$resources->register(new TenantResourceDefinition('test.entries', 'test', TenantSelfEntry::class));
$resources->register(new TenantResourceDefinition('test.articles', 'test', TenantArticle::class));
$resources->register(new TenantResourceDefinition(
    'test.article-translations', 'test', TenantArticleTranslation::class,
    TenantResourceKind::Inherited, 'test.articles', 'article',
));
app(TenantAdoptionRegistry::class)->register('translation-fixtures', TenantTranslationFixtureAdoptionAdapter::class);
$operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
$coordinator = app(TenantAdoptionCoordinator::class);
$plan = $coordinator->prepare(['translation-fixtures'], [], $operation);
$done = false;
for ($batch = 0; $batch < 20 && ! $done; $batch++) {
    $done = $coordinator->backfill($plan, 100, $operation);
}
expect($done)->toBeTrue();
expect($coordinator->verify($plan)->passed())->toBeTrue();
$coordinator->activate($plan, $operation);
app(MaintenanceMode::class)->deactivate();
```

Add `TenantArticleTranslation::article(): BelongsTo`. A dedicated `tests/TenancyTestCase.php` extends the existing Translatable TestCase (which has no outer transaction), uses `DatabaseMigrations`, loads the core opt-in migration file `packages/nvl/tenancy/database/migrations/tenancy/2026_09_16_000001_create_tenancy_core_tables.php`, and binds a test PlatformAccess allowing only purpose `fixture.adoption`, type `test`, ID `fixture`. Its in-memory `MaintenanceMode` implements `activate(array $payload): void`, `deactivate(): void`, `active(): bool`, `data(): array`, starting active and retaining the supplied payload. Activate invalidates the current process probe cache, so no SQLite in-memory application reboot is needed; the separate consumer rehearsal proves restart behavior. In `tests/Pest.php`, replace the all-directory binding with `uses(TestCase::class)->in('Unit', 'Feature')` and `uses(TenancyTestCase::class)->in('Tenancy')`. New tenancy files live under that separate directory, preventing duplicate case bindings; existing tests remain unchanged.

`run()` delegates to `app(TenantRunner::class)->run(new TenantId($id), $callback)`. `entry()` calls `run()`, creates a model with nonownership fields, applies `TenantBoundary::attributes('test.entries')` server-side, saves, and returns it. `article()` does the equivalent for `test.articles`, then seeds each fixture translation with `TenantArticleTranslation::forceCreate(['tenant_id'=>$tenant,'article_id'=>$article->getKey(),'locale'=>$locale,'name'=>$attributes['name']])` inside the same transaction. This is explicit fixture persistence for a pre-existing record; Task 1's read test must not depend on Task 3's not-yet-implemented Writer ownership propagation. Mutation tests call the real Writer themselves. These are the only scenario helper methods used below; never replace them with ambient context mutation.

- [ ] Write the first failing test (imports are the named classes above and `TenantBoundaryViolation`):

```php
it('rejects a retained owner even when its translations were loaded in another tenant', function (): void {
    $s = TenantTranslationScenario::install();
    $article = $s->article($s::A, 'same', ['en' => ['name' => 'A secret']]);
    $s->run($s::A, fn () => $article->load('translations'));

    expect(fn () => $s->run($s::B, fn () => $article->translated('name', 'en')))
        ->toThrow(TenantBoundaryViolation::class);
});
```

- [ ] Run the exact failing command from the suite root:

```bash
vendor/bin/pest --test-directory=packages/nvl/translatable/tests --configuration=packages/nvl/translatable/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/translatable/tests/Tenancy/Feature/TranslationTenancyTest.php
```

Expected red: missing declaration/service first, then the retained-owner assertion fails until the boundary is installed. Do not count fixture syntax or DB setup failure as the final reproduced failure.
- [ ] Implement the boundary routing without altering disabled behavior:

```php
public function assertOwner(Model $owner, TranslationDefinition $definition): void
{
    if ($definition->ownershipResource === null) {
        if ($this->context->snapshot()->mode !== TenantContextMode::Disabled) {
            throw new TenantConfigurationInvalid('Translation ownership is not declared.');
        }

        return;
    }

    $this->boundary->assertRecord($owner, $definition->ownershipResource);
}
```

The adopted-resource guard still runs for the connection before this disabled early return. A missing declaration cannot bypass a marker. `query()` follows the same declaration check then delegates `TenantBoundary::query`. `childAttributes()` first validates the owner and reads the canonical persisted partition from it, rather than using the ambient tenant for a foreign owner.
- [ ] Bind `TranslationOwnership`, both Stores, Writer, Locator, Gatherer, Versioner, and any authorizer capturing their dependencies as scoped. Keep only immutable declaration/locale catalogs singleton. Existing traits already obtain translation runtime services; extend that existing seam, and do not retain a service instance in model static properties.
- [ ] Run the same command; expected green. Run the existing disabled `ProviderConfigurationTest.php` and `TranslatableConsumerContractsTest.php` with the same runner prefix. Commit only this task's declaration, boundary and fixture files: `feat(translatable): declare domain ownership boundaries`.

## Task 2: Partition locale selection and every read path

**Files:** `SelfTranslatable.php`, `Translatable.php`, `RelatedTranslationStore.php`, `SelfTranslationStore.php`, `TranslationTenancyTest.php`.

**Consumes:** Task 1's ownership service. **Produces:** locale queries and model-local reads that cannot mix equal group keys.

- [ ] Add this failing case:

```php
it('chooses A fallback even when B has the requested locale for the same group', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'shared-handle', 'en', 'A fallback');
    $s->entry($s::B, 'shared-handle', 'bg', 'B requested');

    $names = $s->run($s::A, fn () => TenantSelfEntry::query()->locale('bg')->pluck('name')->all());

    expect($names)->toBe(['A fallback']);
});
```

- [ ] Run Task 1's exact file command. Expected red: empty selection or B contamination when preference drops ownership.
- [ ] In `scopeLocale()`, apply ownership to the outer builder before cloning. Retain its scopes; remove only ordering/limit/offset from the cloned preference candidate query. Do not remove all global scopes. Select every partition column plus group/locale and correlate every partition column as well as the group:

```php
$query = $ownership->query($query, $definition);
$partition = $ownership->partitionColumns($definition);
$columns = [...$partition, $definition->groupKey, $definition->localeKey];
$visibleRows = (clone $query)
    ->select(array_map(fn (string $column): string => "{$table}.{$column}", $columns))
    ->reorder()->toBase()->cloneWithout(['limit', 'offset']);

foreach ([...$partition, $definition->groupKey] as $column) {
    $subquery->whereColumn("{$alias}.{$column}", "{$table}.{$column}");
}
```

For a mixed catalog use non-null `ownership_key` so equality behaves correctly for platform rows. Existing caller visibility predicates remain inside `$visibleRows`; configured query scopes cannot remove ownership, replace the model/table/connection, or supply a wider builder after validation.
- [ ] Apply `assertOwner()` before all loaded-relation fast paths: `getTranslation`, `getAllTranslations`, `hasTranslation`, field resolution, `getAvailableLocales`, store `rows`, and self helper reads. Validate each loaded translation's partition and owner key before returning it. Reject a manually injected B translation collection on an A owner. Do not reload each translated field separately; one public collection operation validates the owner once and all child rows in memory.
- [ ] Add explicit partition predicates to related relationships and the `orderByTranslated()` subquery. Eager relation matching must use canonical owner IDs and ownership; a global UUID does not waive the child equality constraint. Apply the owner boundary before `whereTranslated()` OR grouping so caller filters cannot widen it.
- [ ] Extend the file's dataset with ExactOnly/Configured/AnyAvailable, null versus empty string, soft-deleted locale restore, caller visibility constraints, forged dirty tenant attribute, and missing context. Each uses the same A/B fixture; assert only A strings and the declared error. Run the file and existing `TranslatableSelfStrategyTest.php`, `TranslatableSoftDeletesTest.php`, and `TranslatableTest.php`. Commit: `fix(translatable): retain ownership in locale queries`.

### L2 execution clarifications

- Native Laravel eager loading builds a relation on an empty owner prototype. L2 therefore includes package-local `Relations/TranslationHasMany.php`, `TranslationHasOne.php`, and `GuardsTranslationRelation.php`; these preserve the public native relation types and use the injected RelatedTranslationStore for canonical reads. No generic cross-package Eloquent layer or retained admission cache is introduced.
- Related-model metadata inspection in TranslationResourceDefinition, TranslationDoctor, and Gatherer availability checks uses tightly bounded `Relation::noConstraints` construction/getRelated calls. These blocks contain no row query, count, retrieval, or mutation. Actual relation reads admit the real child Connection as well as the owner.
- Locale preference retains caller/global visibility scopes; a temporary model-local recursion guard prevents a locale-selecting global scope from recursively rebuilding its own candidate query. Loaded self groups are cleared before the existing mutation refresh reloads them.
- The existing real-adoption fixture can explicitly select mixed self-row schema for platform/tenant locale correlation proof. This is test schema only; production migrations remain absent.
- Configured resource `queryScope` callbacks execute in Locator::applyQueryScope and are L4 work. L4 must validate callback results against independently captured canonical model/table/Connection, reject wider or replaced builders, and apply ownership after the callback; L2 covers its own caller/global visibility and native relation predicates.
- Admitted-owner SQL satisfies native eager matching without a composite dictionary: every eager parent is canonically admitted in the one active ownership partition, child SQL correlates its foreign key and every declared partition column to that admitted owner set, and loaded/package reads revalidate the same identity. If a future access mode intentionally returns multiple partitions in one eager load, it must add a bounded operation-local composite matcher and targeted regression before shipping; no admission cache or duplicate matcher is added for the current single-partition contract.

## Task 3: Preserve owner identity during writes and restore

**Files:** Stores, Writer, `SelfTranslatable.php`, definitions, `TranslationTenancyTest.php`, `TranslationTenancySchemaTest.php`.

**Consumes:** `TranslationOwnership::lockOwner()` and server-derived child attributes. **Produces:** same-connection writes and tenant-local group locking.

- [ ] Add the failing write proof:

```php
it('creates a second locale only inside the canonical owner partition', function (): void {
    $s = TenantTranslationScenario::install();
    $a = $s->entry($s::A, 'same', 'en', 'A');
    $s->entry($s::B, 'same', 'bg', 'B');

    $s->run($s::A, fn () => $a->getConnection()->transaction(
        fn () => app(TranslationWriter::class)->patch($a, ['bg' => ['name' => 'A bg']]),
    ));

    expect($s->run($s::A, fn () => $a->getAllTranslations()->pluck('name', 'locale')->all()))
        ->toBe(['bg' => 'A bg', 'en' => 'A']);
    expect($s->run($s::B, fn () => TenantSelfEntry::query()->locale('bg')->value('name')))->toBe('B');
});
```

- [ ] Run `TranslationTenancyTest.php`; expected red before partitioned creation/locking.
- [ ] At the beginning of Writer mutations, require the existing owning transaction, lock/reload the canonical owner, and retain its immutable group identity. Self row queries include the ownership partition and group in lock/delete/count/restore/race-retry lookups. Assign creation data in this order:

```php
$identity = [
    ...$ownership->childAttributes($canonicalOwner, $definition),
    $definition->groupKey => $canonicalOwner->translationResourceKey(),
    $definition->localeKey => $locale,
];
$translation = $canonicalOwner->newInstance();
foreach ([...$shared, ...$attributes, ...$identity] as $column => $value) {
    $translation->setAttribute($column, $value);
}
$translation->saveOrFail();
```

Payload validation rejects ownership keys even if a consumer lists them as fields. Identity comes last as defense in depth. Related rows use the canonical owner FK plus identical ownership columns; replace/delete uses both constraints. Restore removes only `SoftDeletingScope`, retains ownership, and never revives B's same-group row. Keep final-row protection and optimistic versions inside the locked group.
- [ ] Add schema assertions and raw-insert rejection for these explicit fixture constraints:

```php
$table->unique(['tenant_id', 'entry_key', 'locale'], 'tenant_entries_group_locale_unique');
$table->unique(['tenant_id', 'id'], 'tenant_articles_tenant_id_unique');
$table->unique(['tenant_id', 'article_id', 'locale'], 'tenant_article_locale_unique');
$table->foreign(['tenant_id', 'article_id'])
    ->references(['tenant_id', 'id'])->on('tenant_test_articles')->cascadeOnDelete();
```

For self rows all updates through model events must reject changes to `tenant_id`, ownership key, group or locale identity, including quiet save wrappers exposed by the package. Bulk raw SQL remains a trusted-host boundary; supported package bulk methods preserve the predicate explicitly.
- [ ] Cover replace, clear/delete-final, concurrent absent-locale insert, duplicate retry after savepoint, central stale-version write, and non-default effective connection. Run the two new files and `TranslatableSoftDeletesTest.php`. Commit: `feat(translatable): constrain translation writes to canonical owners`.

## Task 4: Central resource search, coverage and diagnostics

**Files:** Locator, Gatherer, Versioner, Doctor, provider and `TranslationTenancyCatalogTest.php`.

**Consumes:** partition columns/key and the existing resource visibility/authorization contracts. **Produces:** tenant-local central catalog APIs without a new translation tenancy mode.

- [ ] Register fixture catalog entries `test.entries` and `test.articles` using the existing `TranslationResourceRegistry::register(key, modelClass, label, ...)`; the models' definitions supply ownership. Add this red case:

```php
it('does not use B locale rows for A central coverage or search', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'same', 'en', 'A only');
    $s->entry($s::B, 'same', 'bg', 'secret needle');

    $page = $s->run($s::A, fn () => app(TranslationResourceGatherer::class)->gather(
        'test.entries',
        TranslationActorData::system(),
        new TranslationResourceQuery(search: 'secret needle'),
    ));

    expect($page->total())->toBe(0);
});
```

`TranslationActorData::system(string $type = 'system')` is the existing factory. It only satisfies translation authorization and never bypasses `TenantBoundary`.
- [ ] Run the exact file command with the runner prefix in Task 1; expected red if the search candidate subquery can see B.
- [ ] Route every Locator entry through `TranslationOwnership::query`. In `loadTranslations`, reject a collection containing incompatible connections or ownership contexts, validate canonical owners once per batch, fetch rows partitioned by ownership and group, and group by `partitionKey()` rather than `groupKey` alone. SQL OR groups for multiple composite identities remain nested under the active boundary.
- [ ] Make Gatherer's self coverage/search/missing-locale subqueries select and correlate ownership plus group. Apply the same resource visibility scope to candidate rows, preserving the boundary afterward. A metadata report can include an explicitly registered platform catalog label, but it must not count its platform rows from tenant context. Reject unsupported mode access instead of silently scanning platform data.
- [ ] Versioner includes the ownership partition in its hashed canonical identity. A version computed for B's equal group cannot authorize A. Preserve `DomainActionOnly`; central write authorization does not permit generic mutation of Media or Metafield domain-managed resources.
- [ ] Doctor validates declared ownership key, registered model/connection, required tenant columns, parent/child composite FK, self ownership/group/locale unique constraint, immutable identity exclusions, and adopted marker compatibility. It reports consumer schema fixes with table/index names; it never writes migrations.
- [ ] Run catalog/schema tests plus existing `TranslationResourceRegistryTest.php`. Commit: `feat(translatable): isolate central translation reports`.

## Task 5: Worker, concurrency and distribution evidence

**Files:** two Integration tests above; package docs/skill; existing `.github/workflows/package-quality.yml` only where adding these focused gates to its existing jobs.

**Consumes:** foundation real-worker fixture; do not substitute direct `handle()` or Queue fakes. **Produces:** release evidence required by Media's first tenant proof.

- [ ] Define a test job `TenantTranslationProbeJob` in `packages/nvl/translatable/tests/Support/TenantTranslationProbeJob.php` with constructor `(string $recordId, string $locale, string $resultKey, TenantJobEnvelope $envelope, bool $throwAfterRead = false)`. It implements `ShouldQueue`, carries the typed envelope plus scalar fields, resolves `TenantSelfEntry` after queue context restoration, reads `translated('name', $locale)`, inserts a result record into the fixture's probe table, and optionally throws `RuntimeException('translation probe')`. Capture with `TenantJobEnvelope::capture(app(TenantContext::class))` at dispatch. Missing context fails at dispatch; a corrupt queued envelope fails before record lookup. Do not include a model in the job properties.
- [ ] Create the concrete consumer fixture described immediately below. Enqueue A/en, B/bg, failing A/en, then a deliberately corrupted-envelope probe into one uniquely named database queue with a file-backed disposable test DB. Corrupt only that final queued JSON's scalar envelope after valid serialization; do not bypass the production dispatch check in normal test code. Launch `['php', $fixture.'/artisan.php', 'queue:work', 'database', '--queue='.$queue, '--stop-when-empty', '--tries=1', '--timeout=30']` with a Process argument array. The child receives the same isolated DB/cache paths and fixture providers; capture exit/output and query probe results after exit. Assert A/B correct, failed job durable, corrupted job rejected before read, and no retained ContentLocale/current tenant after the sequence. No sleep-based ordering; enqueue in order and wait for worker exit with a 60-second process timeout.
- [ ] Define concurrent test actors as two independent PHP processes loading the same fixture app/database, waiting on a Redis barrier key, and each calling Writer to create A/bg for the same existing group. The parent releases the barrier after both readiness keys exist, then waits for both exits. Assert one locale row, no B change, no swallowed DB error, and a valid final version. Use `Process` argument arrays and a bounded deadline; no shell-string interpolation.
- [ ] Run the exact focused portable command first, then these exact integration test files with the same runner prefix against the repository's existing PostgreSQL/Redis fixture. Required CI enables these tests; local absence is an explicit skipped gate, never a pass. Run the schema file on the existing MySQL/MariaDB release matrix. Test output must record driver and process/queue evidence.
- [ ] Update package README/UPGRADING/CHANGELOG, canonical skill and suite mirror through the existing `composer skills:sync` workflow. Document mandatory owner declarations for enabled tenancy, schema examples for both strategies, disabled compatibility, loaded-model lifetime, transaction requirement, and the raw-SQL trust boundary.
- [ ] Run `vendor/bin/pint --dirty --format agent`, package PHPStan via the existing package-quality runner, all Translatable package tests, `composer packages:validate`, and `composer contracts:check`. Run no unrelated package suites unless their dependency declaration changed. Commit: `test(translatable): prove tenant boundaries across workers`.

### Concrete worker consumer fixture

Create these exact files during Task 5:

- `packages/nvl/translatable/tests/Fixtures/tenancy-consumer/artisan.php`
- `packages/nvl/translatable/tests/Fixtures/tenancy-consumer/config/app.php`
- `packages/nvl/translatable/tests/Fixtures/tenancy-consumer/config/database.php`
- `packages/nvl/translatable/tests/Fixtures/tenancy-consumer/config/queue.php`
- `packages/nvl/translatable/tests/Fixtures/tenancy-consumer/config/cache.php`
- `packages/nvl/translatable/tests/Fixtures/tenancy-consumer/config/tenancy.php`
- `packages/nvl/translatable/tests/Fixtures/TenancyConsumerServiceProvider.php`
- `packages/nvl/translatable/tests/Fixtures/TenancyConsumerSetupCommand.php`
- `packages/nvl/translatable/tests/Fixtures/TenancyConsumerRaceCommand.php`

`artisan.php` requires the root `vendor/autoload.php` via `dirname(__DIR__, 6)`, builds `Illuminate\Foundation\Application::configure(basePath: __DIR__)`, installs Support/Data/Tenancy/Translatable plus the fixture provider, creates the app, points its vendor path to the root vendor directory, and calls `$app->handleCommand(new Symfony\Component\Console\Input\ArgvInput)`. Tests copy the fixture to a unique temporary directory and pass `NVL_TEST_SUITE_ROOT` so the copied entrypoint locates root autoload explicitly. Create writable `bootstrap/cache`, `storage/framework/cache`, `storage/logs` in the copy. Config app key comes from the existing test key; DB defaults to a unique file-backed SQLite database or the dedicated CI connection; queues use database jobs/failed_jobs; cache uses the run-specific filesystem directory or Redis prefix. No user database or application config is read.

The fixture provider registers the four test adapters (directory/platform/maintenance/owner-adoption), three resources and both central translation resources on every process boot, but performs no migration or adoption automatically. `tenancy-fixture:setup` explicitly runs core migrations, creates Laravel `jobs`/`failed_jobs` tables and `tenant_probe_results(result_key primary string, tenant_id UUID nullable, value text nullable)` with a `Database Schema Blueprint`, then invokes the adoption sequence in Task 1 and seeds A/B through the scenario methods. It exits nonzero on any verification error. `tenancy-fixture:race {tenant} {group} {locale} {value} {barrier}` waits for a Redis barrier using bounded polling within the process (10-second deadline), then calls Writer in a canonical owner transaction and exits. Two parent-launched processes supply identical tenant/group/locale and different names; neither process calls an unavailable helper.

Exact worker invocation from the repository fixture, with dedicated test env already supplied:

```bash
php packages/nvl/translatable/tests/Fixtures/tenancy-consumer/artisan.php tenancy-fixture:setup
php packages/nvl/translatable/tests/Fixtures/tenancy-consumer/artisan.php queue:work database --queue=translation-tenant-proof --stop-when-empty --tries=1 --timeout=30
```

The automated integration test launches its copied entrypoint instead, injects its unique queue/paths, and cleans them in `finally`. It asserts that setup and worker processes actually ran; an absent child artifact is a failure, not a skipped assertion.

## Completion gate

- [ ] Disabled legacy examples work with no tenant tables, apart from the separately measured bounded adoption-marker compatibility probe.
- [ ] Missing declarations/context, forged ownership, loaded foreign relations and mode changes all fail closed.
- [ ] Both strategies preserve locale semantics and existing query-count budgets after accounting separately for the canonical boundary check.
- [ ] SQLite/schema matrix, actual queue worker and separate-process race gates pass.
- [ ] Media/Metafields/Taxonomy can consume the new declaration without independent translation configuration or a generic ownership pivot.
- [ ] Every command/result and any skipped infrastructure gate is recorded in the implementation review; this plan itself makes no runtime verification claim.
