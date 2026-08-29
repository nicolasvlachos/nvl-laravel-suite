# Consumer Configuration and Auth Ergonomics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a suite consumer declare only intentional choices, detect stale package configuration before upgrades, and integrate Auth as an embedded application concern without copying package defaults or defining a parallel gate layer.

**Architecture:** The suite catalog becomes the source of truth for package quality commands, configuration ownership, profiles, and deprecated keys. Runtime configuration remains Laravel-native and backward compatible; new commands inspect source configuration structurally without reading values or rewriting files. Auth adds a host-owned HTTP preset and a configurable management-access adapter while leaving KPO policies, routes, controllers, and UI in KPO.

**Tech Stack:** PHP 8.4, Laravel 13 console/config/container/Gate, Symfony Process, Pest 4.

**Spec:** `docs/superpowers/specs/2026-08-28-consumer-readiness-v2-design.md`

## KPO evidence this plan must remove

- KPO enables seventeen of twenty modules but expresses that choice as twenty booleans.
- KPO publishes thirteen package configuration files containing roughly 1,500 lines.
- All thirteen copies differ from current package defaults. Runtime recursive merge keeps most of them working, but source drift is invisible.
- The published Auth file has 24 intentional changes and 139 copied defaults; Comments has four intentional changes and 52 copied defaults.
- KPO's suite provider defines seventeen `nvl-auth.*` gates to translate package management abilities to host policies.
- KPO deliberately owns Auth HTTP/UI and disables all package Auth route groups. That is a valid architecture, not a migration target.
- Package-local `composer quality` aliases are not a reliable monorepo command: root binaries are not on the child Composer PATH and package PHPStan include paths are inconsistent.

## Global constraints

- Existing `config/nvl-suite.php` and package configuration files continue to work unchanged in 1.x.
- Configuration inspection reports key paths and classifications only; it never logs secret values or evaluates arbitrary PHP in a subprocess.
- Commands default to read-only output. Writing requires `--write`; overwriting an existing file requires `--force` and a generated diff.
- Profiles select modules, not business features. Host business vocabulary never enters the package catalog.
- Dependencies are always closed transitively and cannot be excluded while a selected module requires them.
- Auth presets configure ownership and adapters; they do not generate KPO controllers, routes, policies, mailables, or frontend code.
- Configuration accepts class strings and scalar data, never closures.
- No external dependency is added. The internal `nvl/support` normalization in
  CR-27a requires its explicit dependency-approval checkpoint.

---

### Task 1 (CR-00): Add one reliable root package-quality runner

**Files:**
- Create: `tools/run-package-quality.php`
- Create: `tools/package-quality-runner.php`
- Modify: `composer.json`
- Modify: `phpstan.neon.dist`
- Modify: `tools/package-family.php`
- Modify: `packages/nvl/auth/phpstan.neon.dist`
- Modify: `packages/nvl/data/src/Console/Commands/CheckTypesCommand.php`
- Modify: `packages/nvl/data/tests/Feature/DataPackageTest.php`
- Create: `tests/Contract/PackageMigrationQualityTest.php`
- Modify: `tests/Contract/PackageQualityWorkflowTest.php`
- Modify: `CONTRIBUTING.md`

**Interfaces:**
- Consumes: root `vendor/bin` tools, the package family catalog, each package's source/tests/phpunit configuration, and optional package PHPStan configuration.
- Produces: `php tools/run-package-quality.php <package>... [--format=table|json]` and `composer package:quality -- <package>...`.

**Observed baseline blockers:** Auth's package PHPStan config currently reports
24 errors in the release-locked anonymous migration
`2026_08_01_000000_create_nvl_auth_identity_tables.php`, despite its fresh/
upgrade schema tests passing. An Auth/Comments quality run launched concurrently
also collided through shared `.temp` state; isolated Comments rerun passes all
193 tests. CR-00 must resolve both quality-topology defects without editing a
released migration or suppressing an error baseline. A clean linked worktree
also fails `composer types:check` because generated-type manifest freshness
compares checkout-dependent mtimes even when every declaration byte is
unchanged; the original checkout passes only while generation-time mtimes are
preserved.

- [x] **Step 1: Write failing runner contract tests**

Load `tools/package-quality-runner.php` with a temporary fake package family
plus a fake process executor so tests assert exact commands without recursively
invoking the suite. Cover an unknown package,
one package, multiple packages, failure propagation, JSON output, absent tests,
and paths containing spaces. Assert every process uses the suite root as its
working directory and root `vendor/bin` binaries, executes packages sequentially,
and assigns package-specific PHPStan/PHPUnit temp/cache paths.

Add a Data regression test that changes only generated declaration mtimes and
proves `nvl:data:types:check` still passes for byte-identical artifacts while
retaining hash, inventory, symbol, version, source, and revision validation.

- [x] **Step 2: Prove the current package-local command is not the contract**

Record the current failures from:

```bash
composer --working-dir=packages/nvl/auth quality
composer --working-dir=packages/nvl/comments quality
```

Expected baseline: Auth fails because `pint` is not resolved; Comments also has
a package-relative PHPStan include that assumes a standalone package `vendor`.
Keep this evidence in the implementation log, not in a runtime exception.

- [x] **Step 3: Make immutable migration verification explicit**

Do not edit the release-locked Auth migration, add ignores, or create a PHPStan
baseline. Remove released package migrations from Auth's mutable-code PHPStan
paths, matching Comments' current analysis topology. Add
`PackageMigrationQualityTest` to prove every released migration is checksum
locked in `tools/package-contracts.json`, parses, is covered by its package's
fresh/upgrade/ownership tests, and participates in the supported database matrix.
The runner analyzes a new/unreleased migration until it becomes release-locked;
the package-family quality descriptor records that boundary explicitly.

- [x] **Step 4: Implement the root runner**

Resolve package names only through `tools/package-family.php`. For each selected
package run, in order:

1. `vendor/bin/pint --test --format agent packages/nvl/<package>`;
2. `vendor/bin/phpstan analyse` against that package's declared source,
   factories, fixtures, and unreleased migrations using a root-resolvable
   generated include list;
3. `vendor/bin/pest --test-directory=... --configuration=... --bootstrap=vendor/autoload.php --compact` when tests exist.

Do not invoke child Composer scripts. Stream table output, collect duration and
exit code for JSON, stop on the first failed package by default, and support
`--continue-on-error` for the release matrix. Add a `quality` descriptor to the
package family catalog when a package needs non-standard analysis paths; do not
hardcode package names in the runner. Run packages sequentially by default and
do not offer package parallelism until every tool has isolated temp/cache paths.

- [x] **Step 5: Add the root Composer entry and migrate planning/docs commands**

Add `"package:quality": "@php tools/run-package-quality.php"`. Document that
standalone package archives may still run their package-local Composer scripts,
whereas the monorepo always uses the root runner. Replace every implementation
plan's `composer --working-dir=packages/nvl/<package> quality` instruction with
`php tools/run-package-quality.php <package>`.

- [x] **Step 6: Verify the runner against the two observed packages**

Run:

```bash
php tools/run-package-quality.php auth comments
php tools/run-package-quality.php comments auth
php tools/run-package-quality.php auth comments --format=json
composer types:check
php artisan test --compact tests/Contract/PackageQualityWorkflowTest.php tests/Contract/PackageMigrationQualityTest.php
```

Expected: both orderings pass repeatedly, Auth reports no mutable-source
analysis errors, Comments passes 193 tests without shared-cache contamination,
and JSON contains no absolute paths or configuration values.

- [x] **Step 7: Commit CR-00** (`e41ffab`)

```bash
git add composer.json tools/package-family.php tools/package-quality-runner.php tools/run-package-quality.php packages/nvl/auth/phpstan.neon.dist tests/Contract/PackageMigrationQualityTest.php tests/Contract/PackageQualityWorkflowTest.php CONTRIBUTING.md docs/superpowers/plans
git commit -m "build: add reliable package quality runner"
```

---

### Task 2 (CR-27a): Standardize deep-map and atomic-list configuration merge semantics

**Approval checkpoint:** This task adds `nvl/support` as an internal dependency
to config-bearing packages that do not already require it. Obtain explicit
dependency-change approval before editing manifests. If approval is denied,
stop CR-27a and retain CR-27b's diagnostics; do not copy the merger into eleven
packages.

**Files:**
- Create: `packages/nvl/support/src/Config/PackageConfigurationMerger.php`
- Create: `packages/nvl/support/src/Traits/MergesPackageConfiguration.php`
- Create: `packages/nvl/support/tests/Unit/PackageConfigurationMergerTest.php`
- Modify: `packages/nvl/activity/src/Providers/ActivityServiceProvider.php`
- Modify: `packages/nvl/auth/src/Providers/AuthServiceProvider.php`
- Modify: `packages/nvl/comments/src/Providers/CommentsServiceProvider.php`
- Modify: `packages/nvl/content/src/Providers/ContentServiceProvider.php`
- Modify: `packages/nvl/data/src/Providers/DataServiceProvider.php`
- Modify: `packages/nvl/forms/src/Providers/FormsServiceProvider.php`
- Modify: `packages/nvl/mail-notifications/src/Providers/MailNotificationsServiceProvider.php`
- Modify: `packages/nvl/media/src/Providers/MediaServiceProvider.php`
- Modify: `packages/nvl/metafields/src/Providers/MetafieldsServiceProvider.php`
- Modify: `packages/nvl/pages/src/Providers/PagesServiceProvider.php`
- Modify: `packages/nvl/primitives/src/Providers/PrimitivesServiceProvider.php`
- Modify: `packages/nvl/seo/src/Providers/SeoServiceProvider.php`
- Modify: `packages/nvl/settings/src/Providers/SettingsServiceProvider.php`
- Modify: `packages/nvl/taxonomy/src/Providers/TaxonomyServiceProvider.php`
- Modify: `packages/nvl/templates/src/Providers/TemplatesServiceProvider.php`
- Modify: `packages/nvl/translatable/src/Providers/TranslatableServiceProvider.php`
- Modify: `packages/nvl/translations/src/Providers/TranslationsServiceProvider.php`
- Modify: `packages/nvl/auth/composer.json`
- Modify: `packages/nvl/comments/composer.json`
- Modify: `packages/nvl/data/composer.json`
- Modify: `packages/nvl/mail-notifications/composer.json`
- Modify: `packages/nvl/pages/composer.json`
- Modify: `packages/nvl/primitives/composer.json`
- Modify: `packages/nvl/seo/composer.json`
- Modify: `packages/nvl/settings/composer.json`
- Modify: `packages/nvl/taxonomy/composer.json`
- Modify: `packages/nvl/templates/composer.json`
- Modify: `packages/nvl/translatable/composer.json`
- Modify: `composer.lock`
- Modify: `tools/package-contracts.json`
- Modify: `tests/Contract/PackageQualityWorkflowTest.php`

**Interfaces:**
- Consumes: package default arrays and Laravel's already-loaded host config.
- Produces: one standalone-package-safe merge rule shared by every package provider.

- [x] **Step 1: Write failing merger semantics tests**

Cover nested maps, scalar replacement, class strings, associative numeric keys,
default lists, shorter host lists, empty host lists, nested lists, type changes,
null, cached configuration, and host config absence. The binding rule is:

- map plus map recursively merges;
- when either side is a list, the host list atomically replaces the default;
- scalar/null/type changes use the host value;
- no input array is mutated.

Add a contract test that enumerates every package config provider and proves it
uses the shared trait rather than Laravel's index-merging recursive helper or a
private merge implementation.

- [x] **Step 2: Capture the current list-tail failure**

In a provider fixture, set a two-entry host middleware/allowed-value list over a
three-entry default and set another list to empty. Assert the current result
retains stale entries. This is the red test; never use a KPO production config
value in the fixture.

- [x] **Step 3: Implement the Support merger and provider trait**

`PackageConfigurationMerger::merge(array $defaults, array $host): array` is a
pure, fully typed recursive function. `MergesPackageConfiguration` mirrors
Laravel's configuration-cache guard, requires the package default file once,
and writes the merged array into the Config repository. It neither logs nor
normalizes values.

- [x] **Step 4: Adopt it across all seventeen config-bearing providers**

Replace `replaceConfigRecursivelyFrom`, Auth's `array_replace_recursive`, and
Activity/Comments/Content private merge implementations. Do not alter default
config values. After approval, add `nvl/support:^1.0` only to the eleven package
manifests listed above; update dependency contracts/lockfile using Composer, not
manual lock edits. Verify standalone archives still discover Support before the
consumer package provider.

- [x] **Step 5: Run representative and full package gates**

```bash
php tools/run-package-quality.php support auth comments content mail-notifications pages
composer dependencies:check
composer packages:validate
composer contracts:check
composer test:packages
```

Expected: shorter/empty list overrides are exact, map overlays retain defaults,
and every standalone package dependency graph is valid.

- [x] **Step 6: Commit CR-27a and CR-27b together after their shared catalog gate**

```bash
git add packages/nvl composer.lock tools/package-contracts.json tests/Contract/PackageQualityWorkflowTest.php
git commit -m "fix(config): replace package lists atomically"
```

---

### Task 3 (CR-27b): Add structural package-configuration drift diagnostics

**Files:**
- Create: `src/Services/SuitePackageConfigurationInspector.php`
- Create: `src/Support/SuiteConfigurationFinding.php`
- Modify: `src/Support/SuiteModuleCatalog.php`
- Modify: `src/Services/SuiteConfigurationInspector.php`
- Modify: `src/Console/Commands/SuiteConfigurationCommand.php`
- Modify: `src/Console/Commands/SuiteUpgradeCheckCommand.php`
- Modify: `src/SuiteServiceProvider.php`
- Modify: `tests/Feature/SuiteDiagnosticsTest.php`
- Modify: `tests/Contract/SuiteAdoptionDocumentationTest.php`
- Modify: `docs/installation-profiles.md`

**Interfaces:**
- Consumes: package default config files, the host's published source config files, module catalog deprecation/open-map metadata, and CR-04 adoption decisions.
- Produces: value-free findings in `nvl:suite:configuration` and `nvl:suite:upgrade:check --strict --format=table|json`.

- [x] **Step 1: Write failing KPO-shaped configuration tests**

Create fixtures that reproduce the observed structural cases without copying
KPO values: valid consumer-owned `content.scopes.*`, obsolete Auth session-limit
configuration, obsolete `translations.authorization.class`, missing current Pages branches, one fully
copied Auth default tree, one minimal Comments overlay, and a dynamic map under
a catalog-declared open key. Assert stable finding codes and no serialized
values. The live package implementation confirms Content scopes remain a
supported open map and must not be reported as stale.

- [x] **Step 2: Define catalog-owned configuration metadata**

Extend each module definition with its package config key/default file,
published destination, open-map paths, deprecated paths, and replacement hint.
The catalog, not a command-specific switch statement, owns this metadata.

Stable codes:

- `configuration.unknown_key` (error in strict mode);
- `configuration.deprecated_key` (error in strict mode);
- `configuration.expanded_overlay` (warning when a host file structurally resembles a published full snapshot);
- `configuration.missing_current_branch` (detail/warning only beneath an expanded overlay);
- `configuration.merge_strategy_mismatch` (error);
- `configuration.source_unavailable` (warning).

- [x] **Step 3: Implement a value-free structural comparison**

Use `token_get_all()` to statically extract literal array-key trees and basic
container/scalar kinds from published PHP config source; never `require` or
evaluate the file for inspection. Computed keys, spreads, and dynamic branches
produce `configuration.source_unavailable` for that branch. Compare normalized
key trees and available kinds, never values. Open maps stop recursion below
their declared path. List-valued paths compare only the path and container type.
Detect whether the provider's merge strategy satisfies the
catalog declaration; standardize diagnostics around recursive merge while
preserving current runtime behavior. Classify a file as expanded only when it
contains at least 20 closed default paths and at least 60% of that package's
closed default tree; unit-test the thresholds against full, partial, and minimal
fixtures. Missing defaults in a small intentional overlay are normal and produce
no finding. Output counts and key paths sorted by module/severity/path.

- [x] **Step 4: Integrate upgrade-check and existing suite output**

`nvl:suite:configuration` gains a `package_configuration` section.
`nvl:suite:upgrade:check` combines CR-04 module adoption changes, operational
requirements, and configuration drift. It is read-only and exits non-zero in
strict mode only for error-severity findings. `--module=auth --module=comments`
limits inspection without altering runtime configuration.

- [x] **Step 5: Verify cached and uncached behavior**

Run:

```bash
php artisan test --compact tests/Feature/SuiteDiagnosticsTest.php tests/Contract/SuiteAdoptionDocumentationTest.php
php artisan nvl:suite:upgrade:check --strict --format=json
php tools/run-package-quality.php auth comments content pages translations
```

Expected: fixtures classify the KPO-shaped cases deterministically; the real
suite application produces no value-bearing JSON.

- [x] **Step 6: Commit CR-27**

```bash
git add src tests/Feature/SuiteDiagnosticsTest.php tests/Contract/SuiteAdoptionDocumentationTest.php docs/installation-profiles.md
git commit -m "feat(suite): diagnose package config drift"
```

---

### Task 4 (CR-28): Make profiles and minimal overlays runtime adoption inputs

**Files:**
- Create: `src/Services/SuiteModuleSelection.php`
- Modify: `config/nvl-suite.php`
- Modify: `src/Support/SuiteModuleCatalog.php`
- Modify: `src/SuiteServiceProvider.php`
- Modify: `src/Services/SuiteConfigurationInspector.php`
- Modify: `src/Console/Commands/SuiteConfigureCommand.php`
- Modify: `tests/Feature/SuiteDiagnosticsTest.php`
- Modify: `tests/Feature/PackagePublishingContractTest.php`
- Modify: `docs/installation-profiles.md`

**Interfaces:**
- Consumes: optional `profile`, `include`, `exclude`, legacy `modules`, and the catalog dependency graph.
- Produces: one dependency-complete `SuiteModuleSelection` shared by provider registration, configuration output, Doctor, and configure/upgrade commands.

- [x] **Step 1: Write precedence and compatibility tests**

Cover legacy booleans with `profile: null`, a profile alone, profile plus include,
profile plus valid exclude, an excluded dependency, unknown names, an empty
selection, a no-published-config install, and config-cached boot. Prove the exact
1.x legacy selection remains unchanged when an existing published `modules` map
is present.

- [x] **Step 2: Define one selection algorithm**

Precedence is:

1. when a non-null legacy `modules` array is present, it remains authoritative
   in 1.x;
2. otherwise start with the named profile or an empty root selection;
3. add explicit `include` roots;
4. reject unknown/excluded selected roots;
5. close dependencies transitively in catalog order;
6. reject an `exclude` that is required by a retained root.

Change the package default to `profile: full-suite`, empty include/exclude, and
`modules: null`. This keeps a new/no-config installation full-suite while
allowing a minimal host profile to survive Laravel's default merge. Existing
published boolean maps override the null default and retain their old behavior.
CR-27's static source inspection reports a host file that explicitly declares
both legacy and new keys; runtime does not confuse the package's default profile
with host-authored mixed input. The effective result always contains all twenty
module decisions.

- [x] **Step 3: Implement minimal rendering**

`nvl:suite:configure --profile=<name> --add=<module> --remove=<module>
--minimal` prints a PHP overlay containing only `profile`, `include`, and
`exclude`. `--full` prints all resolved module booleans. Both default to stdout;
`--write` writes only after validation, and `--force` shows a unified diff.

- [x] **Step 4: Prove a KPO-equivalent selection**

Add a test selection with roots for KPO's application capabilities and assert
that dependency closure enables exactly the same seventeen modules while
leaving Primitives, Taxonomy, and Forms disabled. Do not add a `kpo` profile.

- [x] **Step 5: Verify suite boot and publishing**

Run:

```bash
php artisan test --compact tests/Feature/SuiteDiagnosticsTest.php tests/Feature/PackagePublishingContractTest.php
php artisan nvl:suite:configure --add=auth --add=pages --minimal
php artisan config:cache
php artisan nvl:suite:configuration --format=json
php artisan config:clear
```

Expected: cached and uncached module selections are identical.

- [x] **Step 6: Commit CR-28**

```bash
git add config/nvl-suite.php src tests/Feature/SuiteDiagnosticsTest.php tests/Feature/PackagePublishingContractTest.php docs/installation-profiles.md
git commit -m "feat(suite): support minimal module selections"
```

---

### Task 5 (CR-29): Add an embedded-application Auth preset and management adapter

**Files:**
- Create: `packages/nvl/auth/src/Enums/AuthIntegrationPreset.php`
- Create: `packages/nvl/auth/src/Console/Commands/AuthConfigureCommand.php`
- Create: `packages/nvl/auth/src/Console/Commands/AuthConfigurationCommand.php`
- Create: `packages/nvl/auth/src/Services/ConfiguredPolicyAuthManagementAccess.php`
- Create: `packages/nvl/auth/src/Services/AuthManagementAbilityCatalog.php`
- Modify: `packages/nvl/auth/config/nvl-auth.php`
- Modify: `packages/nvl/auth/src/Providers/AuthServiceProvider.php`
- Modify: `packages/nvl/auth/src/Console/Commands/AuthDoctorCommand.php`
- Modify: `packages/nvl/auth/tests/Feature/OperationalCommandsTest.php`
- Create: `packages/nvl/auth/tests/Feature/AuthConfigurationTest.php`
- Modify: `packages/nvl/auth/README.md`

**Interfaces:**
- Consumes: feature catalog, route ownership, configured User model, package management abilities, host Gate/policy abilities, and CR-27 drift output.
- Produces: dry-run configuration commands, `nvl-auth.services.management_access`, and one validated package-ability-to-host-ability map.

- [ ] **Step 1: Write failing embedded-host tests**

Create a host fixture with package routes disabled, a custom User model, selected
features, and three host policies. Assert it boots without seventeen explicit
`Gate::define('nvl-auth.*')` declarations, denies unmapped abilities, delegates
mapped abilities with the correct subject, and produces cache-safe config.

- [ ] **Step 2: Catalog every package management ability**

Move the hardcoded ability strings used by `LaravelGateAuthManagementAccess`
into `AuthManagementAbilityCatalog`. For each ability declare required feature,
operation, expected subject kind, and safe default mapping. Doctor must prove
that every enabled management workflow has a resolvable decision.

- [ ] **Step 3: Add the configurable adapter**

Add `nvl-auth.services.management_access` and bind it instead of hardcoding the
current adapter. `ConfiguredPolicyAuthManagementAccess` reads a validated map:

```php
'management' => [
    'access' => ConfiguredPolicyAuthManagementAccess::class,
    'abilities' => [
        'roles.viewAny' => 'viewAny',
        'roles.create' => 'create',
        'roles.update' => 'update',
        'roles.delete' => 'delete',
    ],
    'policy_models' => [
        'roles' => Role::class,
        'permissions' => Permission::class,
    ],
],
```

Keys are package catalog aliases, not arbitrary Gate names received over HTTP.
Missing mappings deny. A custom `AuthManagementAccess` class remains supported
for domains whose policy mapping is more complex.

- [ ] **Step 4: Add ownership-aware Auth configuration commands**

`nvl:auth:configure --preset=embedded-application --user-model=<class>` prints a
minimal overlay with package HTTP routes disabled, host-owned delivery selected,
the configured User model, and only explicitly selected feature overrides.
`--write`/`--force` follow CR-28 safety. `nvl:auth:configuration --format=json`
reports feature state, route ownership, models, adapters, management coverage,
and CR-27 key-path drift; it reports no secrets or scalar config values.

- [ ] **Step 5: Extend Doctor for ownership conflicts**

Detect enabled package and host routes with the same purpose, absent host route
evidence for enabled host-owned flows as warnings, missing management decisions,
invalid custom models, and non-container-resolvable adapters. Do not require a
package route when a flow is deliberately service-only.

- [ ] **Step 6: Verify Auth quality and cached configuration**

Run:

```bash
php tools/run-package-quality.php auth
php artisan nvl:auth:configure --preset=embedded-application --user-model=App\\Models\\User
php artisan config:cache
php artisan nvl:auth:configuration --format=json
php artisan nvl:auth:doctor --strict --format=json
php artisan config:clear
```

Expected: package tests pass and the fixture has complete deny-by-default
management coverage without per-ability Gate registration.

- [ ] **Step 7: Commit CR-29**

```bash
git add packages/nvl/auth/config packages/nvl/auth/src packages/nvl/auth/tests packages/nvl/auth/README.md
git commit -m "feat(auth): add embedded application integration preset"
```

---

### Task 6 (CR-30): Simplify KPO configuration and Auth composition without behavior drift

**Files (KPO repository):**
- Modify: `config/nvl-suite.php`
- Modify: `config/nvl-auth.php`
- Modify: `config/activity.php`
- Modify: `config/comments.php`
- Modify: `config/content.php`
- Modify: `config/mail-notifications.php`
- Modify: `config/media.php`
- Modify: `config/nvl-data.php`
- Modify: `config/pages.php`
- Modify: `config/settings.php`
- Modify: `config/templates.php`
- Modify: `config/translatable.php`
- Modify: `config/translations.php`
- Modify: `app/Providers/NvlSuiteServiceProvider.php`
- Create or modify: `app/Support/Auth/KpoAuthManagementAccess.php`
- Modify: `tests/Feature/Auth/NvlSuiteIntegrationTest.php`
- Create: `tests/Feature/Package/NvlConfigurationOverlayTest.php`
- Modify: `tests/Feature/Package/NvlSuiteConsumptionGuidesTest.php`

**Interfaces:**
- Consumes: CR-27 through CR-29 commands and diagnostics.
- Produces: minimal KPO config overlays, one Auth authorization adapter/mapping, and unchanged KPO-owned routes/UI/business policies.

- [ ] **Step 1: Freeze current effective behavior**

Before editing, capture JSON from Suite/Auth/Comments configuration and Doctor,
the 422-route inventory, 46 `nvl.*` route names, enabled feature matrix, provider
bindings, scheduled commands, and focused test output. Store only assertions in
tests; do not commit environment-specific snapshots or secrets.

- [ ] **Step 2: Reduce suite and package files one at a time**

For each config file, use upgrade-check to classify intentional overrides,
remove copied defaults, run `config:cache`, compare the effective behavior
assertions, then commit that package separately. Retain KPO target resolvers,
models, policy classes, operational queue/storage choices, routes, feature
choices, and business catalogs. Remove obsolete keys only after strict
upgrade-check identifies their current replacement.

Do not treat `config/activitylog.php` as an NVL published overlay: it configures
the underlying Spatie dependency and keeps KPO's custom Activity model/table.
Retain it unless a separate Spatie configuration audit proves a change is safe.

- [ ] **Step 3: Replace repetitive Auth gates**

Use the configured policy adapter when its subject mapping expresses KPO's
existing policies. If any decision depends on KPO context that the declarative
adapter cannot safely carry, implement one `KpoAuthManagementAccess` contract
instead. Delete the seventeen bridge `Gate::define` calls only after equivalence
tests cover allow and deny cases for every enabled Auth management ability.

- [ ] **Step 4: Keep host ownership explicit**

Do not move KPO route files, Inertia pages, controllers, mailables, invitation
metadata providers, Activity mappings, or policies into the package. Reduce
`NvlSuiteServiceProvider` to KPO-specific bindings and registrations that no
package configuration/registry can own.

- [ ] **Step 5: Run KPO's focused and operational gate**

```bash
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan schedule:list
php artisan nvl:suite:configuration --format=json
php artisan nvl:suite:upgrade:check --strict --format=json
php artisan nvl:auth:doctor --strict --format=json
php artisan nvl:comments:doctor --strict --format=json
php artisan test --compact tests/Feature/Auth/NvlSuiteIntegrationTest.php tests/Feature/Auth/AuthenticationTest.php tests/Feature/Package/NvlConfigurationOverlayTest.php tests/Feature/Package/NvlSuiteConsumptionGuidesTest.php
php artisan route:clear
php artisan config:clear
```

The suite-wide strict Doctor may remain blocked only by the separately tracked
Media persisted-path adoption finding until CR-17/CR-18 migration completes;
record that exact finding and do not suppress it.

- [ ] **Step 6: Commit CR-30 as reversible KPO waves**

Commit suite selection, generic config overlays, Auth overlay, and provider/gate
simplification separately. Each commit must pass the focused gate and be safely
revertible without a schema rollback.

### Workstream acceptance gate

- [ ] `php tools/run-package-quality.php auth comments content pages translations` passes from the suite root.
- [ ] A clean consumer can render and cache a minimal configuration without publishing package defaults.
- [ ] Upgrade-check detects all KPO-observed obsolete/missing/redundant key shapes without exposing values.
- [ ] KPO retains the same seventeen enabled modules, Auth features, route ownership, policies, and user-visible behavior.
- [ ] KPO no longer maintains seventeen Auth bridge gates or hundreds of copied default config lines.
