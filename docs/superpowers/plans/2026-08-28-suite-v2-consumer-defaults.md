# Suite 2.0 Consumer Defaults Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make module activation fail-safe and remove the 1.x compatibility ambiguity from application-facing read APIs.

**Architecture:** The 2.0 cut changes only behavior already diagnosed and migratable in the final 1.x release. Missing module decisions become disabled; model-returning management reads with proven DTO replacements change to those DTOs, while models remain available as package-owned identity and relation types.

**Tech Stack:** PHP 8.4/8.5, Laravel 12/13, Pest 4, Composer SemVer, sealed upgrade fixtures.

**Spec:** `docs/superpowers/specs/2026-08-28-consumer-readiness-v2-design.md`

## Global Constraints

- Do not execute this plan until CR-19 through CR-22 and CR-25 through CR-34 are complete on the final 1.x release.
- Every breaking change must have a final-1.x warning, a documented replacement, and a passing previous-minor upgrade rehearsal.
- Missing flags become disabled; explicit `true` and dependency-enabled modules retain canonical ordering.
- Package models remain valid Action parameters/results for mutation identity and owner relationships unless a task explicitly replaces that return type.
- No persistence model class is removed solely to prevent consumer queries.
- The strict consumer audit, not PHP visibility tricks, enforces unsupported consumer-initiated queries.

---

### Task 1 (CR-23): Disable omitted module flags

**Files:**
- Modify: `src/Support/SuiteModuleCatalog.php`
- Modify: `config/nvl-suite.php`
- Modify: `src/Console/Commands/SuiteConfigureCommand.php`
- Modify: `src/Console/Commands/SuiteUpgradeCheckCommand.php`
- Modify: `src/Console/Commands/SuiteDoctorCommand.php`
- Modify: `tests/Feature/SuiteDiagnosticsTest.php`
- Modify: `tests/Feature/SuiteConfigurationWriterTest.php`
- Modify: `tests/Contract/PackageArchiveToolsTest.php`
- Modify: `docs/installation-profiles.md`
- Create: `UPGRADING.md`

**Interfaces:**
- Consumes: CR-03 decision states and CR-04 configuration renderer/checker.
- Produces: v2 `effectiveModules()` semantics where omitted keys are disabled.

- [x] **Step 1: Change the tests first to express v2 behavior**

```php
$catalog = new SuiteModuleCatalog(new Repository([
    'nvl-suite' => ['modules' => ['pages' => true]],
]));

expect($catalog->requested('auth'))->toBeFalse()
    ->and($catalog->effectiveModules())->toContain('pages', 'content', 'media')
    ->not->toContain('auth', 'forms');
```

Add tests for an absent published config using the package's full explicit
default file, partial configs, explicit false dependencies that are re-enabled
transitively, unknown keys, configure output, and upgrade-check remediation.

- [x] **Step 2: Run diagnostics/configuration tests and verify old defaults fail**

Run: `php artisan test --compact tests/Feature/SuiteDiagnosticsTest.php tests/Feature/SuiteConfigurationWriterTest.php`

Expected: FAIL because omitted flags still resolve true.

- [x] **Step 3: Change catalog fallback semantics**

For the legacy non-null `modules` map, replace the missing-key fallback with
`false` in `SuiteModuleSelection`. Keep dependency closure and CR-28 profile/
include/exclude semantics unchanged. The shipped `config/nvl-suite.php` keeps
`profile: full-suite`, so a new installation is unchanged; partial legacy maps
activate only their explicit modules and dependencies.

- [x] **Step 4: Make upgrade tooling produce the exact fix**

`nvl:suite:upgrade:check` on a 1.x partial config exits 1 and lists every omitted
module with its effective 2.0 disabled state. `nvl:suite:configure --write`
renders all keys explicitly and creates a timestamped sibling backup only when
overwriting an existing file. Doctor no longer calls an omitted decision a
warning in 2.0; it reports it as an intentional disabled state.

- [x] **Step 5: Run upgrade, archive, and clean-consumer gates**

Run: `php artisan test --compact tests/Feature/SuiteDiagnosticsTest.php tests/Feature/SuiteConfigurationWriterTest.php tests/Contract/PackageArchiveToolsTest.php`

Run both production consumer runners and the final-1.x-to-2.0 archive rehearsal.

Expected: PASS with unchanged effective profiles after config rendering.

- [x] **Step 6: Commit CR-23**

```bash
git add src/Support/SuiteModuleCatalog.php config/nvl-suite.php src/Console/Commands/SuiteConfigureCommand.php src/Console/Commands/SuiteUpgradeCheckCommand.php src/Console/Commands/SuiteDoctorCommand.php tests docs/installation-profiles.md UPGRADING.md
git commit -m "feat!: disable omitted suite modules"
```

### Task 2 (CR-24a): Convert compatibility management reads to DTOs

**Files:**
- Modify: `packages/nvl/auth/src/Actions/Rbac/ListRolesAction.php`
- Modify: `packages/nvl/auth/src/Actions/Rbac/ListPermissionsAction.php`
- Modify: `packages/nvl/auth/tests/Feature/RbacManagementTest.php`
- Modify: `packages/nvl/pages/src/Actions/GetPageAction.php`
- Modify: `packages/nvl/pages/src/Actions/ListPagesAction.php`
- Create: `packages/nvl/pages/src/Data/PageListItemData.php`
- Modify: `packages/nvl/pages/tests/Feature/PagesPackageTest.php`
- Modify: `packages/nvl/content/src/Actions/GetContentBlockAction.php`
- Modify: `packages/nvl/content/src/Actions/ListContentBlocksAction.php`
- Modify: `packages/nvl/content/src/Actions/ListContentPlacementsAction.php`
- Modify: `packages/nvl/content/src/Actions/GetOwnerContentEditorAction.php`
- Modify: `packages/nvl/content/src/Content.php`
- Modify: `packages/nvl/content/src/Facades/Content.php`
- Modify: `packages/nvl/content/tests/Feature/ContentContractRegressionTest.php`
- Modify: `packages/nvl/seo/src/Actions/GetSeoProfileAction.php`
- Modify: `packages/nvl/seo/src/Actions/ListSeoProfilesAction.php`
- Modify: `packages/nvl/seo/tests/Feature/SeoConsumerContractsTest.php`

**Interfaces:**
- Consumes: DTO replacements shipped and exercised during 1.1–1.4.
- Produces: DTO-returning management reads for Auth, Pages, Content, and SEO.

- [ ] **Step 1: Inventory final 1.x consumers before changing signatures**

Run strict audit across KPO and both proof consumers, then search their source
for every class/method listed in this task. Record caller counts and confirm each
has a DTO mapping already exercised in 1.x. If any caller lacks a replacement,
stop this task and add the replacement to the final 1.x line first.

- [ ] **Step 2: Change package tests to require DTO paginator items**

Example:

```php
$roles = app(ListRolesAction::class)->execute($actor);

expect($roles->items())->each->toBeInstanceOf(RoleListItemData::class);
```

Add equivalent assertions for permission, page, block, placement, and SEO list
items plus query-count parity and serialized TypeScript shapes.

- [ ] **Step 3: Convert Auth and Pages list/detail reads**

Map Eloquent paginator collections with `through()` while preserving total,
page, per-page, path/query options, ordering, authorization, and query ceilings.
`GetPageAction` returns `PageData`; `ListPagesAction` returns
`LengthAwarePaginator<int, PageListItemData>`. Auth list DTOs include the counts
and hierarchy labels already eager-loaded by their Actions, reusing the
`RoleListItemData` and `PermissionListItemData` contracts shipped in 1.1 rather
than adding a second catalog shape.

- [ ] **Step 4: Convert Content and SEO list/detail reads**

`GetContentBlockAction` returns `ContentBlockData`; block and placement lists map
to existing DTOs; `Content::block()` and `Content::placements()` annotations and
return types change accordingly. `GetOwnerContentEditorAction` consumes the
placement DTO collection directly instead of calling `fromModel()` a second
time. Mutations keep their Action-returned model identity contracts.
`GetSeoProfileAction` returns `SeoProfileData` through `SeoProfilePresenter`;
`ListSeoProfilesAction` maps its paginator to the same DTO.

- [ ] **Step 5: Run four package quality gates and generated types**

Run:

```bash
php tools/run-package-quality.php auth pages content seo
php artisan nvl:data:types:generate --fail-on-warning
composer types:check
```

Expected: all PASS.

- [ ] **Step 6: Commit CR-24a**

```bash
git add packages/nvl/auth packages/nvl/pages packages/nvl/content packages/nvl/seo resources/js/types
git commit -m "feat!: return DTOs from management reads"
```

### Task 3 (CR-24b): Enforce the 2.0 consumer boundary and migration guide

**Files:**
- Create: `tools/consumer-api-deprecations.php`
- Create: `tests/Contract/ConsumerApiDeprecationsTest.php`
- Modify: `src/Services/ConsumerAudit/PhpConsumerBoundaryScanner.php`
- Modify: `src/Console/Commands/SuiteConsumerAuditCommand.php`
- Modify: `tools/consumer-readiness.php`
- Modify: `docs/consumer-readiness.md`
- Modify: `docs/releasing.md`
- Modify: `UPGRADING.md`
- Modify: `CHANGELOG.md`
- Modify: `.github/workflows/package-release.yml`

**Interfaces:**
- Consumes: final 1.x audit evidence and CR-24a DTO reads.
- Produces: authoritative removed/replacement catalog, strict v2 audit, and verified migration documentation.

- [ ] **Step 1: Write a failing catalog completeness test**

```php
foreach ($deprecations as $symbol => $replacement) {
    expect($symbol)->toBeString()->not->toBeEmpty()
        ->and($replacement['since'])->toMatch('/^1\.[0-9]+$/')
        ->and($replacement['removed'])->toBe('2.0')
        ->and(class_exists($replacement['replacement']))->toBeTrue();
}
```

Assert every changed CR-24a signature is cataloged and every catalog entry is
mentioned in `UPGRADING.md`.

- [ ] **Step 2: Run the contract test and verify the catalog is missing**

Run: `php artisan test --compact tests/Contract/ConsumerApiDeprecationsTest.php`

Expected: FAIL because the deprecation catalog does not exist.

- [ ] **Step 3: Add exact replacement metadata and audit severity**

Catalog each old symbol, final supported 1.x version, 2.0 replacement, old/new
return shapes, and migration test evidence. In 2.0 strict audit, every
unallowlisted consumer-initiated package model query is an error. Model type
hints, Action-returned identity use, route binding followed by an Action,
owner-trait relations, Filterable, Translatable, adoption migrations, and
documented legacy bridges retain their explicit classifications.

- [ ] **Step 4: Write and execute the upgrade guide**

For each break, include before/after code using the catalog's real types. Run
the guide against the final 1.x KPO/fixtures, upgrade to the 2.0 archive, and
rerun config/route cache, migrations, TypeScript, strict Doctor/audit, fixture
smokes, and KPO full CI.

- [ ] **Step 5: Run the complete 2.0 release gate**

Run `composer quality`, both production runners, all database jobs, the archive
install, and previous-minor upgrade rehearsal. Require clean audit output from
KPO and both proof consumers.

- [ ] **Step 6: Commit CR-24b**

```bash
git add tools/consumer-api-deprecations.php tests/Contract/ConsumerApiDeprecationsTest.php src/Services/ConsumerAudit src/Console/Commands/SuiteConsumerAuditCommand.php tools/consumer-readiness.php docs UPGRADING.md CHANGELOG.md .github/workflows/package-release.yml
git commit -m "docs: finalize suite 2.0 consumer boundary"
```

### Workstream acceptance gate

- [ ] Omitted module flags are disabled in partial configs and explicit profiles are unchanged.
- [ ] All changed read Actions return DTOs with pagination/query parity.
- [ ] KPO and both proof consumers upgrade from final 1.x to 2.0 without a source-query suppression.
- [ ] Full PHP/framework/database/release matrices pass.
- [ ] Record the 2.0 release candidate commit beside CR-23 and CR-24 in the master tracker.
