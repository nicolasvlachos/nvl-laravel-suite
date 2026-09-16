# Tenancy Content and Sites Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Isolate Content, Pages, and SEO while publishing a complete tenant page through one verified tenant/site boundary.

**Architecture:** Existing public Actions remain the application API; their queries and canonical owner locators use `Nvl\Tenancy`. Code-backed schemas remain shared immutable catalogs. Tenant identity is separate from Content scopes, Page sites, SEO scopes, and locale; publication, URLs, sitemap artifacts, and dependent Media/Metafields/Taxonomy records preserve the tenant of the canonical owner.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4, Eloquent, existing Redis/cache locks and Laravel filesystems.

**Spec:** [Execution contracts](../specs/2026-09-16-tenancy-execution-contracts.md) and [suite design](../specs/2026-09-16-configurable-tenancy-design.md).

## Global Constraints

- PHP 8.4 and Laravel 13 are the execution baseline.
- No new external Composer dependency is required.
- All tenancy types and runtime logic live in `Nvl\Tenancy`, including the disabled implementation. Support remains free of tenant domain logic.
- Missing context in enabled mode fails closed. Platform access, central identity use cases, and tenant operations are explicit and separate.
- Released migrations are immutable.
- Installing integrated packages includes the inert library; it does not enable tenant migrations or adopt existing rows.
- Existing public Actions retain their normal signatures where possible. Tenant IDs never enter ordinary client mutation DTOs.
- This is a planning artifact. No runtime implementation, migration execution, dependency change, test run, or commit belongs to this planning turn.

## Entry gate and execution conventions

Execute after foundation/adoption/context contracts, Translatable ownership, Auth or host admission, tenant Media, and tenant Metafields are ready. Taxonomy is a prerequisite only for a configured owner/reference capability that uses it. Execute the early Activity and Settings/Filterable/Data prerequisites from [workflows](2026-09-16-tenancy-workflows.md) and [settings/tools](2026-09-16-tenancy-settings-tools.md) before tenant data begins flowing.

Load backend architecture, domain package canonical skills, Laravel, migrations, Pest and testing-practices skills. Run Boost `search-docs` before implementation. All test code below uses actual existing package Actions or exact new interfaces defined in its task. Create local `tests/TenancyTestCase.php` and `tests/Fixtures/TenantScenario.php` in Content, Pages and SEO using the complete code in [Workflow concrete test setup](2026-09-16-tenancy-workflows.md#concrete-test-setup-shared-by-these-plans). Copy each package's existing `tests/TestCase.php` provider/environment/schema setup, insert TenancyServiceProvider, retain `DatabaseMigrations` directly on Orchestra Testbench, and set `tenancy.resources` to the corresponding family. Bind TenantScenario's directory/platform/maintenance adapters in `defineEnvironment()`, load core migrations from the displayed provider ReflectionClass path, and call `activate(['content'])`, `['pages']`, or `['seo']` after ordinary schemas exist. Coordinator derives real dependency closure; no hand-created active marker or imported development harness is allowed. Route `tests/Tenancy` to these local cases. Pages HTTP setup additionally copies its existing `HttpTestCase.php` routes/resolver bindings. Existing `RefreshDatabase` suites keep their current cases; an outer transaction is intentionally incompatible with tenant switching.

Repository-root package test invocation:

```bash
vendor/bin/pest --test-directory=packages/nvl/content/tests --configuration=packages/nvl/content/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/content/tests/Tenancy/TenantContentTest.php
```

Replace package and exact test filename for the named task. Red means a missing ownership behavior; missing providers/fixtures are setup errors to resolve first. Runtime implementation steps use strict types, class/method PHPDoc, imported names, and package conventions. Finish each task with relevant docs/Doctor changes, narrow green tests, then a reviewable commit.

## Resource registrations and schema ownership

| Key | Family / kind | Owner policy and uniqueness |
|---|---|---|
| `content.definitions` | content / Platform | Immutable code-backed schema catalog; platform-only synchronization; tenant editor can read approved code definitions through the existing definitions boundary |
| `content.blocks` | content / Root | Tenant plus existing `scope,scope_key,key`; `global:*` means reusable within the tenant |
| `content.placements` | content / Inherited | Canonical Content owner; block, owner, parent, group and Media usage tenant must match |
| `content.revisions` | content / Inherited | `content.blocks` through `block`; tenant/block/revision indexes |
| `content.translations` | content / Inherited | `content.blocks` through `block`; Translatable registration owns locale behavior |
| `pages.pages` | pages / Root | Tenant/key and tenant/site/path; parent/site/tenant compatibility |
| `pages.translations` | pages / Inherited | `pages.pages` through `page`; locale uniqueness per page |
| `seo.profiles` | seo / Inherited | Canonical registered owner and existing SEO scope |
| `seo.translations` | seo / Inherited | `seo.profiles` through `profile`; tenant/scope/locale/path uniqueness |
| `seo.redirects` | seo / Root | Tenant plus existing scope/locale/source identity |

Schema locators use configured table names and normalized effective connections. Independently queryable children persist tenant IDs; concrete same-connection links enforce composite ownership equality. Polymorphic owners are reloaded through registries and `TenantResourceRegistry::forModel()`; unknown owners fail. Lock tables and artifact namespaces carry ownership even though they are not Eloquent resources exposed to consumers.

Use one package registrar and adoption adapter per package:

- `packages/nvl/content/src/Tenancy/ContentResourceRegistrar.php`, `ContentAdoptionAdapter.php`.
- `packages/nvl/pages/src/Tenancy/PagesResourceRegistrar.php`, `PagesAdoptionAdapter.php`.
- `packages/nvl/seo/src/Tenancy/SeoResourceRegistrar.php`, `SeoAdoptionAdapter.php`.

Each registrar exposes `register(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoption): void`. Each adapter implements the frozen `TenantAdoptionAdapter`, with package-owned forward migrations under `database/tenancy-migrations/`. Register inherited resources using actual relationship names read from their models. Dynamic polymorphic owners omit a fixed parent resource and use the package canonical owner registry plus the core model registry; do not invent a universal polymorphic SQL foreign key.

### Task 1: Register Content ownership and keep definition synchronization platform-owned

**Files:** Modify `packages/nvl/content/src/Providers/ContentServiceProvider.php`, `packages/nvl/content/src/Models/ContentBlock.php`, `packages/nvl/content/src/Models/ContentDefinition.php`, `packages/nvl/content/src/Actions/CreateContentBlockAction.php`, `packages/nvl/content/src/Actions/SyncContentDefinitionsAction.php`, `packages/nvl/content/src/Actions/ListContentDefinitionsAction.php`; create the Content registrar/adapter above, `packages/nvl/content/database/tenancy-migrations/2026_09_16_170001_add_content_ownership.php`, `packages/nvl/content/tests/Tenancy/TenantContentTest.php`.

**Interfaces:** Consume `TenantBoundary::{query,attributes,assertRecord,key}` and resource registration. Preserve `CreateContentBlockAction::execute(CreateContentBlockData, ContentActorData): ContentBlock` and source definition registry keys. Produce tenant-qualified blocks while code-defined schemas remain read-only in tenant operations. Code catalog reads are narrow vocabulary APIs, not general platform context.

- [ ] Write a failing two-tenant Action test with the existing `hero` definition and supported `site/default` scope. Synchronize that code definition once through explicit platform execution before creating tenant blocks.

```php
use Nvl\Content\Actions\CreateContentBlockAction;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;

test('identical content scope keys belong to different tenants', function (): void {
    $runner = app(TenantRunner::class);
    $payload = new CreateContentBlockData(
        definition: 'hero', key: 'home-hero', scope: 'site', scopeKey: 'default',
        translations: ['en' => ['title' => 'Welcome']],
    );
    $create = fn () => app(CreateContentBlockAction::class)
        ->execute($payload, ContentActorData::system());
    $a = $runner->run(new TenantId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'), $create);
    $b = $runner->run(new TenantId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'), $create);
    expect($a->id)->not->toBe($b->id)
        ->and($a->tenant_id)->not->toBe($b->tenant_id);
});
```

Add an explicit platform catalog test: tenant `Content::definitions()` returns the approved code schema, but tenant `syncDefinitions()` is denied and cannot orphan definitions. No tenant grant table is added for Content schemas; executable definitions remain deployment-owned. Include unknown definition, disabled install, and injected ownership DTO aliases.

- [ ] Run the focused Content test. Expected red: global unique index rejects the second block, or tenant ownership is absent.
- [ ] Add the optional migration and register all Content rows. Preserve platform definition identity and FK targets. Add tenant-leading block indexes; replace global block natural-key uniqueness only after backfill validation. Use server attributes after DTO projection, not client fields:

```php
$attributes = $data->toModelFiltered();
unset($attributes['tenant_id'], $attributes['ownership_key']);
$attributes = array_replace($attributes, $this->tenancy->attributes('content.blocks'));
```

Domain field mapping remains the existing writer's responsibility; this fragment shows ownership overlay order only. Apply query predicates before custom authorization scopes, filters, aggregates, and key resolution. Preserve default content scopes as business scopes; never encode tenant IDs into them. Add platform-only admission to definition sync and definition migration planning. Applying a schema migration to tenant blocks executes through bounded tenant runs, with per-tenant progress and existing placement validation; source registry synchronization itself never scans tenant rows implicitly.

- [ ] Run `TenantContentTest.php`, `ContentDefinitionMigrationTest.php`, and `ContentBoundaryContractTest.php`; verify duplicate keys now work and unadopted disabled behavior remains compatible.
- [ ] Commit `feat(content): isolate content ownership and definition operations` with schema/adoption/Doctor and contract updates.

### Task 2: Close Content composition, snapshots, and owner lifecycle boundaries

**Files:** Modify `packages/nvl/content/src/Services/ContentOwnerRegistry.php`, `ContentSnapshotService.php`, `ContentPlacementTree.php`, `ContentMediaReferences.php`, `ContentRenderResources.php`, `ContentPlacementOwnerLock.php`, `packages/nvl/content/src/Data/ContentCompositionSnapshotData.php`, `packages/nvl/content/src/Casts/ContentCompositionSnapshotCast.php`, `packages/nvl/content/src/Support/ContentOwnerDeletionBridge.php`; update all existing block/placement mutation Actions; create `packages/nvl/content/tests/Tenancy/TenantContentCompositionTest.php`.

**Interfaces:** Preserve the public `Content` methods. Add nullable server-only `tenantId` and snapshot `formatVersion` to `ContentCompositionSnapshotData`, defaulting to legacy values only in disabled mode. Format 2 includes tenant ownership in the canonical hash. A tenant runtime cannot render an unadopted format-1 snapshot. Snapshot adoption maps its canonical owner and rehashes under the reviewed mapping, with a complete version/provenance record.

- [ ] Write failing tests through `Content::place`, `Content::capture`, and `Content::renderSnapshot`: an A owner rejects B block, B parent placement, B Media reference/override, and B reference-resolver result; passing preloaded B owner/model or forged dirty ownership does not help. Capture a valid snapshot in A, render it in B and unresolved mode, and require denial before template view/asset reads.

For the denial assertion, use the real public snapshot API:

```php
use Nvl\Content\Content;
use Nvl\Content\Data\ContentActorData;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

expect(fn () => app(Content::class)->renderSnapshot($snapshotFromA, 'en', ContentActorData::system()))
    ->toThrow(TenantBoundaryViolation::class);
```

Define `$snapshotFromA` by the existing Action/Content setup in this test file: create a registered tenant owner, create/publish a tenant block, place it in its declared group, then call `Content::capture`. Do not hand-author arbitrary snapshot arrays to simulate a successful capture.

- [ ] Run the composition test; expected red is acceptance/disclosure of a foreign graph or missing tenant snapshot identity.
- [ ] Canonically reload every supplied owner/block/placement before use, preserving soft-delete policy. Assert the canonical owner through its registered resource. Derive child ownership from it, compare block/placement/parent ownership, then perform normal Content authorization. Keep locks scoped to tenant/owner/group, and keep existing transaction ownership and same-connection guarantees.

```text
canonical owner -> assert owner resource -> owner/group lock
canonical block + parent -> tenant equality -> existing schema/visibility/revision checks
write placement + translations/usage changes atomically
capture snapshot format=2, tenant_id, canonical owner identity, blocks -> hash
render: validate format/hash -> tenant equality -> canonical owner -> resolve every external reference
```

Media references use Media-owned APIs and tenant authorization; preloading Media by IDs does not make them authorized. Default/reference resolution performed at definition boot remains structural only; live tenant resolution occurs at use. Owner force-delete, restore, retained placements, bulk reorder and definition migration must all preserve equality and rollback when dependent cleanup fails. Template catalog copy uses the dedicated source-copy API defined in the workflows plan, never `renderSnapshot` with broader scopes.

- [ ] Run composition/publication/media placement regressions, snapshot cast tests, and actual PostgreSQL competing placement/owner deletion tests. Expected green: no foreign graph, stale snapshot, or orphaned media usage; existing query-count budgets remain.
- [ ] Commit `feat(content): enforce tenant composition and snapshot boundaries`.

### Task 3: Add Page tenant trees, exact key lookup, and handler ownership

**Files:** Modify `packages/nvl/pages/src/Models/Page.php`, `src/Models/PageTranslation.php`, `src/Actions/CreatePageAction.php`, `src/Actions/MovePageAction.php`, `src/Actions/RestorePageAction.php`, `src/Actions/FindPageByKeyAction.php`, `src/Actions/CheckPageKeyAvailabilityAction.php`, `src/Actions/ListPageEditorSummariesAction.php`, `src/Actions/GetPageEditorBootstrapAction.php`, `src/Services/PageResourceRegistry.php`; create the Pages registrar/adapter, `packages/nvl/pages/database/tenancy-migrations/2026_09_16_170002_add_pages_ownership.php`, `packages/nvl/pages/tests/Tenancy/TenantPagesTest.php`.

**Interfaces:** Preserve Page Action signatures. Tenant key uniqueness replaces today's global Page key uniqueness; site-qualified lookup still does not reveal other sites in the same tenant. `PageResourceHandler` remains the registered dynamic resource contract, and its returned model is checked through `TenantResourceRegistry::forModel()` before rendering its sanitized DTO. Unknown resource ownership is a configuration failure.

- [ ] Write this failing test through real Actions in the adopted two-tenant fixture:

```php
use Nvl\Pages\Actions\CreatePageAction;
use Nvl\Pages\Actions\FindPageByKeyAction;
use Nvl\Pages\Data\Mutations\CreatePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;

test('page key lookup is tenant and site local', function (): void {
    $runner = app(TenantRunner::class);
    $a = new TenantId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $b = new TenantId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
    $create = fn () => app(CreatePageAction::class)->execute(
        new CreatePageData(key: 'home', slug: 'home', site: 'default', translations: ['en' => ['title' => 'Home']]),
        PageActorData::system(),
    );
    $pageA = $runner->run($a, $create);
    $pageB = $runner->run($b, $create);
    $found = $runner->run($a, fn () => app(FindPageByKeyAction::class)
        ->execute('default', 'home', PageActorData::system()));
    expect($found->id)->toBe($pageA->id)->not->toBe($pageB->id);
});
```

- [ ] Run `TenantPagesTest.php`; expected red: duplicate global key/path or wrong ownership projection.
- [ ] Add `(tenant_id,key)`, `(tenant_id,site,parent_key,slug)`, and `(tenant_id,site,path_hash)` uniqueness plus concrete parent/translation ownership constraints. Extend the page-tree lock identity from site to tenant/site. Validate complete subtrees on move/restore; do not mutate another tenant's path/redirects. Rework availability/exceptId queries to remain tenant/site constrained.

Require tenant-safe dynamic handlers at registration/Doctor time and validate returned owners even if the host policy returns true. Handler queries must constrain before pagination/count/selection; a final per-model check does not fix aggregate disclosure. If the host cannot declare a safe scoped handler, its resource Page is unavailable in tenant mode. Batched editor composition validates all owner identities before cross-package SQL, retains the 100-owner cap, and preserves fixed-query behavior. No Page service writes Content/SEO/Metafield tables directly.

- [ ] Run Page tree, option, key, editor and HTTP tests plus competing same-slug moves on PostgreSQL. Include disabled cross-package contracts and suspended/missing tenant denial.
- [ ] Commit `feat(pages): isolate page trees and resource composition`.

### Task 4: Use one verified public tenant/site context for Pages and SEO

**Files:** Modify `packages/nvl/pages/src/Services/ConfiguredPageRequestContextResolver.php`, `ConfiguredPageUrlGenerator.php`, `packages/nvl/pages/src/Contracts/PageRequestContextResolver.php`, `packages/nvl/pages/src/Data/PageRequestContextData.php`, `packages/nvl/pages/src/Providers/PagesServiceProvider.php`, `packages/nvl/seo/src/Services/AbsoluteUrl.php`, `SeoRouteResolver.php`, `SitemapLocationPolicy.php`, `packages/nvl/seo/src/Support/SeoScope.php`; create `packages/nvl/pages/tests/Tenancy/TenantPageContextTest.php`, `packages/nvl/seo/tests/Tenancy/TenantSiteIdentityTest.php`.

**Interfaces:** Consume the frozen `TenantSiteResolver::resolve(Request): TenantSiteContext`. Keep existing site/locale Page context arguments and add an optional server-only `TenantSiteContext $tenantSite` constructor parameter for adopted mode. The public HTTP boundary resolves trusted tenant/site before package binding, enters `TenantRunner::run`, then resolves/validates locale. Outside HTTP, callers supply a verified site context from their host site adapter and active tenant; no global configuration mutation establishes site identity.

- [ ] Add thin HTTP tests with an explicit test resolver implementing the real contract:

```php
use Illuminate\Http\Request;
use Nvl\Tenancy\Contracts\TenantSiteResolver;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;

final class TestSites implements TenantSiteResolver
{
    public function resolve(Request $request): TenantSiteContext
    {
        return match ($request->getHost()) {
            'a.example.test' => new TenantSiteContext(new TenantId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'), 'default', 'https://a.example.test'),
            'b.example.test' => new TenantSiteContext(new TenantId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'), 'default', 'https://b.example.test'),
            default => throw new TenantNotFound('Unknown public site.'),
        };
    }
}
```

Test real Page routes using A/B hosts with identical site/path and a forged tenant header, site query and browser Origin. Host proxy normalization belongs to the host resolver/foundation trusted-proxy contract. Confirm unknown host and foreign resource return the same 404, locale remains supported/validated, and `config()->all()` is unchanged after A→B→failure. Bind the test resolver before boot in the HTTP fixture; do not allow arbitrary request-supplied class names.

- [ ] Run the new Page HTTP/SEO identity tests; expected red: default global site or base URL contaminates output.
- [ ] Adapt default resolvers/URL generators to require verified `TenantSiteContext` when the family is adopted. Enforce context tenant equality and site validity; use `canonicalOrigin` for canonical/alternate and sitemap URLs. A tenant admin's site string selects an allowlisted site within that tenant only. Global defaults remain the disabled behavior.

```text
trusted host -> TenantSiteResolver -> active tenant admission
TenantRunner.run -> locale validation -> package binding -> public publication check
canonical URLs = verified canonicalOrigin + normalized tenant/site-local path
finally restore tenant, locale, site-scoped services; never Config::set per tenant
```

- [ ] Run A/B public Page journeys plus same-worker sequential requests and route/config cached boots. Expected green: correct hosts/content and clean context after every exit.
- [ ] Commit `feat(pages): resolve public tenant and site before content binding`.

### Task 5: Partition SEO profiles, redirects, sitemap manifests, and invalidation

**Files:** Modify `packages/nvl/seo/src/Services/SeoOwnerRegistry.php`, `SeoRedirectLookup.php`, `SeoRedirectChain.php`, `SeoPathConflictResolver.php`, `SitemapCache.php`, `SitemapGenerator.php`, `FilesystemSitemapArtifactStore.php`, `SitemapRegistry.php`, `EloquentSeoSitemapSource.php`; modify `packages/nvl/pages/src/Seo/PageSitemapSource.php`, `packages/nvl/pages/src/Listeners/InvalidatePageSitemap.php`; create Seo registrar/adapter, `packages/nvl/seo/database/tenancy-migrations/2026_09_16_170003_add_seo_ownership.php`, `packages/nvl/seo/tests/Tenancy/TenantSeoTest.php`.

**Interfaces:** Preserve public sitemap source `entries(string $scope): iterable`; active tenant/site context is mandatory around generator iteration. `SitemapCache::key(string $scope): string` and `namespace(string $scope): string` preserve signatures but include tenant, verified origin, scope and build version. Add internal immutable `SitemapCacheIdentity` under `src/Data/` with key/namespace/tenant/site facts, plus `capture(string $scope): SitemapCacheIdentity` and `forgetCaptured(SitemapCacheIdentity $identity): bool`; events/after-commit invalidation use the captured value.

- [ ] Add failing tests using existing public SEO Actions and sitemap generator: A and B can use identical scope/locale/source path; A's locale-neutral redirect fallback never considers B; graph cycles are checked within A; wrong owner and wrong image are rejected. Assert cache key independence through the existing service:

```php
use Nvl\Seo\Services\SitemapCache;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;

$runner = app(TenantRunner::class);
$keyA = $runner->run(new TenantId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'), fn () => app(SitemapCache::class)->key('default'));
$keyB = $runner->run(new TenantId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'), fn () => app(SitemapCache::class)->key('default'));
expect($keyA)->not->toBe($keyB);
```

The fixture installs verified A/B site contexts before each generator run; directory identity alone must not supply an invented origin.

- [ ] Run `TenantSeoTest.php`; expected red: shared cache identity/global natural key or unscoped sitemap source.
- [ ] Add tenant profile/redirect/translation columns and indexes. Recompute redirect source identity and graph lock identity with tenant without changing locale-neutral semantics. Adopt existing source hashes under a reviewed mapping before replacing uniqueness. Tenant context is mandatory for every graph edge lookup, conflict check, bulk import, retained-row restore, prune and owner hydration.

The current singleton `SitemapRegistry` retains source objects. Add `registerType(string $sourceClass, ?string $key = null, array $ownerTypes = []): self`; retain only immutable class/key/owner declarations and resolve each source under the current tenant during `all()`. Existing object-based `register()` remains supported for disabled consumers, but tenant Doctor rejects its use for tenant sources. Fresh source instances must not survive A→B in a long-running worker. Add that explicit registry regression.

Namespace sitemap locks/manifests/artifacts by canonical `(connection,tenant,site,origin,version)`. Keep manifests small and XML on private durable disk. Cache invalidation captures namespace/identity at mutation time; no ambient context lookup in after-commit callbacks. A custom sitemap source must be registered with a tenant-safe owner capability and yields only active context entries. Keep same-origin/path validation on every generated entry, including external custom sources; skip external canonicals as existing behavior specifies.

Commands require a tenant/site or an explicitly authorized bounded tenant/site loop. Public sitemap/chunk routes resolve the same trusted host mapping before cache lookup, reject arbitrary scopes, and preserve site scope in generated chunk links. Test ETags and content after A invalidation: B's manifest remains valid. Cleanup deletes only captured obsolete namespaces and never another tenant's current build.

- [ ] Run SEO hardening/consumer/sitemap tests, Page sitemap tests, real Redis simultaneous build/invalidation cases, and actual PostgreSQL redirect mutation races. Expected green: tenant-local artifacts and loops, unchanged disabled output, and no global Config changes.
- [ ] Commit `feat(seo): isolate redirect graphs and sitemap artifacts`.

### Task 6: Rehearse adoption and the complete tenant publication graph

**Files:** Content/Pages/SEO registrars/adapters, package Doctor services, package README/UPGRADING/CHANGELOG/canonical skills, `tools/package-contracts.json`, suite configuration diagnostics and consumer audit fixtures; create `tests/Feature/Integration/TenantPagePublicationTest.php` and each package's `tests/Tenancy/TenantAdoptionTest.php`.

**Interfaces:** Adapters implement exact core `TenantAdoptionAdapter` methods. Root mapping covers independent Content blocks, Pages and SEO redirects; inherited rows derive from canonical owners. Definition catalogs remain platform-owned. Unmapped custom owners, cross-tenant existing placements, conflicting path keys, snapshots without canonical owner and incompatible connections stop verification with bounded identifiers.

- [ ] Write failing integration/adoption cases: create A/B identical keys, publish A Page with Content image override, Metafields, optional Taxonomy, locales, SEO redirect and sitemap; B IDs injected at each step fail atomically. Rehearse fresh install and current schema upgrade with reviewed mapping, legacy snapshot conversion, corruption reports and resumption. Use real public Actions and actual files, not hand-built final state.
- [ ] Run the focused integration test and new adoption tests. Expected red: unfinished ownership graph or unmapped adoption; ordinary disabled fixtures still pass.
- [ ] Implement bounded package backfills/checkpoints, constraint replacement ordering, artifact/cache cutover, immutable provenance, and schema-owner checks. Require maintenance for affected writes, version/drain old jobs, revise old links, restart workers. Record counts/checksums before/after and reject flag-disable or feature omission after activation.
- [ ] Run all three affected package suites, `vendor/bin/pint --dirty --format agent`, their PHPStan checks, suite package contracts, generated DTO/TypeScript contracts, and clean/archive consumer tests. Run migrations/constraints on SQLite, PostgreSQL, MySQL/MariaDB and concurrency on real connections. Run a real sequential request worker and Redis/filesystem fixtures. Existing query budgets exclude only the separately measured one-time adoption probe.
- [ ] Commit `test(tenancy): prove content site adoption and publication isolation`. Do not label the family tenant-ready before this full graph passes.

## Completion evidence

Record test commands/results, database/cache/storage profiles, disabled compatibility, adopted-marker guard, query-budget change, two-tenant publication proof, and migration reconciliation. Resource readiness is an explicit per-family release state; merely installing an inert provider is not readiness. All package docs and canonical skills must describe the exact public context/copy policy and preserve their current non-tenant APIs.
