# Consumer Guardrails and Adoption Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Define the supported consumer boundary, detect violations, and make module adoption explicit without breaking 1.x applications.

**Architecture:** A token-based source scanner resolves PHP imports and recognizes package model queries/writes without evaluating host code. Suite configuration inspection remains the runtime source of truth; new configure and upgrade-check commands operate from `SuiteModuleCatalog` and write only after an explicit flag.

**Tech Stack:** PHP 8.4 `PhpToken`, Laravel 13 console/config/filesystem, Pest 4.

**Spec:** `docs/superpowers/specs/2026-08-28-consumer-readiness-v2-design.md`

## Global Constraints

- All 1.x work is additive and preserves documented model APIs.
- The audit never evaluates consumer PHP, reads secrets, or mutates the consumer.
- Every finding has a stable code, severity, package, relative path, line, message, and remediation.
- Suppressions identify a finding code and symbol with a human reason; free-form regex suppression is rejected.
- Missing module flags continue to enable modules in 1.x unless strict explicit decisions are enabled.
- Configuration is written only when `nvl:suite:configure` receives `--write`.
- No external dependency is added.

---

### Task 1 (CR-01): Unify the consumer boundary doctrine

**Files:**
- Modify: `docs/consumer-readiness.md`
- Modify: `tools/consumer-readiness.php`
- Modify: `tests/Contract/ConsumerReadinessTest.php`
- Modify: `docs/adoption-matrix.md`

**Interfaces:**
- Consumes: the decision in the design spec's “Consumer boundary doctrine”.
- Produces: one machine-checked classification used by the audit messages and all later package docs.

- [x] **Step 1: Add a failing contract assertion for the four policy classes**

```php
expect($document)->toContain(
    '**Allowed:**',
    '**Compatibility-only in 1.x:**',
    '**Forbidden:**',
    '**Explicit exceptions:**',
);
```

- [x] **Step 2: Run the focused contract test and confirm the old doctrine fails**

Run: `php artisan test --compact tests/Contract/ConsumerReadinessTest.php`

Expected: FAIL because the rendered document does not yet contain the four
canonical policy classes.

- [x] **Step 3: Replace conflicting model-language in the catalog and rendered document**

Use these exact classifications:

```php
'allowed' => 'Actions, explicit services, contracts, DTOs, enums, owner traits, and documented identity/result models.',
'compatibility_1x' => 'Consumer-initiated package model queries and relation aggregates remain supported only where already documented.',
'forbidden' => 'Consumer writes through package models, builders, raw tables, pivots, or storage paths.',
'exceptions' => 'Filterable consumer builders, Translatable opted-in scopes, adoption migrations, and documented legacy bridges.',
```

Update every package row so the package's canonical entry point and any model
exception agree with these definitions. Add the same summary to the adoption
matrix introduction.

- [x] **Step 4: Run contract and formatting checks**

Run: `php artisan test --compact tests/Contract/ConsumerReadinessTest.php tests/Contract/SuiteAdoptionDocumentationTest.php`

Expected: PASS.

Run: `vendor/bin/pint --dirty --format agent`

Expected: exit 0.

- [x] **Step 5: Commit CR-01** (`ead83c6`)

```bash
git add docs/consumer-readiness.md docs/adoption-matrix.md tools/consumer-readiness.php tests/Contract/ConsumerReadinessTest.php
git commit -m "docs: define suite consumer boundary"
```

### Task 2 (CR-02): Add the consumer source auditor

**Files:**
- Create: `src/Support/ConsumerAuditFinding.php`
- Create: `src/Services/ConsumerAudit/PhpImportMap.php`
- Create: `src/Services/ConsumerAudit/ComposerSourceRootLocator.php`
- Create: `src/Services/ConsumerAudit/PhpConsumerBoundaryScanner.php`
- Create: `src/Services/ConsumerAudit/SuiteRuntimeConsumerScanner.php`
- Create: `src/Services/SuiteConsumerAuditor.php`
- Create: `src/Console/Commands/SuiteConsumerAuditCommand.php`
- Create: `tests/Fixtures/consumer-audit/app/AllowedOwner.php`
- Create: `tests/Fixtures/consumer-audit/app/UnsafeRoleReader.php`
- Create: `tests/Fixtures/consumer-audit/database/migrations/2026_01_01_000000_duplicate_auth_table.php`
- Create: `tests/Feature/SuiteConsumerAuditTest.php`
- Modify: `config/nvl-suite.php`
- Modify: `src/SuiteServiceProvider.php`
- Modify: `tests/Contract/PackageArchiveToolsTest.php`

**Interfaces:**
- Consumes: CR-01 classifications and `SuiteModuleCatalog::modules()`.
- Produces: `SuiteConsumerAuditor::audit(string $basePath): array` returning `list<ConsumerAuditFinding>` and the `nvl:suite:consumer-audit` command.

- [x] **Step 1: Write failing DTO and scanner tests**

```php
it('reports package model queries with stable source locations', function (): void {
    $findings = app(SuiteConsumerAuditor::class)->audit(
        base_path('tests/Fixtures/consumer-audit'),
    );

    expect($findings)->toContainOnlyInstancesOf(ConsumerAuditFinding::class)
        ->and(collect($findings)->firstWhere('code', 'consumer.package_model_query'))
        ->toMatchArray([
            'package' => 'auth',
            'path' => 'app/UnsafeRoleReader.php',
            'symbol' => 'Nvl\\Auth\\Models\\Role::query',
        ]);
});

it('does not flag a documented owner trait relationship', function (): void {
    $codes = collect(app(SuiteConsumerAuditor::class)->audit(
        base_path('tests/Fixtures/consumer-audit'),
    ))->where('path', 'app/AllowedOwner.php')->pluck('code');

    expect($codes)->toBeEmpty();
});

it('reports every runtime and generated-artifact adoption failure with stable codes', function (): void {
    $codes = collect(app(SuiteConsumerAuditor::class)->audit(
        base_path('tests/Fixtures/consumer-audit'),
    ))->pluck('code');

    expect($codes)->toContain(
        'consumer.missing_auth_binding',
        'consumer.unsafe_management_route',
        'consumer.missing_required_schedule',
        'consumer.stale_generated_contract',
        'consumer.stale_suite_skill',
    );
});
```

- [x] **Step 2: Run the new test and verify missing classes fail**

Run: `php artisan test --compact tests/Feature/SuiteConsumerAuditTest.php`

Expected: FAIL because `SuiteConsumerAuditor` and `ConsumerAuditFinding` do not
exist.

- [x] **Step 3: Implement deterministic PHP import and static-call scanning**

`ConsumerAuditFinding` is a final readonly value object with this constructor:

```php
public function __construct(
    public string $code,
    public string $severity,
    public ?string $package,
    public string $path,
    public ?int $line,
    public string $symbol,
    public string $message,
    public string $remediation,
) {}
```

`PhpImportMap` uses `PhpToken::tokenize($source, TOKEN_PARSE)` to resolve
namespace and `use` aliases. `PhpConsumerBoundaryScanner` recognizes static
calls on resolved `Nvl\\<Package>\\Models\\*` symbols. Classify `query`, `where`,
`whereIn`, `find`, `findOrFail`, `first`, `firstOrFail`, and `with` as
`consumer.package_model_query`; classify `create`, `updateOrCreate`, `upsert`,
`insert`, `save`, `delete`, `forceDelete`, `restore`, and `truncate` as
`consumer.package_model_write`. Record the method token's source line.

Do not flag model type hints, DTO `fromModel()` calls, model constants, package
Actions, owner traits, Filterable, Translatable, test factories, or files under
`vendor/`, `storage/`, `bootstrap/cache/`, `node_modules/`, and `.git/`.

`ComposerSourceRootLocator` reads only the consumer's root `composer.json` and
normalizes every local directory in `autoload.psr-4`, `autoload.classmap`, and
configured module roots. Include `app`, `Modules`, root/package migration
directories, routes, and config when present; exclude `autoload-dev`, tests,
factories, fixtures, and generated caches. Explicit `consumer_audit.paths`
extends these discovered roots rather than replacing them, so modular Laravel
applications cannot silently escape the scan.

- [x] **Step 4: Add migration/table checks and strict command output**

`SuiteConsumerAuditor` combines source findings with migration checks derived
from the package table definitions in `tools/package-contracts.json`. References
inside consumer adoption migrations are warnings; a consumer migration that
creates an enabled package-owned table is
`consumer.duplicate_package_migration`; raw table references elsewhere are
`consumer.package_table_reference`.

Command signature:

```php
protected $signature = 'nvl:suite:consumer-audit
    {path? : Consumer application root; defaults to base_path()}
    {--strict : Fail for errors and strict adoption warnings}
    {--format=table : table or json}';
```

Exit 0 for a clean report, 1 for findings that fail the selected mode, and 2
for invalid path/format/configuration. JSON must never include source contents
or configuration values.

In 1.x, `consumer.package_model_query` is a compatibility warning and
`consumer.implicit_module_decision` is an adoption warning. Package model
writes, raw/package-table references, duplicate migrations, missing security or
schedule requirements, unsafe routes, and stale generated artifacts/skills are
errors. `--strict` additionally fails the implicit-decision warning only when
`adoption.require_explicit_module_decisions` is true; it reports but does not
fail compatibility-query warnings until CR-24 changes the 2.0 policy.

- [x] **Step 5: Add booted runtime and generated-artifact checks**

`SuiteRuntimeConsumerScanner` consumes the secret-free
`SuiteConfigurationInspector::inspect()` report and the application route
collection. Emit `consumer.missing_auth_binding` for each enabled module
authorization/access contract whose implementation starts with `unresolvable:`;
scanner, storage, resolver, and context-provider contracts remain Suite Doctor
findings rather than being mislabeled as authorization failures. Emit
`consumer.missing_required_schedule` for each required schedule whose
`registered` field is false.

For every enabled package management route, resolve its owning module from the
route action namespace and the module route metadata added to
`tools/consumer-readiness.php`. Emit `consumer.unsafe_management_route` when
the route has no authentication middleware (`auth`, `auth:*`, or an explicitly
configured equivalent) or its package Doctor reports the management
authorization check as unhealthy. The finding symbol is the route name, or the
HTTP-method/URI pair when unnamed; never report middleware arguments.

When Data is enabled, run `nvl:data:types:check --fail-on-warning` through the
application console with buffered output and emit
`consumer.stale_generated_contract` on a non-zero result. Call
`SuiteSkillManager::inspect(strict: true)` directly and emit
`consumer.stale_suite_skill` for each unhealthy managed skill. Do not include
captured command output, generated content, absolute paths, exception messages,
or configuration values in findings. Add fixture providers/routes/schedules,
an isolated generated-types directory, and a temporary Suite skill destination
so all five codes are tested without reading the developer workstation state.

- [x] **Step 6: Add exact suppression validation**

Add this configuration shape:

```php
'consumer_audit' => [
    'paths' => ['app', 'config', 'database/migrations', 'routes'],
    'suppressions' => [],
],
```

Each suppression must contain non-empty `code`, `path`, `symbol`, and `reason`.
Reject unknown finding codes, absolute paths, `..`, globs, regex delimiters, and
empty reasons with command exit 2.

- [x] **Step 7: Run focused and distribution tests**

Run: `php artisan test --compact tests/Feature/SuiteConsumerAuditTest.php tests/Contract/PackageArchiveToolsTest.php`

Expected: PASS, including table and JSON output, strict exits, exclusions,
suppression validation, and archive membership.

Run: `vendor/bin/pint --dirty --format agent`

Expected: exit 0.

- [x] **Step 8: Commit CR-02** (`bfae7ef`)

```bash
git add src/Support/ConsumerAuditFinding.php src/Services/ConsumerAudit src/Services/SuiteConsumerAuditor.php src/Console/Commands/SuiteConsumerAuditCommand.php src/SuiteServiceProvider.php config/nvl-suite.php tests/Fixtures/consumer-audit tests/Feature/SuiteConsumerAuditTest.php tests/Contract/PackageArchiveToolsTest.php
git commit -m "feat: audit suite consumer boundaries"
```

Implementation notes: release-time table and management-route metadata comes
from the shipped `SuiteModuleCatalog`; `tools/consumer-readiness.php` mirrors
that metadata under a contract test because tooling files are excluded from
Composer archives. The scanner additionally follows local package-model
variables and builder chains so forbidden instance writes are not understated
as compatibility queries. Runtime checks run only when the audited path is the
booted application; JSON reports `runtime_checked` so an external static audit
cannot imply that another application's container, routes, schedules, types,
or managed skills were inspected.

### Task 3 (CR-03): Track explicit module decisions

**Files:**
- Modify: `config/nvl-suite.php`
- Modify: `src/Support/SuiteModuleCatalog.php`
- Modify: `src/Services/SuiteConfigurationInspector.php`
- Modify: `src/Console/Commands/SuiteDoctorCommand.php`
- Modify: `src/Services/SuiteConsumerAuditor.php`
- Modify: `tests/Feature/SuiteDiagnosticsTest.php`
- Modify: `tests/Feature/SuiteConsumerAuditTest.php`

**Interfaces:**
- Consumes: `SuiteModuleCatalog::modules()` and the CR-02 finding DTO.
- Produces: `SuiteModuleCatalog::moduleDecision(string $module): 'enabled'|'disabled'|'implicit'` and an `explicit` field in configuration reports.

- [ ] **Step 1: Write failing catalog and Doctor tests**

```php
it('distinguishes explicit and implicit module decisions', function (): void {
    config()->set('nvl-suite.modules', ['auth' => true, 'forms' => false]);
    $catalog = app(SuiteModuleCatalog::class);

    expect($catalog->moduleDecision('auth'))->toBe('enabled')
        ->and($catalog->moduleDecision('forms'))->toBe('disabled')
        ->and($catalog->moduleDecision('pages'))->toBe('implicit');
});

it('fails strict adoption when explicit decisions are required', function (): void {
    config()->set('nvl-suite.adoption.require_explicit_module_decisions', true);
    config()->set('nvl-suite.modules', ['auth' => true]);

    expect(Artisan::call('nvl:suite:doctor', ['--strict' => true]))->toBe(1);
});
```

- [ ] **Step 2: Run the diagnostics tests and verify the missing method fails**

Run: `php artisan test --compact tests/Feature/SuiteDiagnosticsTest.php`

Expected: FAIL because `moduleDecision()` does not exist.

- [ ] **Step 3: Implement the staged 1.x decision behavior**

Add:

```php
'adoption' => [
    'require_explicit_module_decisions' => false,
],
```

Keep `effectiveModules()` and `requested()` behavior unchanged in 1.x. Add
`moduleDecision()` based only on key presence and boolean value. The inspector
adds `decision` and `explicit` to every module without leaking configuration.
Doctor emits `module.<name>.explicit_decision` as warning when omitted; strict
mode fails that warning only when the flag is true. CR-02 emits
`consumer.implicit_module_decision` with the same policy.

- [ ] **Step 4: Prove compatibility and strict behavior**

Run: `php artisan test --compact tests/Feature/SuiteDiagnosticsTest.php tests/Feature/SuiteConsumerAuditTest.php tests/Contract/PackageArchiveToolsTest.php`

Expected: PASS; omitted flags still enable modules when the adoption flag is
false, and both Doctor/audit fail when strict explicit decisions are enabled.

- [ ] **Step 5: Commit CR-03**

```bash
git add config/nvl-suite.php src/Support/SuiteModuleCatalog.php src/Services/SuiteConfigurationInspector.php src/Console/Commands/SuiteDoctorCommand.php src/Services/SuiteConsumerAuditor.php tests/Feature/SuiteDiagnosticsTest.php tests/Feature/SuiteConsumerAuditTest.php
git commit -m "feat: diagnose implicit suite modules"
```

### Task 4 (CR-04): Add configure and upgrade-check commands

**Files:**
- Create: `src/Services/SuiteConfigurationRenderer.php`
- Create: `src/Console/Commands/SuiteConfigureCommand.php`
- Create: `src/Console/Commands/SuiteUpgradeCheckCommand.php`
- Create: `tests/Feature/SuiteConfigurationWriterTest.php`
- Modify: `src/SuiteServiceProvider.php`
- Modify: `docs/installation-profiles.md`
- Modify: `docs/adoption-matrix.md`
- Modify: `tests/Contract/SuiteAdoptionDocumentationTest.php`
- Modify: `tests/Contract/PackageArchiveToolsTest.php`

**Interfaces:**
- Consumes: `SuiteModuleCatalog::profileModules()`, `modules()`, `moduleDecision()`, and the published `config/nvl-suite.php` path.
- Produces: dry-run-first `nvl:suite:configure` and read-only `nvl:suite:upgrade:check` commands.

- [ ] **Step 1: Write failing command behavior tests**

```php
it('renders a dependency-complete profile without writing by default', function (): void {
    $path = storage_path('framework/testing/nvl-suite.php');
    File::delete($path);

    expect(Artisan::call('nvl:suite:configure', [
        '--profile' => 'auth-only',
        '--path' => $path,
        '--format' => 'json',
    ]))->toBe(0)
        ->and(File::exists($path))->toBeFalse();
});

it('reports modules absent from a published config', function (): void {
    $path = fixtureSuiteConfigWithOnly('auth');

    expect(Artisan::call('nvl:suite:upgrade:check', [
        '--path' => $path,
        '--format' => 'json',
    ]))->toBe(1);
});
```

- [ ] **Step 2: Run the new test and verify both commands are missing**

Run: `php artisan test --compact tests/Feature/SuiteConfigurationWriterTest.php`

Expected: FAIL because the command names are not registered.

- [ ] **Step 3: Implement canonical rendering and explicit writes**

Command signatures:

```php
nvl:suite:configure {--profile=} {--add=*} {--path=} {--write} {--format=table}
nvl:suite:upgrade:check {--path=} {--format=table} {--strict}
```

`SuiteConfigurationRenderer` starts with every catalog module set to `false`,
enables the requested profile and `--add` modules, resolves dependencies, and
renders every module key in canonical catalog order. Unknown profiles/modules,
non-PHP destinations, paths outside `base_path()`, conflicting arguments, and
unreadable existing files exit 2. `--write` uses `Filesystem::replace()` after
showing the exact enabled/disabled module list; it never merges arbitrary PHP.

Upgrade check loads only the returned array from the selected config file,
reports missing/unknown/non-boolean keys, new required schedules, migration
ownership changes, and newly required contracts from the current catalog. It
never writes.

- [ ] **Step 4: Document reproducible adoption commands**

Add these verified examples:

```bash
php artisan nvl:suite:configure --profile=content-platform
php artisan nvl:suite:configure --profile=content-platform --write
php artisan nvl:suite:upgrade:check --strict
php artisan nvl:suite:consumer-audit --strict
```

Explain exit codes 0/1/2 and the 1.x explicit-decision switch.

- [ ] **Step 5: Run focused, contract, and archive tests**

Run: `php artisan test --compact tests/Feature/SuiteConfigurationWriterTest.php tests/Feature/SuiteDiagnosticsTest.php tests/Contract/SuiteAdoptionDocumentationTest.php tests/Contract/PackageArchiveToolsTest.php`

Expected: PASS.

Run: `vendor/bin/pint --dirty --format agent`

Expected: exit 0.

- [ ] **Step 6: Commit CR-04**

```bash
git add src/Services/SuiteConfigurationRenderer.php src/Console/Commands/SuiteConfigureCommand.php src/Console/Commands/SuiteUpgradeCheckCommand.php src/SuiteServiceProvider.php tests/Feature/SuiteConfigurationWriterTest.php docs/installation-profiles.md docs/adoption-matrix.md tests/Contract/SuiteAdoptionDocumentationTest.php tests/Contract/PackageArchiveToolsTest.php
git commit -m "feat: guide suite adoption upgrades"
```

### Workstream acceptance gate

- [ ] Run `vendor/bin/pint --dirty --format agent`.
- [ ] Run `php artisan test --compact tests/Feature/SuiteConsumerAuditTest.php tests/Feature/SuiteConfigurationWriterTest.php tests/Feature/SuiteDiagnosticsTest.php tests/Contract/ConsumerReadinessTest.php tests/Contract/SuiteAdoptionDocumentationTest.php tests/Contract/PackageArchiveToolsTest.php`.
- [ ] Run `composer analyse` and `composer contracts:check`.
- [ ] Run the audit against KPO in non-strict JSON mode and save only the finding summary, never KPO source contents.
- [ ] Confirm the audit identifies the known Role/Permission, Media concern, and listener seams without flagging owner-trait declarations.
