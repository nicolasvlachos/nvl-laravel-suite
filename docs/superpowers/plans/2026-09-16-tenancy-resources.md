# Media, Metafields and Taxonomy Tenancy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver tenant-owned Media libraries, Metafield definitions/values, and Taxonomy trees with explicit platform catalog imports and complete owner composition checks.

**Architecture:** Roots carry direct tenant ownership; translations and domain associations derive it from canonical parents. Package-specific grant records permit inspection and copy-on-import of platform Media or Metafield definitions, and never make the source a shared tenant object. Each package guards its Actions, traits, queries, files, queues, and maintenance commands using the foundation's typed boundary.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4/Testbench, the existing PostgreSQL/Redis/S3-compatible fixtures, SQLite plus the supported MySQL/MariaDB matrix.

**Spec:** [Execution contracts](../specs/2026-09-16-tenancy-execution-contracts.md), [suite design](../specs/2026-09-16-configurable-tenancy-design.md), prerequisite [Translatable plan](2026-09-16-tenancy-translatable.md).

## Global Constraints

- PHP 8.4 and Laravel 13 are the execution baseline.
- No new external Composer dependency is required.
- All tenancy types and runtime logic live in `Nvl\Tenancy`, including the disabled implementation. Support remains free of tenant domain logic.
- Independent package installation remains supported. Installing Media brings the Tenancy library as a dependency but never requires NVL Auth.
- Existing public Actions retain their normal signatures where possible. Context is injected; tenant IDs are not mass-assignable client DTO fields.
- Each package owns its new schema, query predicates, grants, adoption adapter, audit facts, and lifecycle. Tenancy never writes another package's tables.
- Missing context in enabled mode fails closed. Platform access, central identity use cases, and tenant operations are explicit and separate.
- Released migrations are immutable. Optional migration sets use distinct paths and explicit registration, never an `up()` that silently skips then records itself as applied.
- This is a planning deliverable; implementation, tests, dependency changes and commits are separate execution work.

## Execution order and non-negotiable decisions

1. Foundation and Translatable pass first. Media's isolated package proof does not need Auth; the suite's shared-user/two-membership journey additionally consumes the Auth plan.
2. Complete Tasks 1–5 for Media before tenant-enabling it. Complete Tasks 6–7 for Metafields and Task 8 for Taxonomy before enabling those families. Task 9 proves their composition before downstream Content/Pages/SEO activation.
3. `tenancy.resources` selects **family** `tenant` or `platform`; derived children are not independently configurable. Media and Metafield definitions can additionally contain explicitly declared platform catalog roots when `tenancy.sharing.<family>='copy'`. Their schema uses `tenant_id` plus a constrained non-null `ownership_key`. A tenant-only family uses non-null `tenant_id` and does not need the extra key.
4. `media_associations` relates an asset to a model; `metafield_definition_assignments` applies a definition to an owner **type**; `termables` attaches a term to a model. These are not resource-to-tenant ownership tables. No `tenant_resources`, `term_tenant`, or translation tenant pivot is introduced.
5. Catalog imports create new UUIDs, tenant-owned rows, independent Media objects, and immutable provenance values. Source deletion has no cascade to imported copies. Revocation denies subsequent inspection/import, including retries and staged imports whose final authorization has not committed; it does not change already committed copies.
6. Grant/import/revocation use a single lock order: recipient grant first, source root second, target idempotency claim third. The final transaction is the linearization point. If import commits while holding the grant lock before a concurrent revocation acquires it, the copy exists legitimately; if revocation wins, import aborts and deletes its staged object. Do not promise the revocation can undo a transaction that already committed.
7. No live platform definition/term references enter tenant values/attachments. Taxonomy vocabulary aliases and structural declarations remain immutable global code configuration; their terms/trees are tenant data.

## File inventory and schema contract

All paths below are relative to the repository root. In per-package tables, `M`, `F`, and `T` expand to `packages/nvl/media`, `packages/nvl/metafields`, and `packages/nvl/taxonomy` respectively; executors must use the expanded paths in commits.

### Planned new schema files

| Package | Create | Purpose |
|---|---|---|
| M | `database/tenancy/2026_09_16_100001_expand_media_tenant_ownership.php`; `2026_09_16_100002_create_media_tenant_grants.php`; `2026_09_16_100003_constrain_media_tenant_ownership.php` | Expand roots/children, grant/import ledger, then verified constraints |
| F | `database/tenancy/2026_09_16_110001_expand_metafield_tenant_ownership.php`; `2026_09_16_110002_create_metafield_definition_tenant_grants.php`; `2026_09_16_110003_constrain_metafield_tenant_ownership.php` | Definition/value graph and concrete grants |
| T | `database/tenancy/2026_09_16_120001_expand_taxonomy_tenant_ownership.php`; `2026_09_16_120002_constrain_taxonomy_tenant_ownership.php` | Terms, translations and owner attachments |

Optional migration registration separates **expand** and **constrain** phases through the adoption coordinator. Registering a directory must not execute its constrain migration before reviewed backfill/verification. Package providers expose named expand/constrain migration lists from their adoption adapter; ordinary `migrate` only receives explicitly selected phase paths. No migration checks `tenancy.enabled` in `up()` and returns successfully without work. Do not mix these files into existing always-loaded `database/migrations`.

| Root/child | Ownership and constraints after activation |
|---|---|
| `media` | Direct ownership; globally unique UUID; `(ownership_key,id)` unique in mixed catalog mode or `(tenant_id,id)` in tenant-only mode; tenant-leading list, digest/disk/visibility, status/created indexes; immutable persisted `storage_path` length 1024; provenance columns nullable and without source FK |
| `media_associations`, `media_image_variations`, `media_i18n` | Ownership copied from media; concrete composite FK using the selected non-null partition and media ID; owner association additionally verified against canonical owner; tenant-leading owner/collection indexes |
| `media_multipart_uploads` | Direct tenant; immutable tenant-prefixed object key/disk; completed-media composite tenant FK; globally unique upload UUID/object identity remains global |
| configurable Media owner-slot operation table | Derived owner tenant; unique `(tenant_id,idempotency_key)`; request hash includes tenant; same effective connection as Media and owner |
| `metafields_definitions` | Direct ownership; `(ownership_key,active_handle)` unique when mixed; archived null active handles retain existing semantics; `(partition,id)` unique for child FKs |
| definition assignments/definition i18n | Inherit definition partition; unique `(partition,definition_id,owner_type)` and `(partition,definition_id,locale)` respectively |
| `metafields` | Inherit canonical owner tenant; definition must be that tenant's definition, including imported copies; unique `(tenant_id,metafieldable_type,metafieldable_id,definition_id)`; concrete composite FK to definition tenant/ID |
| metafield value i18n | Inherit value tenant; composite FK and unique `(tenant_id,metafield_id,locale)` |
| configured `terms` | Direct tenant; unique `(tenant_id,taxonomy,parent_key,slug)` and `(tenant_id,taxonomy,id)`; parent FK `(tenant_id,taxonomy,parent_id)`; preserve `__root__` parent key |
| configured `termables` | Owner and term same tenant; FK `(tenant_id,taxonomy,term_id)` to terms; unique `(tenant_id,term_id,termable_type,termable_id)` |
| configured term i18n | Same tenant as term; composite tenant/term FK; unique `(tenant_id,term_id,locale)` |

Mixed definitions require an additional unique `(tenant_id,id)` parent index for tenant-only value FKs, even while platform children use `(ownership_key,id)`. A null tenant on a platform definition can never satisfy a non-null tenant value FK. Mixed platform child rows include both partition columns and a consistency constraint; no nullable equality FK is claimed to enforce platform equality.

For every mixed table constrain `ownership_key='platform' AND tenant_id IS NULL` or `ownership_key='tenant:' || canonical_uuid AND tenant_id IS NOT NULL`. The migration uses the driver's supported string concatenation and check-constraint syntax; test actual invalid inserts on SQLite/PostgreSQL/MySQL/MariaDB. Application validation is required as well. Keep tenant-directory FKs conditional on the selected directory schema contract, never hardcode a FK to Auth users or a host's assumed tenants table.

The table above describes the application tenant profile. An explicitly platform-owned family uses its adopted platform partition: nullable tenant ID, non-null `ownership_key`, and partition-leading uniques/FKs instead of a non-null tenant constraint. Package declarations set `allowsPlatformRows` for that validated operational mode; grant-capable roots also declare `allowsPlatformCatalog`. Children inherit the chosen partition. The adapter persists this structural choice in its configuration fingerprint and rejects switching it by flag after adoption. Platform-only operation does not make platform terms/values available to tenant APIs. Any owner capability whose family partition cannot match its canonical owner's partition is rejected by configuration before activation.

### Exact registrations and package adoption APIs

Each `*TenancyResources` class has `register(TenantResourceRegistry $resources): void`; providers call it once with immutable descriptors. For example:

```php
$resources->register(new TenantResourceDefinition(
    key: 'media.assets', family: 'media', model: Media::class,
    allowsPlatformCatalog: $validatedSharingIsCopy,
    allowsPlatformRows: $validatedFamilyIsPlatform,
));
$resources->register(new TenantResourceDefinition(
    'media.associations', 'media', MediaAssociation::class,
    TenantResourceKind::Inherited, 'media.assets', 'media',
));
$resources->register(new TenantResourceDefinition(
    'metafields.values', 'metafields', Metafield::class,
    TenantResourceKind::Inherited, null, 'metafieldable',
));
```

`$validatedSharingIsCopy` is the validated scalar `tenancy.sharing.media === 'copy'`; `$validatedFamilyIsPlatform` is the effective immutable profile/family selection produced by the foundation configuration validator. No actor/context is captured. Register Media variation/translation children with parent relation `media`; multipart as a root; slot-operation owner as an inherited polymorphic `owner` relationship added to `MediaOwnerSlotOperation`. Register Metafield assignments/definition translations with relation `definition`, value translations with `metafield`, and taxonomy attachment/translations with `term`. Register actual parent resource keys from the table; do not abbreviate `.values` in PHP. `parentResource:null` is allowed only with a declared polymorphic parent relation and package owner allowlist followed by `TenantResourceRegistry::forModel($canonicalOwner)`; unknown owners fail.

Grant models are recipient-tenant roots under resource keys `media.catalog-grants` and `metafields.catalog-grants`, with concrete platform source validation inside their owning grant Actions. Ordinary tenant reads only see their grants; platform administration uses a package-internal recipient-ID query after explicit Platform authorization. A grant does not pass as source ownership.

The new adapters implement the frozen `TenantAdoptionAdapter` exactly: `resources(): array`, `prepare(TenantAdoptionPlan): void`, `backfill(TenantAdoptionPlan,?string $cursor,int $limit): TenantBackfillResult`, `verify(TenantAdoptionPlan): TenantVerification`, `activate(TenantAdoptionPlan): void`. Providers register `TenantAdoptionRegistry::register('media', MediaAdoptionAdapter::class)` and corresponding `metafields`/`taxonomy` entries. `prepare` applies expansion, `backfill` writes bounded ordered IDs plus cursor, `verify` produces bounded codes/IDs, `activate` applies constraints after verification. Coordinators own markers/checkpoints; package adapters own their schema/data.

### Reviewed split mappings

Every original root has one primary assignment `TenantAssignment(resource,recordId,TenantId,metadata)`. Additional copies derive only from reviewed canonical owner assignments. Optional metadata is package-validated: `splits` is a list of `{tenant_id: UUID, destination_id: UUID}`; `source_disposition` is exactly `retain-primary`; Media additionally records `expected_digest: lowercase SHA-256`. Reject duplicate/unknown tenants, destination collisions, and a split not justified by a reviewed attached owner. Persist concrete ledgers in the expansion files: `media_tenant_adoption_copies`, `metafield_definition_tenant_adoption_copies`, `term_tenant_adoption_copies`, each keyed by `(adoption_run_id,source_id,tenant_id)` with deterministic destination ID, status and timestamps; Media adds disk/path/digest/checksum_verified_at. These are adoption checkpoints, not ownership or runtime sharing pivots. Use `TenantAdoptionMappings::metadataFor()`/`assignments()`; never modify the reviewed input in place. Child schema rows and physical bytes follow the ledger destination before associations are rewritten.

## Test fixture contract used throughout

Create per-package helpers at `M/tests/Fixtures/MediaTenancyScenario.php`, `F/tests/Fixtures/MetafieldTenancyScenario.php`, `T/tests/Fixtures/TaxonomyTenancyScenario.php`. They share the following **behavior**, not a new cross-package runtime dependency:

```php
public const string A = '00000000-0000-4000-8000-00000000000a';
public const string B = '00000000-0000-4000-8000-00000000000b';
public static function install(bool $catalogCopies = false): self;
public function run(string $tenant, Closure $callback): mixed;
public function platform(Closure $callback): mixed;
public function owner(string $tenant): Model;
```

`install()` boots a dedicated Testbench case directly from Orchestra with `DatabaseMigrations`, never a base case that already uses `RefreshDatabase`. Bind a host `TenantDirectory` accepting A/B as Active, and test `PlatformAccess` accepting only purposes `fixture.adoption`/`fixture.catalog`, actor type `test`, actor ID `fixture`. Bind in-memory `MaintenanceMode` implementing `activate(array $payload):void`, `deactivate():void`, `active():bool`, `data():array`, initially active. The dedicated case configures application profile and sharing before provider boot; `install()` verifies that configuration and never changes an already-registered structural descriptor. Register fixture owner resources, and explicitly load the core migration `packages/nvl/tenancy/database/migrations/tenancy/2026_09_16_000001_create_tenancy_core_tables.php`. Package adapter `prepare()` owns expansion. Use this actual sequence, substituting package name:

```php
$coordinator = app(TenantAdoptionCoordinator::class);
$operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
$plan = $coordinator->prepare(['media'], [], $operation);
$done = false;
for ($batch = 0; $batch < 20 && ! $done; $batch++) {
    $done = $coordinator->backfill($plan, 100, $operation);
}
expect($done)->toBeTrue();
expect($coordinator->verify($plan)->passed())->toBeTrue();
$coordinator->activate($plan, $operation);
app(MaintenanceMode::class)->deactivate();
```

Create dedicated `M/tests/MediaTenancyTestCase.php`, `F/tests/MetafieldTenancyTestCase.php`, `T/tests/TaxonomyTenancyTestCase.php`. Modify each `tests/Pest.php`: scope the old case to its existing test directories/files, and the new case only to `tests/Tenancy`. Media's old case covers `Feature`, `Unit`, `Integration`; Metafields covers `Feature`; Taxonomy names its existing `TaxonomyTest.php` and `TaxonomyConsumerContractsTest.php`. New tenancy tests in this plan reside under `tests/Tenancy` so no duplicate case bindings or outer transactions occur. Copy only the owning package's existing minimal environment/provider setup into the new case; define its fixture owner table in a test-owned migration and register its owner adoption adapter alongside the package. Normal tests remain disabled. Add `M/tests/MediaCatalogTenancyTestCase.php` and `F/tests/MetafieldCatalogTenancyTestCase.php` extending their dedicated tenancy cases, overriding `defineEnvironment()` to set sharing to `copy` before provider boot. Bind ordinary cases only to `Tenancy/Feature` (Taxonomy: its named `Tenancy/*Test.php` files), catalog cases to `Tenancy/Catalog`, and Media's catalog-capable case also to its new `Tenancy/Integration` files. `install(catalogCopies:true)` verifies this preconfigured mode rather than mutating config; false cases require `none`. Media integration tests using the catalog case call `install(catalogCopies:true)`. Activation invalidates the process probe cache; no in-memory SQLite reboot discards data.

Define the owner fixture schema/adapter at each package's `tests/Fixtures/TenancyOwnerAdoptionAdapter.php`, implementing the frozen adoption interface. Its sole resource is `test.media-owners`, `test.metafield-owners`, or `test.taxonomy-owners`, mapped to the existing stub class with its existing table/key type. `prepare()` creates the test-owned table if absent with original columns plus non-null tenant UUID and `(tenant_id,id)` unique; `backfill()` returns `TenantBackfillResult(null,0)` only for the verified empty test table; `verify()` checks columns and empty initial ownership consistency; `activate()` has no extra DDL. Register package name `resource-fixture-owners` and include it in `prepare(['resource-fixture-owners','media'],[],$operation)` (or corresponding package). The helper creates initial fixture tables through this adapter, never by hand-writing an active marker. This adapter is for fixtures only and is not shipped as a production owner model generator.

`run()` delegates to `TenantRunner::run(new TenantId($tenant), $callback)`; `platform()` delegates to `TenantRunner::platform(new PlatformOperation('fixture.catalog','test','fixture'),$callback)`. `owner()` uses the existing owner stub with non-null tenant column and registered resource, applies `TenantBoundary::attributes()` server-side and returns fresh data. It never mutates context directly or mocks the boundary.

Media helper additionally defines `upload(string $tenant, string $bytes, bool $public = true): Media`: fake `tenant-disk`, create a fresh owner, use `UploadedFile` backed by a temporary UTF-8 text file containing `$bytes`, call actual `UploadMediaAction::execute(file: $file, disk: 'tenant-disk', model: $owner, slot: new MediaSlot('default'), fileName: 'sample.txt', isPublic: $public, skipAutoVariations: true)`, and unlink its local temp in `finally`. Register `txt` policy and a slot accepting it. Run on the tenant boundary. Existing MediaFactory is used only when bytes are irrelevant.

Metafield helper defines `definition(string $tenant, string $key = 'color'): MetafieldDefinition`: run `CreateMetafieldDefinitionAction::execute(CreateMetafieldDefinitionPayload::from(['namespace'=>'catalog','key'=>$key,'type'=>'string','translations'=>['en'=>['title'=>'Color']],'assignment'=>['ownerType'=>'test-owner','section'=>'general']]))`; configure `'test-owner'` to the owner fixture with string support. Its `platformDefinition()` executes the same owning action inside `platform()` and returns the definition. This assignment describes eligible owner type; no platform owner instance is created or attached.

Taxonomy helper defines `term(string $tenant, string $slug = 'same'): Term`: run `CreateTermAction::execute(new MutateTermPayload(taxonomy:'tag',slug:$slug,translations:['en'=>['name'=>$slug]]))`. It registers fixture owner alias `'test-owner'` and preserves the immutable tag/category definitions.

`MediaSlot` currently accepts the single name argument used above. The fixture sets `acceptedMimeTypes = ['text/plain']`. No test helper may silently bypass public writer behavior.

## Task 1: Register ownership and adopt Media's complete graph

**Create:** `M/src/Tenancy/MediaTenancyResources.php`, `MediaAdoptionAdapter.php`; the three Media schema files above; `M/tests/Tenancy/Feature/MediaTenancySchemaTest.php`; fixture above.

**Modify:** `M/composer.json`; `M/src/Providers/MediaServiceProvider.php`; `M/src/Models/Media.php`, `MediaAssociation.php`, `MediaImageVariation.php`, `MediaTranslation.php`, `MediaMultipartUpload.php`, `MediaOwnerSlotOperation.php`; `M/src/Definitions/Tables/MediaTables.php`; `M/src/Services/MediaDoctor.php`; `src/Support/SuiteModuleCatalog.php`; `tools/package-contracts.json`.

**Consumes:** foundation registry/adoption APIs, `TenantBoundary`, Translatable `ownershipResource`. **Produces:** `media.assets` root; `media.associations`, `media.variations`, `media.translations`, `media.multipart`, `media.slot-operations` derived declarations and compatible schema.

- [ ] Add new package dependency `nvl/tenancy:^2.0`; the optional feature remains false. Add model property docs/casts, protected ownership mutation guards and the appropriate immutable resource declaration. Root fields are not client fillable. Media translation definition receives `ownershipResource: 'media.assets'`.
- [ ] Write the failing persistence proof using two Media roots created by the fixture:

```php
it('rejects an association with a tenant different from its asset', function (): void {
    $s = MediaTenancyScenario::install();
    $a = $s->run($s::A, fn () => Media::factory()->create([
        ...app(TenantBoundary::class)->attributes('media.assets'),
    ]));
    $bOwner = $s->owner($s::B);

    expect(fn () => DB::table('media_associations')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $s::B,
        'media_id' => $a->id, 'associable_type' => $bOwner->getMorphClass(),
        'associable_id' => $bOwner->getKey(), 'collection' => 'default',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
```

In mixed mode include B's `ownership_key` in the inserted row; the test must fail on the FK rather than a missing required field.
- [ ] Run from repository root:

```bash
vendor/bin/pest --test-directory=packages/nvl/media/tests --configuration=packages/nvl/media/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/media/tests/Tenancy/Feature/MediaTenancySchemaTest.php
```

Expected red: new schema absent, then FK assertion fails until constrain phase works.
- [ ] Implement schema expansion as nullable columns, reviewed mapping/backfill, then non-null/composite constraints. `MediaAdoptionAdapter` inventories both soft-deleted and active rows, associations, translations, variations, multipart sessions, and operation ledgers. Persist original `storage_path` from current actual disk/root/folder/hash before changing any root configuration. Inventory same-digest legacy rows spanning owners in A/B; split asset rows and copy verified binaries per tenant, recreate variation/translation children and rewrite associations using a durable old-ID+tenant→new-ID mapping. Do not move/delete the shared original before all copies verify.
- [ ] Adapter verification compares counts, association target IDs, file lengths/checksums and parent ownership; unmapped uploader alone is not an ownership mapping. Unknown owners, multiple owner tenants, anonymous unassociated files, expired multipart sessions and completed operation payloads require explicit mapping or an explicit cleanup decision. Never infer tenant from the first uploader membership.
- [ ] Doctor rejects mismatched effective owner-slot operation connection, missing adopted columns/indexes/marker, sharing without mixed schema, or renamed configured table without adoption mapping. Disabled installation requires no tenant schema, with the foundation's bounded marker probe accounted separately.
- [ ] Run schema test and existing `ProviderConfigurationTest.php`, `MediaModelTest.php`; verify disabled migration list excludes all new phase files. Commit: `feat(media): add opt-in tenant ownership schema`.

## Task 2: Guard Media queries, Actions, owner traits and public delivery

**Create:** `M/src/Services/MediaTenantOwnerResolver.php`; `M/tests/Tenancy/Feature/MediaTenantBoundaryTest.php`; `M/tests/Tenancy/Feature/MediaTenantAssetRoutesTest.php`.

**Modify:** `M/src/Services/MediaQueryService.php`, `MediaAccessService.php`, `DefaultMediaAuthorization.php`, `MediaAssociableResolver.php`, `MediaOwnerSlotResolver.php`, `MediaModelInteractionService.php`, `MediaMutationService.php`, `MediaLifecycleService.php`; `M/src/Traits/InteractsWithMedia.php`; every mutation Action in `M/src/Actions`; `M/src/Http/Controllers/MediaAssetController.php`; `M/src/Providers/RouteServiceProvider.php`; asset routes and owning models.

The Action inventory is exactly `M/src/Actions/{AbortMultipartUploadAction,AdoptSpatieMediaAction,AttachMediaAction,BulkDeleteMediaAction,BulkMoveMediaAction,BulkTagMediaAction,ClearOwnerMediaSlotAction,CompleteMultipartUploadAction,CopyOwnerMediaSlotAction,DeleteMediaAction,DetachMediaAction,FinalizeMediaScanAction,GenerateImageVariationAction,GetOwnerMediaSlotAction,InitiateMultipartUploadAction,MutateMediaTagsAction,RelocateMediaAction,RenameMediaAction,ReplaceMediaFileAction,ReplaceOwnerMediaSlotAction,ReusePublicMediaAction,SignMultipartPartAction,UpdateMediaMetadataAction,UploadMediaAction}.php`. Include the read-only owner-slot Action because it accepts owner/model identities. Verify no exported contract/facade bypasses these guarded paths; scope-only HTTP tests do not satisfy this task.

**New API:** `MediaTenantOwnerResolver::resolve(Model $owner, bool $lock = false): Model`. It uses foundation's registered owner descriptor, reloads through that owner's domain resource boundary by key, verifies HasMedia and the effective connection, and returns canonical data. Disabled mode retains existing supported owner behavior. Unknown enabled owners fail closed; `media.allowed_associable_types` is not ownership classification.

- [ ] Add failing tests:

```php
it('denies public reuse across tenant owners even for a passed model', function (): void {
    $s = MediaTenancyScenario::install();
    $asset = $s->upload($s::A, 'public bytes');
    $ownerB = $s->owner($s::B);

    expect(fn () => $s->run($s::B, fn () => app(ReusePublicMediaAction::class)
        ->execute($asset, $ownerB)))->toThrow(TenantBoundaryViolation::class);
});

it('does not allow a privileged actor to list another tenant library', function (): void {
    $s = MediaTenancyScenario::install();
    $a = $s->upload($s::A, 'A');
    $s->upload($s::B, 'B');

    $ids = $s->run($s::A, fn () => app(MediaQueryService::class)
        ->index(new MediaFilter)->getCollection()->modelKeys());

    expect($ids)->toBe([$a->id]);
});
```

The second test uses null actor to prove the existing null-user broad query is still bounded; add existing `TestPermissionMediaUser` with `media.view-any` to prove permission grants cannot widen it.
- [ ] Run `MediaTenantBoundaryTest.php` with Task 1's runner prefix. Expected red: cross-tenant attachment succeeds or list includes B.
- [ ] Apply tenant predicates before visibility/authorization. All bulk IDs resolve inside the active resource query; reject a partially foreign list atomically instead of returning success for its local subset. A passed model contributes only its key; reload and lock before mutate/delete/replace/attach/detach. Resolve the canonical owner before deriving slot rules, paths or IDs. Existing permissions, uploader policy, availability, public/private rules remain additional checks.
- [ ] Update root/global query safety and per-Action checks. Global scope protects ordinary supported model/relationship queries, but Actions explicitly use `TenantBoundary::query/assertRecord`. Model saving/restore/force-delete and package quiet-write methods protect immutable tenant ownership. Raw pivots, aggregates, filter options, usages, activeAssociation, tags, translated search, pruning and owner events get explicit boundary checks. Add canonical owner validation before `InteractsWithMedia::getMedia/hasMedia` loaded-relation branches; inspect child ownership before returning loaded rows.
- [ ] Wire asset routes to trusted `TenantSiteResolver` context **before** route model binding. Foreign/unknown asset returns identical 404. Private URLs bind tenant and media revision as well as existing owner/expiry signature; generated URLs use verified site origin. `MediaAssetController` must check tenant status before 304/ETag handling, range or HEAD handling. Public visibility never grants management or cross-tenant reuse. A system MediaActor still must pass the explicit resource boundary.
- [ ] Add HTTP tests for same UUID against wrong host, suspended tenant, conflicting selector, expired signed link, range/HEAD, and unchanged-public-version ETag. Assert enumeration-neutral status and headers only; durable policy permutations remain in the Action test. Document that issued S3 URLs and immutable public CDN caches are capabilities until expiry/purge, not instantly revocable server checks.
- [ ] Run new tests plus existing `MediaApiTest.php`, `MediaAssetRoutesTest.php`, `InteractsWithMediaTest.php`, `MediaPrivilegedAccessTest.php`. Commit: `feat(media): enforce tenant boundaries at resource entry points`.

## Task 3: Isolate Media binaries, deduplication, multipart and operations

**Create:** `M/tests/Tenancy/Feature/MediaTenantStorageTest.php`; `M/tests/Tenancy/Feature/MediaTenantMultipartTest.php`.

**Modify:** `UploadMediaAction.php`, `ReplaceMediaFileAction.php`, `RelocateMediaAction.php`, `AdoptSpatieMediaAction.php`; Services `MediaPathResolver`, `MediaDeduplicationLock`, `MediaMutationLock`, `MediaMultipartLock`, `MediaMultipartService`, `MediaOwnerSlotIdempotency`, `MediaOwnerSlotWorkflow`, `MediaOwnerSlotCopyWorkflow`, `MediaFileEffectScheduler`, `MediaTransactionRollbackRegistry`, `MediaFileOperator`, `MediaUrlResolver`; Models and `M/src/Console/Commands/{StorageHealthCommand,MigrateDiskCommand,PruneExpiredMultipartUploadsCommand,PruneMediaOwnerSlotOperationsCommand}.php`.

**Produces:** immutable original object location, tenant-scoped locks and idempotency, same-tenant slot copies.

- [ ] Write red dedup proof:

```php
it('deduplicates within A but never reuses A asset or object in B', function (): void {
    $s = MediaTenancyScenario::install();
    $a1 = $s->upload($s::A, 'identical');
    $a2 = $s->upload($s::A, 'identical');
    $b = $s->upload($s::B, 'identical');

    $aPath = $s->run($s::A, fn () => $a1->buildPath());
    $bPath = $s->run($s::B, fn () => $b->buildPath());
    expect($a2->id)->toBe($a1->id)
        ->and($b->id)->not->toBe($a1->id)
        ->and($bPath)->not->toBe($aPath)
        ->and($aPath)->toContain('/tenants/'.$s::A.'/')
        ->and($bPath)->toContain('/tenants/'.$s::B.'/');
});
```

- [ ] Run `MediaTenantStorageTest.php`; expected red: equal media ID for public digest or absent tenant prefix.
- [ ] Put the validated tenant prefix outside user folders: `root/tenants/<uuid>/<normalized-relative-folder>/<hash>`. Platform catalog path is `root/platform/<folder>/<hash>`. Persist the exact object path at write time. `mediaPath()` returns it for adopted records; only disabled untouched legacy rows use root/folder/hash reconstruction. Variations and multipart use the same ownership path policy, retaining their persisted paths. A caller folder override cannot remove or duplicate the tenant segment.
- [ ] Add tenant boundary to every digest query, duplicate filename count, metadata/variation merge and cache lock. Use `TenantBoundary::key('media.assets', $legacyLockIdentity)` so disabled identities remain unchanged. Private dedup still includes uploader type/ID within tenant; same global uploader across A/B never deduplicates between them. Slot request hashes and lookup uniqueness include tenant; replay cannot disclose B result payload.
- [ ] Require same-tenant canonical owners in CopyOwnerMediaSlotAction. Catalog import is a separate Action, not a `copyAcrossTenants=true` escape hatch. Retain source revision checks. Normalize effective connections before opening transactions; file callbacks capture immutable connection/disk/path/context scalar data at registration. Never read ambient tenant while a later callback runs.
- [ ] Multipart initiate stores tenant; sign/complete/abort/scan/failure/prune reload the session under it and verify its completed media tenant. Object-key uniqueness remains a physical global invariant, while actor/status lookup indexes become tenant-leading. A foreign session ID gives the same unavailable response as an unknown ID.
- [ ] Storage health/prune/delete only enumerates known tenant prefixes in tenant mode. Legacy unknown-path/orphan inventory requires an explicit platform operation and reviewed mapping. Shared disk-wide deletion is not allowed from a tenant command. Disk migration copies and checksums then updates persisted identity after success, never derives a new source path from changed config.
- [ ] Extend red/green tests for same private uploader, same slot key in A/B, multipart foreign ID, file rollback after context restoration, changed root configuration, and migration resume. Run existing `MultipartUploadTest.php`, `MediaOwnerSlotWorkflowTest.php`, `TransactionLifecycleTest.php`, `MediaUrlPathTest.php`, `StorageHealthCommandTest.php`. Commit: `feat(media): partition tenant storage and operation identity`.

## Task 4: Add concrete Media grants and copy-on-import

**Create:** `M/src/Models/MediaTenantGrant.php`; `M/src/Contracts/MediaCatalogImport.php`; `M/src/Data/Mutations/ImportPlatformMediaData.php`; `M/src/Actions/GrantMediaToTenantAction.php`, `RevokeMediaTenantGrantAction.php`, `ImportPlatformMediaAction.php`; `M/src/Services/MediaCatalogReader.php`, `MediaCatalogImporter.php`; `M/src/Data/MediaCatalogSnapshot.php`, `StagedCatalogMedia.php`; `M/tests/Tenancy/Catalog/MediaCatalogImportTest.php`.

**Schema:** `media_tenant_grants(id UUID, tenant_id UUID, media_id UUID FK, source_revision unsigned bigint, revision unsigned bigint, enabled bool, revoked_at nullable timestamp, timestamps)`, unique `(tenant_id,media_id)`. Concrete `media_id` FK can cascade grant cleanup on source deletion; imported copy provenance cannot. Add `catalog_import_key UUID nullable`, `catalog_source_id UUID nullable`, `catalog_source_revision bigint nullable`, `catalog_source_digest char(64) nullable` on Media. Unique `(tenant_id,catalog_import_key)` makes retries local and idempotent. No tenant can directly edit a grant through Media mutation DTOs.

**Interfaces:**

```php
GrantMediaToTenantAction::execute(string $mediaId, TenantId $recipient, int $sourceRevision): MediaTenantGrant;
RevokeMediaTenantGrantAction::execute(string $grantId, int $expectedRevision): MediaTenantGrant;
MediaCatalogReader::find(string $grantId): MediaCatalogSnapshot;
ImportPlatformMediaAction::execute(ImportPlatformMediaData $data): Media;

// ImportPlatformMediaData's constructor; no tenant input.
public function __construct(
    public string $grantId,
    public int $expectedGrantRevision,
    public int $expectedSourceRevision,
    public string $idempotencyKey,
) {}
```

`MediaCatalogSnapshot` contains scalar `grantId`, `grantRevision`, `sourceId`, `sourceRevision`, `sourceDigest`, `disk`, `storagePath`, `filename`, `mimeType`, `size`, safe metadata/tags, and exact locale maps. Its internal storage fields never appear in an HTTP list payload. `StagedCatalogMedia` contains target `disk`, `storagePath`, `digest`, `size`; the importer owns cleanup. Define `MediaCatalogImporter::stage(MediaCatalogSnapshot $source): StagedCatalogMedia`, `persist(MediaCatalogSnapshot $source, StagedCatalogMedia $file, string $idempotencyKey): Media`, and `discard(StagedCatalogMedia $file): void`. Stage materializes/copies only the allowed source original, verifies checksum/size, and creates an independent tenant object; persist creates fresh root/translations with safe metadata and provenance inside the caller's transaction.

**Cross-package public port:** Templates/Content must compose staged files with their final shared-connection transaction through this package-owned contract, not by invoking an Action that commits before their graph is ready:

```php
namespace Nvl\Media\Contracts;

interface MediaCatalogImport
{
    public function inspect(string $grantId): MediaCatalogSnapshot;
    public function stage(MediaCatalogSnapshot $source): StagedCatalogMedia;
    public function persist(MediaCatalogSnapshot $source, StagedCatalogMedia $file, string $idempotencyKey): Media;
    public function discard(StagedCatalogMedia $file): void;
}
```

Import `Nvl\Media\Data\MediaCatalogSnapshot`, `Nvl\Media\Data\StagedCatalogMedia`, and `Nvl\Media\Models\Media` in the actual interface. `MediaCatalogImporter` implements this contract; `inspect()` delegates to the narrow Reader. Bind it as a scoped public contract in `MediaServiceProvider` and register it plus the two snapshot DTOs in `tools/package-contracts.json` and consumer audit surfaces. Consumers inject `MediaCatalogImport`, never the internal Reader/Importer classes.

`persist()` requires an already-open transaction on the same normalized connection as the consumer graph. It acquires grant then source locks, rechecks active tenant, canonical grant recipient/enabled/revision, source platform ownership/status/revision/digest and idempotency fingerprint; it never trusts a supplied snapshot as authorization. It verifies the staged object's tenant, exact registered path and checksum before creating rows. Add `operationId` and `tenantId` scalar fields to `StagedCatalogMedia`; the scoped importer retains the exact tuples it created, so callers cannot substitute arbitrary filesystem paths into persist/discard. `discard()` deletes only a matching staged tuple and is idempotent. Persist registers rollback cleanup against the actual root transaction and defers variation work until that transaction commits. Callers must `discard()` staged files on pre-transaction failure; the root rollback handler handles later failure. Imported rows and graph writes commit together; a failure does not leave unattached copied media rows.

For a graph with multiple assets, inspect/stage outside its final DB transaction, then persist in sorted grant-ID/source-ID order inside it; consumer grant/root lock order is documented by its own owning Action before the sorted Media portion. Revocation during staging is detected by each final persist. `ImportPlatformMediaAction` remains the standalone facade: inspect, stage, begin its own transaction, call this port's persist, and discard on failure/replay. It cannot be used as a substitute for staging a larger cross-package graph.

- [ ] Add failing revocation proof:

```php
it('keeps a completed catalog copy after revocation but rejects a new import', function (): void {
    $s = MediaTenancyScenario::install(catalogCopies: true);
    $source = $s->platform(fn () => Media::factory()->create([
        ...app(TenantBoundary::class)->attributes('media.assets'),
        'is_public' => false, 'visibility' => 'private', 'disk' => 'tenant-disk',
        'filename' => 'catalog.txt', 'extension' => 'txt', 'mime_type' => 'text/plain',
        'size' => 7, 'digest' => hash('sha256', 'catalog'),
        'storage_path' => 'media/platform/catalog.txt', 'status' => 'available',
    ]));
    $s->platform(fn () => Storage::disk($source->disk)->put($source->buildPath(), 'catalog'));
    $grant = $s->platform(fn () => app(GrantMediaToTenantAction::class)
        ->execute($source->id, new TenantId($s::A), $source->revision));
    $request = new ImportPlatformMediaData($grant->id, $grant->revision, $source->revision, (string) Str::uuid());
    $copy = $s->run($s::A, fn () => app(ImportPlatformMediaAction::class)->execute($request));
    $s->platform(fn () => app(RevokeMediaTenantGrantAction::class)->execute($grant->id, $grant->revision));

    expect($s->run($s::A, fn () => Media::query()->findOrFail($copy->id)->digest))->toBe($source->digest);
    expect(fn () => $s->run($s::A, fn () => app(ImportPlatformMediaAction::class)->execute(
        new ImportPlatformMediaData($grant->id, $grant->revision, $source->revision, (string) Str::uuid()),
    )))->toThrow(TenantBoundaryViolation::class);
});
```

- [ ] Run `MediaCatalogImportTest.php`; expected red until grant/import Actions exist, then revoked import must fail by policy, not missing bytes.
- [ ] Grant and revoke Actions require explicit Platform mode, source platform ownership, active recipient and expected revision; write after-commit audit facts containing IDs/revisions only. CatalogReader is the one intentionally authorized source read: query grant for active recipient tenant, check enabled/revoked state and matching source revision, then load only that platform ID using a **package-internal** predicate. It does not switch the ambient context or expose a general unscoped builder. Public source status is irrelevant to grant rights; source must be Available.
- [ ] The internal source reader uses explicit selected columns and `ownership_key='platform'` plus the granted source ID for root/translation reads. It builds the scalar snapshot directly; it must not call a normal Media trait/accessor on a platform model while tenant context is active, because those public methods correctly reject the mismatch. Limit this read capability to the granted item, propagate no Eloquent source model to callers, and revalidate it inside the final transaction.
- [ ] Import algorithm: validate input; inspect authorized source snapshot; stage independent target bytes; start same-connection transaction; lock grant then source; recheck recipient, tenant active status, exact grant/source revisions, source digest/status; look up tenant/import key; if a matching prior result exists, return it only after current grant validation; otherwise persist copy and locale rows, register staged-file rollback cleanup, then commit. Remove redundant staged file on replay. Dispatch tenant variation jobs after commit; copy only approved metadata and regenerate variation definitions under target policy. On any failure discard staged bytes. Changed source invalidates the old snapshot/grant revision until platform refreshes the grant.
- [ ] Add tests for grant to B used in A, source replacement during staging, grant revoke during staging, key replay with altered source, source deletion after import, malicious provenance/tenant payload, and tenant attempting direct platform reuse. Test import/revoke race with separate DB connections in Task 5. Commit: `feat(media): import granted platform assets as tenant copies`.
- [ ] Add the port-specific rollback proof before committing: call `inspect()`/`stage()` in A, then `$connection->transaction(function () use ($port,$snapshot,$file): void { $port->persist($snapshot,$file,(string) Str::uuid()); throw new RuntimeException('graph rollback'); });`. Catch only that expected exception in the test; assert no target Media row and no staged object, with source/grant untouched. Assert `persist()` outside a transaction throws before any write and a caller-substituted path is rejected. This is the gate consumed by the Templates graph-copy plan.

## Task 5: Capture Media context in queue and operational work

**Create:** `M/tests/Tenancy/Integration/MediaTenantWorkerTest.php`, `MediaTenantImportConcurrencyTest.php`; `M/tests/Tenancy/Feature/MediaTenantQueuePayloadTest.php`.

**Modify:** `M/src/Jobs/GenerateImageVariationJob.php`, `ProcessMediaVariationsJob.php`, `RegenerateMediaVariationsJob.php`; `M/src/Services/MediaVariationDispatcher.php`; `M/src/Console/Commands/RegenerateVariationsCommand.php`; existing `M/tests/Integration/ProductionStackTest.php`, `M/tests/Feature/MediaOwnerSlotDatabaseConcurrencyTest.php` and existing CI integration jobs.

**Consumes:** foundation queue lifecycle before deserialization; scalar package job IDs. **Produces:** same-worker A→B→failure→missing-context evidence.

- [ ] Add the failing payload test by dispatching actual `GenerateImageVariationJob` under A to the database queue, reading its stored JSON payload, and asserting foundation context metadata identifies A while the serialized command contains only `mediaId`, preset definition and source revision—not Media/Tenant models. Delete the queued probe after asserting. Queue fakes cannot prove serialization.
- [ ] Run the new Feature test; expected red if job metadata lacks A.
- [ ] Append `?TenantJobEnvelope $envelope = null` to each existing job constructor; store a non-null readonly envelope captured from `TenantContext` by the package dispatcher when omitted. The foundation serializer writes the same versioned scalar envelope into payload data. Preserve existing positional arguments and disabled-mode construction. `handle()` relies on established validated context and reloads through `TenantBoundary::query`. Child dispatches capture the same tenant at creation. Regeneration jobs require a single tenant; no-context scan throws. Maintenance `--tenant=<uuid>` enters the bounded runner; `--all-tenants` requires an explicit platform operation and iterates active tenants with independent checkpoints.
- [ ] Create Media's own consumer fixture, without importing another package's test classes: `M/tests/Fixtures/tenancy-consumer/artisan.php`, `config/{app,database,queue,cache,filesystems,tenancy,media}.php`, plus `M/tests/Fixtures/MediaTenancyConsumerServiceProvider.php` and `MediaTenancyConsumerSetupCommand.php`. Its entrypoint uses Laravel Application configuration, root autoload from `NVL_TEST_SUITE_ROOT`, Support/Data/Filterable/Tenancy/Translatable/Media and its fixture provider. Tests copy it to an isolated temp directory with unique bootstrap/storage paths; database and queue env point only to the disposable test services. The provider registers the real Media adapter, fixture-owner adapter and test directory/platform/maintenance implementations. Command `media-tenancy-fixture:setup` explicitly migrates the core and normal Media schema, creates jobs/failed_jobs tables, runs the fixture adoption sequence, and seeds A/B image assets using actual ingestion. Never migrate or adopt implicitly on worker boot.
- [ ] Launch `['php',$fixture.'/artisan.php','queue:work','database','--queue='.$queue,'--stop-when-empty','--tries=2','--timeout=30']` through Process with a 60-second timeout and isolated env. Dispatch A and B variation jobs with equal preset names, a stale revision, a deliberately failing source, a retry, then a valid serialized job whose final queued envelope is deliberately corrupted. Unresolved normal dispatch is rejected by the producer; the corrupt queued row proves consumer rejection before lookup. Assert object paths and variation rows remain correctly owned, failure cleanup is local, and the worker's next job sees no prior tenant/locale/settings. Check sync dispatch and after-commit dispatch separately. Do not call `handle()` directly as the worker gate.
- [ ] Reuse PostgreSQL/Redis/S3 test configuration in `ProductionStackTest.php`; add tenant prefix/checksum assertions and a distributed dedup race. Two processes importing and revoking use a barrier immediately before final grant lock; force each winner once and assert the linearization outcomes stated above. Existing `MediaOwnerSlotDatabaseConcurrencyTest.php` gains same-slot keys in A/B without cross contention or replay.
- [ ] Commands: run the three new files with Task 1's runner prefix and provisioned test services; run `composer test:media-production`; run the existing DB concurrency file. CI must fail when an expected service gate skips. Record database, queue/cache driver, S3 endpoint fixture, process count and results. Commit: `test(media): prove tenant queue and storage isolation`.

## Task 6: Isolate Metafield definitions, assignments, owners and references

**Create:** `F/src/Tenancy/MetafieldTenancyResources.php`, `MetafieldAdoptionAdapter.php`; the three schema files; `F/tests/Tenancy/Feature/MetafieldTenancyTest.php`, `MetafieldTenancySchemaTest.php`; fixture above.

**Modify:** `F/composer.json`; provider and models; `F/src/Services/MetafieldDefinitions/{MetafieldDefinitionCatalog,MetafieldDefinitionWriter,MetafieldDefinitionMutationGuard,MetafieldDefinitionRemover,MetafieldDefinitionAssignmentSyncer}.php`; `F/src/Services/Metafields/{MetafieldOwnerModelResolver,OwnerMetafieldAssignmentCatalog,OwnerMetafieldFieldCatalog,OwnerMetafieldRecordFinder,OwnerMetafieldRecordWriter,OwnerMetafieldSyncValidator,MetafieldValueValidator}.php`; `F/src/Support/{MetafieldOwnerRegistry,MetafieldReferenceModelRegistry}.php`; Actions, `Traits/HasMetafields.php`, Doctor; suite catalog/contracts.

Exact Action paths: `F/src/Actions/MetafieldDefinitions/{ArchiveMetafieldDefinitionAction,CreateMetafieldDefinitionAction,DeleteMetafieldDefinitionAction,ListMetafieldDefinitionsAction,UpdateMetafieldDefinitionAction}.php` and `F/src/Actions/Metafields/{DeleteOwnerMetafieldAction,ListAuthorizedOwnerMetafieldsAction,ListOwnerMetafieldsAction,SetMetafieldAction,SyncOwnerMetafieldsAction}.php`. Exact models: `F/src/Models/{Metafield,MetafieldDefinition,MetafieldDefinitionAssignment,MetafieldDefinitionTranslation,MetafieldTranslation}.php`; provider is `F/src/Providers/MetafieldsServiceProvider.php`, Doctor is `F/src/Services/MetafieldDoctor.php`.

**Produces:** resource keys `metafields.definitions`, `.definition-assignments`, `.definition-translations`, `.values`, `.value-translations`. Definitions/assignments/locale rows form one graph; values derive tenant from canonical owner. `MetafieldOwnerModelResolver::resolve(string $ownerType,string $ownerId): Model` retains its public signature; add `canonical(Model $owner,bool $lock=false): Model` for programmatic calls. Reference registry keeps stable aliases but delegates record existence/load to an injected scoped resolver; a configured class alone is not authorization.

- [ ] Add red same-handle proof:

```php
it('keeps an identical definition handle and owner values local to each tenant', function (): void {
    $s = MetafieldTenancyScenario::install();
    $a = $s->definition($s::A);
    $b = $s->definition($s::B);
    $ownerB = $s->owner($s::B);

    expect($a->id)->not->toBe($b->id);
    expect(fn () => $s->run($s::A, fn () => app(SetMetafieldAction::class)
        ->execute($ownerB, 'catalog.color', 'foreign')))->toThrow(TenantBoundaryViolation::class);
});
```

- [ ] Run:

```bash
vendor/bin/pest --test-directory=packages/nvl/metafields/tests --configuration=packages/nvl/metafields/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/metafields/tests/Tenancy/Feature/MetafieldTenancyTest.php
```

Expected red: current global active_handle uniqueness, then forged-owner mutation.
- [ ] Implement schema/backfill constraints from the table. Existing definition used by owners in multiple tenants is copied per tenant with assignments and translated labels/defaults, then each value's definition ID is remapped. Preserve archived/trashed definitions and values; never delete history to satisfy uniqueness. The adoption report explicitly classifies standalone definitions and every reference/default reference. Reject ambiguous references before cutover.
- [ ] Canonicalize and lock owner first on the same effective connection, then lock sorted definition IDs, then value rows. Assignment catalog must return only the owner's tenant definitions. Do not combine platform and tenant handles in `findByHandle/mapIdsByHandles`; ordinary resolution sees local definitions only, and direct platform IDs fail. Imported copies are ordinary local definitions with their chosen target handles.
- [ ] Guard create/update/archive/delete/restore and canonical `getValue()`/default/reference reads; loaded definition/translation relations are checked before use. Value writers derive tenant from owner and verify definition/assignment matches it. Existing required-section checks operate only on local assignments; foreign required fields must not make A invalid.
- [ ] Reference existence, instance loading, ReferenceList, localized defaults, default_referenced_id, serialized lists and configured validation rules must resolve each target through its declared resource and same tenant. Deduplicate/sort identifiers before locks. A reference to platform identity is forbidden in this release unless the domain explicitly classifies that target as fixed global identity and the reference contract allows it; no blanket `tenant_id IS NULL` allowance. Unknown target classifications fail closed.
- [ ] Add new API/Action/schema tests for defaults, null/clear, soft-delete restore, foreign ID list rollback, eager loading, same handle in A/B, foreign direct model, and mutation DTO ownership keys. Run existing `MetafieldConsumerWorkflowTest.php`, `MetafieldRelationTest.php`, `MetafieldIdentifierStrategyTest.php`, `MetafieldsApiTest.php`. Commit: `feat(metafields): isolate definitions and owner values`.

## Task 7: Import granted Metafield definitions as independent schemas

**Create:** `F/src/Models/MetafieldDefinitionTenantGrant.php`; `F/src/Data/ImportPlatformMetafieldDefinitionData.php`; Actions `GrantMetafieldDefinitionToTenantAction.php`, `RevokeMetafieldDefinitionTenantGrantAction.php`, `ImportPlatformMetafieldDefinitionAction.php`; Services `MetafieldDefinitionCatalogReader.php`, `MetafieldDefinitionImporter.php`; `F/tests/Tenancy/Catalog/MetafieldCatalogImportTest.php`.

**Schema:** `metafield_definition_tenant_grants(id, tenant_id, definition_id concrete FK, source_revision, revision, enabled, revoked_at, timestamps)`, unique tenant/definition. Definition provenance `catalog_source_id`, `catalog_source_revision`, `catalog_source_hash`, nullable `catalog_import_key`, all without source FK; unique tenant/import key. Platform source deletion may delete its grants but not its copied definitions or tenant values.

**Interfaces:**

```php
GrantMetafieldDefinitionToTenantAction::execute(string $definitionId, TenantId $recipient, int $sourceRevision): MetafieldDefinitionTenantGrant;
RevokeMetafieldDefinitionTenantGrantAction::execute(string $grantId, int $expectedRevision): MetafieldDefinitionTenantGrant;
ImportPlatformMetafieldDefinitionAction::execute(ImportPlatformMetafieldDefinitionData $data): MetafieldDefinition;

public function __construct(
    public string $grantId,
    public int $expectedGrantRevision,
    public int $expectedSourceRevision,
    public string $idempotencyKey,
    public string $namespace,
    public string $key,
    public AssignMetafieldDefinitionPayload $assignment,
    /** @var array<string,string> source reference ID => target reference ID */
    public array $referenceMap = [],
) {}
```

Catalog reader returns the immutable definition snapshot containing type/validation/schema, nonlocalized and localized defaults, labels/properties, and source revision/hash. It exposes neither other grants nor owner values. Define `MetafieldDefinitionCatalogReader::find(string $grantId): MetafieldDefinitionCatalogSnapshot` using the existing display DTO inside an added internal snapshot wrapper `F/src/Data/MetafieldDefinitionCatalogSnapshot.php`. That wrapper has scalar grant/source IDs/revisions/hash plus `MetafieldDefinitionPayload $definition`; it never holds an Eloquent model. Define importer `persist(MetafieldDefinitionCatalogSnapshot $source, ImportPlatformMetafieldDefinitionData $data): MetafieldDefinition` to use existing Writer and AssignmentSyncer within the Action transaction.

Construct that payload from the explicitly authorized scalar source snapshot, not `MetafieldDefinitionPayload::fromModel($platformModel)` in tenant context: the latter calls ordinary tenant-guarded display/default accessors. Fetch only the granted platform definition and its exact child rows with explicit ownership predicates, resolve copied display fields deterministically from the configured locale chain in memory, and preserve the full locale map for the target write. The grant capability authorizes this narrow reader and does not change the normal query/translation boundary.

- [ ] Add this failing acceptance case:

```php
it('imports a definition without retaining a live platform dependency', function (): void {
    $s = MetafieldTenancyScenario::install(catalogCopies: true);
    $source = $s->platformDefinition();
    $grant = $s->platform(fn () => app(GrantMetafieldDefinitionToTenantAction::class)
        ->execute($source->id, new TenantId($s::A), $source->revision));
    $data = new ImportPlatformMetafieldDefinitionData(
        $grant->id, $grant->revision, $source->revision, (string) Str::uuid(),
        'catalog', 'local-color', new AssignMetafieldDefinitionPayload('test-owner', 'general'),
    );
    $copy = $s->run($s::A, fn () => app(ImportPlatformMetafieldDefinitionAction::class)->execute($data));
    $s->platform(fn () => app(RevokeMetafieldDefinitionTenantGrantAction::class)->execute($grant->id, $grant->revision));
    $owner = $s->owner($s::A);

    $value = $s->run($s::A, fn () => app(SetMetafieldAction::class)->execute($owner, 'catalog.local-color', 'blue'));

    expect($copy->id)->not->toBe($source->id)->and($value->definition_id)->toBe($copy->id);
});
```

- [ ] Run new test with Task 6's runner prefix; expected red until import creates an independent local definition.
- [ ] Lock grant, source definition and its definition-owned children inside one transaction. All platform source modifications that affect the snapshot (assignment, translations, validation/defaults/type) increment source revision under the root lock. Recheck grant enabled, exact revisions, target tenant active, idempotency request fingerprint, and target handle availability. Copy labels/schema, create **target** assignment from validated input, and never mutate the source to add a tenant owner type.
- [ ] Explicit reference defaults require a total `referenceMap`: normalize source IDs, require every referenced source ID be mapped exactly once, reject extra or absent keys, canonical-resolve each target in the recipient tenant and expected model alias, and write only target IDs. No reference default means an empty map. For ReferenceList preserve source list order after mapping; never copy source platform IDs. Validate localized/nonlocalized typed values using the normal package validator; translated reference types remain rejected.
- [ ] Catalog import failures preserve target database state atomically; no partly created definition/assignments/translations. Handle collision returns a conflict and asks the caller to select an explicit target handle, never auto-overrides. Revocation does not block writes/reads of committed local copies. Add revoke/import and source-edit/import separate-process races, payload replay mismatch, source delete, and stale revision tests. Commit: `feat(metafields): copy granted platform definitions into tenants`.

## Task 8: Keep taxonomy trees and owner attachments tenant-local

**Create:** `T/src/Tenancy/TaxonomyTenancyResources.php`, `TaxonomyAdoptionAdapter.php`; two schema files; `T/tests/Tenancy/TaxonomyTenancyTest.php`, `TaxonomyTenancySchemaTest.php`, `TaxonomyTenancyConcurrencyTest.php`; fixture above.

**Modify:** `T/composer.json`; `T/src/Models/{Term,TermTranslation,Termable,TermablePivot}.php`; `T/src/Services/{TaxonomyOwnerRegistry,TermAttachmentWriter,TermResolver,TermModelResolver,TermHierarchy,TermMergeValidator,TaxonomyTree,TermWriter,TaxonomyDoctor}.php`; `T/src/Actions/{AttachTermsAction,CreateTermAction,DeleteTermAction,DetachTermsAction,MergeTermsAction,MoveTermAction,RebuildTreeAction,ResolveTermsAction,SyncTermAttachmentsAction,UpdateTermAction,ValidateTermMergeAction}.php`; `T/src/Concerns/HasTaxonomies.php`; `T/src/Support/TaxonomyConfiguration.php`; `T/src/Providers/TaxonomyServiceProvider.php`; `T/src/Commands/{MergeTermsCommand,PruneOrphansCommand,RebuildTreeCommand}.php`; suite catalog/contracts.

**Produces:** `taxonomy.terms`, `taxonomy.attachments`, `taxonomy.translations` ownership keys. Vocabulary `TaxonomyDefinition` remains immutable configuration. Add `TaxonomyOwnerRegistry::resolve(Model $owner,bool $lock=false): Model` using the foundation owner declaration; no tenant encoded into taxonomy names.

- [ ] Write red tree/owner proof:

```php
it('allows the same slug in two tenants but never attaches a foreign term', function (): void {
    $s = TaxonomyTenancyScenario::install();
    $a = $s->term($s::A);
    $b = $s->term($s::B);
    $owner = $s->owner($s::A);

    expect($a->id)->not->toBe($b->id);
    expect(fn () => $s->run($s::A, fn () => app(AttachTermsAction::class)
        ->execute($owner, 'tag', [$b])))->toThrow(TenantBoundaryViolation::class);
});
```

- [ ] Run:

```bash
vendor/bin/pest --test-directory=packages/nvl/taxonomy/tests --configuration=packages/nvl/taxonomy/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/taxonomy/tests/Tenancy/TaxonomyTenancyTest.php
```

Expected red: global sibling uniqueness or foreign attachment succeeds.
- [ ] Backfill configured table names/effective connection; derive term ownership from reviewed mappings. A term attached to owners in two tenants requires a copy of the relevant ancestor/descendant graph per target tenant and attachment rewrite. Preserve canonical slugs/locale rows/positions; report collisions and unmapped unattached terms. No platform-tree attachment shortcut.
- [ ] Update parent/child and termable composite constraints as defined. Roots use tenant/taxonomy/`__root__` identity. Canonical locators reload passed Term objects under the active resource before reading taxonomy or revision. Create/update/move/merge/delete/rebuild validate tenant before taking hierarchy snapshots. Parent/source/destination/reparent target must all match tenant and taxonomy. Lock ordering stays stable by tenant, vocabulary, ID.
- [ ] Raw `TermAttachmentWriter::ownerQuery()` includes explicit tenant; inserted rows carry canonical owner tenant. `MergeTermsAction` raw duplicate/transfer/update/delete queries include tenant and verify owner compatibility. HasTaxonomies owner-deletion events validate canonical ownership before deleting pivots. `hasTerm` validates loaded relations; `withAnyTerms/withAllTerms/withoutTerms/inCategory` preserve owner and term boundary in OR/NOT EXISTS subqueries.
- [ ] `TaxonomyConfiguration::attachmentLockName()` delegates tuple identity to `TenantBoundary::key`; prune/rebuild process locks include tenant/vocabulary. CLI uses explicit tenant or audited bounded loop. A prune query must not conclude a term is orphaned because it ignored another ownership context; cross-tenant attachments are rejected by adoption and constraints.
- [ ] Change `TaxonomyServiceProvider`'s `SlugGenerator` binding from singleton to scoped because it captures `ContentLocale`. Immutable registries stay singleton and must never cache per-tenant terms, actors or query builders. Term translation declaration links `taxonomy.terms` to the Translatable boundary.
- [ ] Add tests for same parent/slug across A/B, forged dirty parent, cross-tenant move/merge/reparent, exclusive attachment, prune, owner force deletion, configured table/connection aliases, retained loaded relation, B locale contamination, and concurrent same-root creation/moves. Run existing `TaxonomyTest.php` and `TaxonomyConsumerContractsTest.php`, schema matrix and two-process race. Commit: `feat(taxonomy): isolate tenant trees and attachments`.

## Task 9: Prove composition, migration recovery and package independence

**Create:** `tests/Feature/Integration/TenantResourceCompositionTest.php`; `tests/Feature/Integration/TenantResourceAdoptionTest.php`.

**Modify:** each package's README/UPGRADING/CHANGELOG and `resources/boost/skills/nvl-{media,metafields,taxonomy}/SKILL.md`; mirrored suite skills through `composer skills:sync`; `tools/package-contracts.json`; suite Doctor/consumer audit registration; existing archive/consumer fixtures and `.github/workflows/package-quality.yml` gates as appropriate.

- [ ] Composition fixture model uses existing `HasMedia`, `InteractsWithMedia`, `HasMetafields`, and `HasTaxonomies` with a registered direct tenant owner. In A create a Media asset, definition+value, term and translated copy; repeat identical business keys in B. Prove list/detail/eager relations and translated content show A only; try each B model/ID through A Actions and assert rollback. Retain the fully eager-loaded A model, switch to B, and prove every sanctioned trait/API denies use. The Auth-plan integration adds one global user with distinct A/B roles and membership revocation; package tests continue without Auth installed.
- [ ] Rehearse upgrade after old migrations are already applied: disabled consumer→reviewed mapping→expand→backfill→constraint verification→activate/restart. Include a legacy Media row attached across A/B, shared Metafield definition, taxonomy ancestor graph, soft-deleted owner, copied-vendor migrations, configured taxonomy names, and non-default connection alias pointing to the same actual connection. Intentionally introduce one cross-owner mismatch per adapter, assert activation is denied, repair the reviewed mapping, resume from checkpoint, and verify counts/checksums.
- [ ] Prove prepared state denies ordinary work; disabled flag or omitted suite feature after activation does not expose adopted rows. A standalone Composer consumer installing Media/Metafields/Taxonomy loads inert Tenancy, never Auth. `config:cache` and `route:cache` succeed before and after adoption; missing configured owner/adapter fails before serving enabled routes. No new Composer package is fetched for this change.
- [ ] Recovery drill uses pre-cutover backup plus deterministic mapping to restore or forward-repair after interrupted file copying. Do not claim dropping tenant columns is rollback after duplicate handles/slugs exist. Tenant cleanup runs one package-owned bounded graph at a time, retaining grant/audit/provenance policy; physical asset cleanup cannot delete another tenant's imported copy.
- [ ] Run the new integration files, focused package suites, formatter and package analysis, `composer packages:validate`, `composer contracts:check`, and existing consumer/archive gates. PostgreSQL/Redis/S3 real worker and race tests plus supported MySQL/MariaDB schema matrix are release gates; SQLite/sync results alone are insufficient. Preserve query-count budgets and inspect tenant-leading query plans with representative rows.
- [ ] Commit integration/docs independently: `test(tenancy): verify resource composition and adoption`.

## Completion gate

- [ ] All three families have explicit resource declarations and adoption markers; every stateful child is part of the closure.
- [ ] No cross-tenant row, object, loaded relationship, key, grant, default reference or worker state survives the denial matrix.
- [ ] Grants mean recipient availability for copy, tenant ownership remains direct, and revocation/import race outcomes match the documented linearization rule.
- [ ] Old disabled installs and independent consumers remain supported with no automatically created tenant schema.
- [ ] Every test command, real-service result, count and skipped gate is recorded in the implementation review. These plans do not assert implementation or test completion.
