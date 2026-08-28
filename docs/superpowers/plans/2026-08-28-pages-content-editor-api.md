# Pages and Content Editor API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give consumers complete, typed page/editor/public projections without direct Pages, Content, SEO, or Metafields queries.

**Architecture:** Content's existing `Content::editor()` contract is preserved and extracted behind a focused Action. Content owns placement-tree reads and mutations; Pages composes Page, Content, SEO, and Metafields DTOs because Page already declares those package dependencies.

**Tech Stack:** PHP 8.4, Laravel 13 Eloquent/transactions/cache locks, Spatie Laravel Data, TypeScript Transformer, Pest 4.

**Spec:** `docs/superpowers/specs/2026-08-28-consumer-readiness-v2-design.md`

## Global Constraints

- Existing `Content::editor()` and `PreviewPageAction` behavior remains compatible.
- Content placement mutations hold the existing owner/group atomic lock and one database transaction.
- Page management reads authorize before returning data; Content and SEO composition also passes through their package boundaries.
- Public child reads include only `publiclyVisible()` pages and return `PublicPageData`.
- Page option reads are capped at 100; public children are capped at 100; Content keeps its configured 1,000-placement hard ceiling.
- DTOs contain no Eloquent models, builders, lazy relations, closures, or application-specific copy.

---

### Task 1 (CR-13): Extract and harden the existing Content editor projection

**Files:**
- Create: `packages/nvl/content/src/Actions/GetOwnerContentEditorAction.php`
- Create: `packages/nvl/content/src/Actions/ListOwnerContentPlacementSummariesAction.php`
- Modify: `packages/nvl/content/src/Content.php`
- Modify: `packages/nvl/content/src/Data/ContentEditorData.php`
- Modify: `packages/nvl/content/src/Data/ContentPlacementData.php`
- Modify: `packages/nvl/content/src/Http/ContentResponseData.php`
- Modify: `packages/nvl/content/tests/Feature/ContentContractRegressionTest.php`
- Modify: `packages/nvl/content/README.md`

**Interfaces:**
- Consumes: existing definition, preset, group, placement Actions and `ContentOwnerRegistry`.
- Produces: `GetOwnerContentEditorAction::execute(Model&ContentOwner $owner, string $group, ContentActorData $actor): ContentEditorData`; existing `Content::editor()` delegates to it, and a bounded bulk placement projection supports editor indexes.

- [x] **Step 1: Extend the existing editor regression test before extraction**

```php
$editor = app(GetOwnerContentEditorAction::class)->execute(
    $owner,
    'homepage',
    $actor,
);

expect($editor->ownerType)->toBe('page')
    ->and($editor->placementLimit)->toBe(1000)
    ->and($editor->placements[0])->toBeInstanceOf(ContentPlacementData::class);
```

Keep the existing one-versus-twenty-five constant-query assertion and add exact
ordering for definitions, presets, groups, regions, sort order, and IDs. Add a
bulk-summary test for one and 25 owners with the same five-query count,
per-owner authorization, serialization-safe canonical owner-identity keys,
and a hard 100-entry limit.

- [x] **Step 2: Run the regression test and verify the missing Action/field fails**

Run: `vendor/bin/pest --configuration=packages/nvl/content/phpunit.xml.dist --compact packages/nvl/content/tests/Feature/ContentContractRegressionTest.php`

Expected: FAIL because `GetOwnerContentEditorAction` and `placementLimit` do not
exist.

- [x] **Step 3: Move the existing editor composition into the Action**

Inject `ListContentDefinitionsAction`, `ListContentPresetsAction`,
`ListContentGroupsAction`, `ListContentPlacementsAction`, and
`ContentOwnerRegistry`. Reproduce the existing DTO assembly exactly, append the
validated `content.placements.maximum_per_group` as `placementLimit`, and map
every placement through `ContentPlacementData::fromModel()`.

Delegate the composition body in `Content::editor()` to the injected Action,
while preserving direct construction through the original 22-argument service
constructor:

```php
return $this->getOwnerEditor->execute($owner, $group, $actor);
```

Keep the facade annotation and all existing callers unchanged.

Implement
`ListOwnerContentPlacementSummariesAction::execute(iterable $owners, string
$group, ContentActorData $actor): array` inside Content. Normalize/deduplicate
zero to 100 persisted `ContentOwner` entries, resolve canonical type/ID, authorize
`ListPlacements` for every owner before the bulk query, group queries by owner
morph type, eager-load only the block/definition/translation projection, and
return `array<string, list<ContentPlacementData>>` keyed by canonical
`<owner-type>:<owner-id>` identity. The optional placement block DTO exposes
the constrained block/definition/translation projection without changing
non-eager placement response shape. Empty input returns an empty array without
querying. The bulk seam remains an injected Action so the Content service does
not require a service-locator fallback or a breaking constructor dependency.

- [x] **Step 4: Document the canonical editor boundary**

Show dependency-injected Action and facade examples. State that
`Content::placements()` returns documented 1.x identity models for compatibility,
while new editor UIs use `Content::editor()` or the Action's DTO.

- [x] **Step 5: Run Content quality and generated-type checks**

Run: `php tools/run-package-quality.php content`

Expected: PASS with the previous query ceiling unchanged.

Run: `php artisan nvl:data:types:generate --fail-on-warning`

Run: `composer types:check`

Expected: PASS with `placementLimit` in the generated contract.

- [x] **Step 6: Commit CR-13** (`aa4c689`)

```bash
git add packages/nvl/content/src/Actions packages/nvl/content/src/Content.php packages/nvl/content/src/Data/ContentEditorData.php packages/nvl/content/src/Data/ContentPlacementData.php packages/nvl/content/src/Http/ContentResponseData.php packages/nvl/content/tests/Feature/ContentContractRegressionTest.php packages/nvl/content/README.md resources/js/types
git commit -m "refactor(content): extract editor projection action"
```

### Task 2 (CR-14): Add placement find, replace, and reorder workflows

**Files:**
- Create: `packages/nvl/content/src/Actions/FindContentPlacementAction.php`
- Create: `packages/nvl/content/src/Actions/FindContentBlockByKeyAction.php`
- Create: `packages/nvl/content/src/Actions/ReplaceContentPlacementAction.php`
- Create: `packages/nvl/content/src/Actions/ReorderContentPlacementsAction.php`
- Create: `packages/nvl/content/src/Data/Mutations/ReorderContentPlacementData.php`
- Create: `packages/nvl/content/src/Data/Mutations/ReorderContentPlacementsData.php`
- Create: `packages/nvl/content/src/Relations/ExactTextValueComparison.php`
- Modify: `packages/nvl/content/src/Services/ContentPlacementTree.php`
- Modify: `packages/nvl/content/tests/Feature/ContentContractRegressionTest.php`
- Modify: `packages/nvl/content/README.md`
- Modify: both mirrored `nvl-content` skills, readiness evidence, public
  contracts, and generated TypeScript declarations

**Interfaces:**
- Consumes: CR-13 editor DTO, `ContentPlacementOwnerLock`, `ContentPlacementValidator`, `ContentOwnerRegistry`, and revision exceptions.
- Produces: DTO-first block/placement find plus replacement/reorder APIs.

- [x] **Step 1: Write failing owner, revision, and transaction tests**

```php
$result = app(ReplaceContentPlacementAction::class)->execute(
    owner: $owner,
    group: 'homepage',
    placement: $placement->id,
    block: $replacement->id,
    expectedRevision: $placement->revision,
    actor: $actor,
);

expect($result->blockId)->toBe($replacement->id)
    ->and($result->revision)->toBe($placement->revision + 1);
```

Add tests for foreign owner/group IDs, unknown key/ID, stale revisions, hidden
blocks, invalid overrides after replacement, duplicate reorder IDs, incomplete
reorder sets, cycles, cross-region parents, lock contention, deadlock retry,
rollback, event payloads, and deterministic final ordering. Add block-key lookup
tests for authorization, exact keys, duplicate/corrupt keys, and absent blocks.
The completed matrix also proves byte-exact key predicates for MySQL, MariaDB,
PostgreSQL, SQL Server, and SQLite, plus the PostgreSQL-safe non-UUID query
shape.

- [x] **Step 2: Run the Content regression test and verify missing APIs fail**

Run: `vendor/bin/pest --configuration=packages/nvl/content/phpunit.xml.dist --compact packages/nvl/content/tests/Feature/ContentContractRegressionTest.php`

Expected: FAIL because the new Actions/DTOs do not exist.

- [x] **Step 3: Implement authorized block and placement lookups**

`FindContentBlockByKeyAction::execute(string $key, ContentActorData $actor):
ContentBlockData` normalizes a 1–191 character exact key, authorizes
`ContentAbility::View`, eager-loads the definition/translations needed by the
existing DTO, and fails for missing or duplicate/corrupt keys without exposing
a builder.

Signature:

```php
public function execute(
    Model&ContentOwner $owner,
    string $group,
    string $idOrKey,
    ContentActorData $actor,
): ContentPlacementData
```

Resolve canonical owner type/ID, assert the group, authorize
`ListPlacements`, query only within that owner/group by exact ID or key, reject
ambiguous ID/key collisions, eager-load the block fields needed for policy, and
return `ContentPlacementData`. Byte-exact grammar predicates preserve semantics
on case-insensitive database collations. Non-UUID keys never bind to the UUID
primary key; UUID-shaped input checks both identities to retain collision
detection.

- [x] **Step 4: Implement atomic block replacement**

Inside the owner lock and one transaction: lock the complete owner/group
placement ID set, resolve and lock the target placement and replacement block,
authorize `ContentAbility::Place`, compare expected revision, revalidate the
existing region/parent/overrides against the replacement definition, change
only `content_block_id` and revision, dispatch `ContentPlacementChanged` after
the write, and return a DTO from the refreshed placement.

- [x] **Step 5: Implement full-set reorder**

DTOs:

```php
ReorderContentPlacementData(string $id, int $expectedRevision, string $region, ?string $parentId, int $sortOrder)
ReorderContentPlacementsData(array $placements)
```

Both mutation inputs extend Data, use camel-case mapping and `DataTransform`,
and are emitted to TypeScript like the existing Content mutation DTOs.

Require exactly one item for every placement in the owner/group and cap by the
configured placement maximum. Under the owner lock and transaction, lock all
rows, compare all revisions before writing, apply the proposed tree in memory,
validate it through a new public `ContentPlacementTree::assertValidProposal()`
method, update rows in deterministic ID order, increment each revision once,
dispatch one event per changed row after all writes succeed, and return the
fresh CR-13 editor DTO.

- [x] **Step 6: Publish focused Action seams and run quality**

Keep the new DTO-returning workflows as documented, constructor-injected
Actions. This intentional implementation adjustment preserves the original
22-argument `Content` constructor and existing facade/model-returning methods
without optional dependencies or a service-locator fallback. Undocumented
Actions remain internal; these four editor Actions are explicit public seams.

Run: `php tools/run-package-quality.php content`

Actual: PASS with 135 tests and 931 assertions, PHPStan level max, and Pint.

Run: `php artisan nvl:data:types:generate --fail-on-warning`

Run: `composer types:check`

Actual: PASS with both reorder inputs generated. Public-contract, package
distribution, dependency, optimized-autoload, strict Composer, root analysis,
all-package analysis, focused readiness/skill, and full root/package test gates
also passed.

- [x] **Step 7: Commit CR-14** (`42113d8`)

```bash
git add packages/nvl/content/src/Actions packages/nvl/content/src/Data/Mutations packages/nvl/content/src/Relations/ExactTextValueComparison.php packages/nvl/content/src/Services/ContentPlacementTree.php packages/nvl/content/tests/Feature/ContentContractRegressionTest.php packages/nvl/content/README.md resources/boost/skills packages/nvl/content/resources/boost/skills resources/js/types tests/Contract/ConsumerReadinessTest.php tools/consumer-readiness.php tools/package-contracts.json docs/consumer-readiness.md
git commit -m "feat(content): add placement editor workflows"
```

### Task 3 (CR-15): Add Page key, option, and public-child reads

**Files:**
- Create: `packages/nvl/pages/src/Data/PageOptionData.php`
- Create: `packages/nvl/pages/src/Data/PageKeyAvailabilityData.php`
- Create: `packages/nvl/pages/src/Actions/FindPageByKeyAction.php`
- Create: `packages/nvl/pages/src/Actions/CheckPageKeyAvailabilityAction.php`
- Create: `packages/nvl/pages/src/Actions/ListPageOptionsAction.php`
- Create: `packages/nvl/pages/src/Actions/ListPublicChildPagesAction.php`
- Modify: `packages/nvl/pages/config/pages.php`
- Modify: `packages/nvl/pages/tests/Feature/PagesPackageTest.php`

**Interfaces:**
- Consumes: `PageAuthorization`, `PageUrlGenerator`, Translatable fallback, and `PagesConfiguration` limits.
- Produces: `PageData`, `PageKeyAvailabilityData`, `PageOptionData`, and `PublicPageData` application reads.

- [ ] **Step 1: Write failing authorization, locale, and bound tests**

```php
$page = app(FindPageByKeyAction::class)->execute('main', 'about', $actor);

expect($page)->toBeInstanceOf(PageData::class)
    ->and($page->key)->toBe('about');
```

Add tests for wrong site, missing key, denied actor, translation fallback,
published/scheduled/expired children, deterministic sibling ordering, search,
one-character search behavior, 100-result caps, and one-versus-twenty-five query
counts. Add key-availability tests for per-site uniqueness, `exceptId`, invalid
keys, and authorization before disclosure.

- [ ] **Step 2: Run the Pages package test and verify missing APIs fail**

Run: `vendor/bin/pest --configuration=packages/nvl/pages/phpunit.xml.dist --compact packages/nvl/pages/tests/Feature/PagesPackageTest.php`

Expected: FAIL because the new DTO/Actions do not exist.

- [ ] **Step 3: Implement management key and option reads**

Signatures:

```php
public function execute(string $site, string $key, PageActorData $actor): PageData
public function execute(string $site, string $key, PageActorData $actor, ?string $exceptId = null): PageKeyAvailabilityData
public function execute(string $site, string $locale, PageActorData $actor, ?string $search = null, int $limit = 50): Collection
```

Authorize `View` for the found page and `List` for options. Normalize site/key
through existing Pages identity rules. Options select ID/key/path/kind/status/
revision plus translations, resolve label for the requested locale, order by
path then ID, and map to
`PageOptionData(id, key, label, path, kind, status, revision)`.
Availability authorizes `PageAbility::List` for the site before checking the
configured Page model and returns the conflicting page ID without exposing a
model.

- [ ] **Step 4: Implement public child projection**

Signature:

```php
public function execute(
    string $parentId,
    PageRequestContextData $context,
    int $limit = 50,
): Collection
```

Resolve the parent inside `context.site`, authorize `ViewNavigation` with the
parent/context, restrict children to the same site and `publiclyVisible()`,
eager-load translations, apply sibling ordering, clamp at the configured/hard
100 maximum, and map through `PublicPageData::fromModel()`.

- [ ] **Step 5: Run Pages quality and generated types**

Run: `php tools/run-package-quality.php pages`

Expected: PASS.

Run: `php artisan nvl:data:types:generate --fail-on-warning`

Run: `composer types:check`

Expected: PASS with `PageOptionData` generated.

- [ ] **Step 6: Commit CR-15**

```bash
git add packages/nvl/pages/src/Data/PageOptionData.php packages/nvl/pages/src/Data/PageKeyAvailabilityData.php packages/nvl/pages/src/Actions/FindPageByKeyAction.php packages/nvl/pages/src/Actions/CheckPageKeyAvailabilityAction.php packages/nvl/pages/src/Actions/ListPageOptionsAction.php packages/nvl/pages/src/Actions/ListPublicChildPagesAction.php packages/nvl/pages/config/pages.php packages/nvl/pages/tests/Feature/PagesPackageTest.php resources/js/types
git commit -m "feat(pages): add bounded page lookup projections"
```

### Task 4 (CR-16): Compose Page editor and publication projections

**Files:**
- Create: `packages/nvl/metafields/src/Actions/Metafields/ListAuthorizedOwnerMetafieldsAction.php`
- Modify: `packages/nvl/metafields/tests/Feature/MetafieldConsumerWorkflowTest.php`
- Create: `packages/nvl/pages/src/Data/PageEditorBootstrapData.php`
- Create: `packages/nvl/pages/src/Data/PageEditorSummaryData.php`
- Create: `packages/nvl/pages/src/Actions/GetPageEditorBootstrapAction.php`
- Create: `packages/nvl/pages/src/Actions/ListPageEditorSummariesAction.php`
- Create: `packages/nvl/pages/src/Actions/GetPagePublicationProjectionAction.php`
- Modify: `packages/nvl/pages/tests/Feature/PagesPackageTest.php`
- Modify: `packages/nvl/pages/tests/ArchitectureTest.php`
- Modify: `packages/nvl/pages/README.md`
- Modify: `tools/consumer-readiness.php`
- Modify: `tests/Contract/ConsumerReadinessTest.php`

**Interfaces:**
- Consumes: CR-12 SEO owner read, CR-13 Content editor, CR-15 Page reads, `MetafieldAuthorization`, `ListOwnerMetafieldsAction`, `PageResourceRegistry`, and existing public rendering services.
- Produces: an authorized Metafields owner read, `PageEditorSummaryData` paginator, `PageEditorBootstrapData`, and an ID-based `ResolvedPageData` publication projection.

- [ ] **Step 1: Write failing complete-projection tests**

```php
$editor = app(GetPageEditorBootstrapAction::class)->execute(
    $page->id,
    'en',
    $actor,
);

expect($editor->page)->toBeInstanceOf(PageData::class)
    ->and($editor->content)->toBeInstanceOf(ContentEditorData::class)
    ->and($editor->seo)->toBeInstanceOf(SeoProfileData::class)
    ->and($editor->resourceAliases)->toContain('article');
```

Add missing SEO behavior, empty metafields, deterministic enum/resource lists,
authorization failures from Page/Content/SEO/Metafields, no lazy loading,
one-versus-twenty-five page/placement query counts, stable summary pagination,
and ID-based public visibility tests. Add a Metafields-focused test proving
authorization happens before its storage list Action.

- [ ] **Step 2: Run Pages tests and verify missing projection classes fail**

Run: `vendor/bin/pest --configuration=packages/nvl/pages/phpunit.xml.dist --compact packages/nvl/pages/tests/Feature/PagesPackageTest.php packages/nvl/pages/tests/ArchitectureTest.php`

Run: `vendor/bin/pest --configuration=packages/nvl/metafields/phpunit.xml.dist --compact packages/nvl/metafields/tests/Feature/MetafieldConsumerWorkflowTest.php`

Expected: FAIL because the authorized Metafields wrapper and the Pages
bootstrap/publication APIs do not exist.

- [ ] **Step 3: Add the authorized Metafields owner projection**

`ListAuthorizedOwnerMetafieldsAction::execute(Model $owner, ?string $locale = null): Collection`
calls `MetafieldAuthorization::authorizeOwner(MetafieldAbility::ViewOwner,
$owner)` before delegating to the existing `ListOwnerMetafieldsAction`. Keep the
existing storage-focused Action compatible; consumers and Pages use the new
authorized wrapper for management reads.

- [ ] **Step 4: Implement the editor bootstrap DTO**

Constructor:

```php
PageEditorBootstrapData(
    PageData $page,
    ContentEditorData $content,
    ?SeoProfileData $seo,
    array $metafields,
    array $pageKinds,
    array $pageStatuses,
    array $resourceAliases,
    int $maximumDepth,
)
```

The Action resolves and authorizes the page first, then invokes
`GetOwnerContentEditorAction` with `Page::CONTENT_GROUP` and
`$actor->contentActor()`, CR-12's SEO owner Action, and
`ListAuthorizedOwnerMetafieldsAction`. Enum arrays use their backed values;
resource aliases come from `PageResourceRegistry::aliases()`; metafields use
their existing DTOs. Do not catch authorization exceptions or return partial
data after denial.

- [ ] **Step 5: Implement bounded editor summaries**

Signature:

```php
public function execute(
    string $site,
    string $locale,
    PageActorData $actor,
    int $perPage = 25,
): LengthAwarePaginator
```

Authorize `PageAbility::List` for the site, clamp `perPage` to 100, eager-load
page and SEO translations with fixed columns, then request all Content
placements in one call to CR-13's
`ListOwnerContentPlacementSummariesAction`. Map with paginator `through()`.
`PageEditorSummaryData` contains `PageData $page`, `string $label`,
`array<ContentPlacementData> $placements`, and `?SeoProfileData $seo`. Preserve
page ordering and paginator links; never repeat definition/preset catalogs in
each row.

- [ ] **Step 6: Implement publication projection by page identity**

Signature:

```php
public function execute(string $pageId, string $locale, PageActorData $actor): ResolvedPageData
```

Resolve a publicly visible page by ID, authorize `View`, build
`PublicPageData`, render public Content, resolve SEO, and return
`ResolvedPageData` with `resource: null`. Reject resource-kind pages because
their resource request parameters are absent; consumers use `ResolvePageAction`
for those.

- [ ] **Step 7: Document editor/public read choices and update evidence**

Document:

- `ResolvePageAction` for path/resource public delivery;
- `PreviewPageAction` for path-based management preview;
- `GetPagePublicationProjectionAction` for static page ID publication checks;
- `GetPageEditorBootstrapAction` for management editor initialization.
- `ListPageEditorSummariesAction` for bounded editor indexes.

Point the consumer-readiness catalog to the query-count test.

- [ ] **Step 8: Run package, contracts, and type gates**

Run: `php tools/run-package-quality.php pages`

Run: `php tools/run-package-quality.php metafields`

Run: `php artisan test --compact tests/Contract/ConsumerReadinessTest.php tests/Feature/Integration/CrossPackageIntegrationTest.php`

Run: `php artisan nvl:data:types:generate --fail-on-warning`

Run: `composer types:check`

Expected: all PASS.

- [ ] **Step 9: Commit CR-16**

```bash
git add packages/nvl/metafields/src/Actions/Metafields/ListAuthorizedOwnerMetafieldsAction.php packages/nvl/metafields/tests/Feature/MetafieldConsumerWorkflowTest.php packages/nvl/pages/src/Data/PageEditorBootstrapData.php packages/nvl/pages/src/Data/PageEditorSummaryData.php packages/nvl/pages/src/Actions/GetPageEditorBootstrapAction.php packages/nvl/pages/src/Actions/ListPageEditorSummariesAction.php packages/nvl/pages/src/Actions/GetPagePublicationProjectionAction.php packages/nvl/pages/tests packages/nvl/pages/README.md tools/consumer-readiness.php tests/Contract/ConsumerReadinessTest.php resources/js/types
git commit -m "feat(pages): compose editor and publication projections"
```

### Workstream acceptance gate

- [ ] Run `php tools/run-package-quality.php content`.
- [ ] Run `php tools/run-package-quality.php pages`.
- [ ] Run Metafields, SEO, Translatable, and Data focused integration gates.
- [ ] Run `composer contracts:check` and `composer types:check`.
- [ ] Build the KPO editor endpoint using only the new DTO APIs and run KPO strict consumer audit.
- [ ] Confirm the editor endpoint makes the documented constant query count for one and twenty-five placements.
