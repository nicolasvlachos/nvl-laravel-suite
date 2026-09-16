# Tenancy Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the inert-by-default Tenancy library, explicit adoption, and safe execution boundaries consumed by the other package plans.

**Architecture:** Tenancy owns its contracts and runtime. Packages register immutable resource definitions and their own adoption adapters. A shared connection, scoped context, explicit admission, and persisted adoption markers establish the common boundary.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4, Testbench 11; existing Composer dependencies only.

**Spec:** [Design](../specs/2026-09-16-configurable-tenancy-design.md), [frozen contracts](../specs/2026-09-16-tenancy-execution-contracts.md), [execution order](2026-09-16-tenancy-program.md).

## Global Constraints

- PHP 8.4 and Laravel 13 are the execution baseline.
- No new external Composer dependency is required.
- All tenancy types and runtime logic live in `Nvl\Tenancy`, including the disabled implementation. Support remains free of tenant domain logic.
- Missing context in enabled mode fails closed. Platform access, central identity use cases, and tenant operations are explicit and separate.
- Released migrations are immutable. Optional migration sets use distinct paths and explicit registration.
- Run package tests serially: the current Testbench bootstrap/cache is shared.
- All paths below are repository-relative. Files marked Create do not exist yet.

## File ownership and verification convention

New production files belong under `packages/nvl/tenancy/`. Public names and exact
signatures in the contracts document are binding. This plan adds private runtime
classes, middleware and commands; consumers must not depend on those internals.
Scaffold classes with Artisan into a temporary package workbench when the root
generator would incorrectly place them in `app/`; move generated files into the
specified package path and set their namespace. Do not create production models
in the workbench database.

For every task, run its named test red, implement, then run it green using:

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= CACHE_STORE=array QUEUE_CONNECTION=sync APP_ENV=testing php vendor/bin/pest --test-directory=packages/nvl/tenancy/tests --configuration=packages/nvl/tenancy/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/tenancy/tests/Feature/<named-test>.php
```

The angle-bracket filename in this command is a substitution instruction; each
task names the concrete file. Run `vendor/bin/pint --dirty --format agent` before
each PHP commit. Commit only that task's listed files and its required manifest,
contract, skill and documentation updates, after inspecting `git diff --cached`.

## F1: Installable package, configuration and disabled compatibility

**Files:**

- Create: `packages/nvl/tenancy/composer.json`, `phpunit.xml.dist`, `phpstan.neon.dist`, `LICENSE`, `README.md`, `CHANGELOG.md`, `UPGRADING.md`, `SECURITY.md`, `CONTRIBUTING.md`.
- Create: `packages/nvl/tenancy/config/tenancy.php`, `src/Providers/TenancyServiceProvider.php`, `src/Services/TenancyConfiguration.php`.
- Create: the context values/interfaces and exception classes named in contracts §3–4, plus `src/Services/ScopedTenantContext.php`.
- Create: `packages/nvl/tenancy/resources/boost/skills/backend-tenancy/SKILL.md`, `tests/TenancyTestCase.php`, `tests/Pest.php`, `tests/Feature/DisabledCompatibilityTest.php`.
- Modify: `composer.json`, `src/Support/SuiteModuleCatalog.php`, `src/Services/SuitePackageConfigurationInspector.php`, `tools/package-family.php`, `tools/package-contracts.json` and root module-count/configuration contract tests.

**Interfaces:** Produces `TenantContext`, immutable context values and typed
configuration validation. `TenancyConfiguration::validate(): void` validates
structure without opening a database or resolving scoped adapters in boot.

- [ ] Add the new namespace to root production/test autoload; adapt the Support package manifest and Testbench configuration to the new package. Use PHP `^8.4`, framework `^13.0`, and suite-compatible `^2.0` internal requirements for Support/Data. Use the existing MIT LICENSE content, then run family validation.
- [ ] Write this failing test with the exact imports named below; `TenancyTestCase` registers Support, Data, and Tenancy providers, uses SQLite memory, and sets `tenancy.enabled=false` without loading Tenancy migrations:

```php
use Illuminate\Support\Facades\Schema;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;

it('registers the library without activating tenancy or installing schema', function (): void {
    expect(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Disabled)
        ->and(config('tenancy.enabled'))->toBeFalse()
        ->and(Schema::hasTable('nvl_tenancy_tenants'))->toBeFalse();
});
```

- [ ] Run `DisabledCompatibilityTest.php`; expect missing provider/type before implementation.
- [ ] Implement the exact config array from contracts §2. Use `MergesPackageConfiguration`, register the context with `scopedIf`, and declare config/skill publish tags. Register commands in console only. Do not auto-load any new migrations. Keep the actual mutable snapshot on `ScopedTenantContext`; expose only the read-only interface to package callers.

```php
$this->mergePackageConfiguration(__DIR__.'/../../config/tenancy.php', 'tenancy');
$this->app->scopedIf(TenantContext::class, ScopedTenantContext::class);
```

- [ ] Add validator cases for unknown strategy/profile/family, non-boolean enablement, invalid sharing, closures in cached config, and contradictory explicit adapter/binding. Initially the application profile may contain no integrated roots; later package registration adds them. Configuration class strings must implement their declared contracts.
- [ ] Add Tenancy to the Suite catalog after Support/Data. Update family metadata, Composer replace/autoload, provider/config/skill discovery and exact package counts from 20 to 21; do not add other packages' dependency edges until their integration task. Preserve the legacy omitted-module rule. Check a standalone Tenancy archive and a Media-only consumer at its later integration gate.
- [ ] Run the named test, root configuration/module tests, `php tools/validate-package-family.php`, `php tools/check-package-contracts.php` (update its baseline only after reviewing the intentional new API), and Composer validation. Commit `feat(tenancy): add inert configurable package foundation`.

## F2: Directory, context runner and admission

**Files:**

- Create: `src/Contracts/TenantDirectory.php`, `TenantMembershipAccess.php`, `PlatformAccess.php`, `TenantHttpResolver.php`, `TenantSiteResolver.php`, `TenantContextParticipant.php`.
- Create: `src/ValueObjects/TenantDescriptor.php`, `TenantSiteContext.php`, `PlatformOperation.php`; `src/Enums/TenantStatus.php`.
- Create: `src/Services/TenantRunner.php`, `TenantMaintenanceRunner.php`, `TenantContextParticipants.php`, `EffectiveTenantConnection.php`, `PackageTenantDirectory.php`.
- Create: `src/Services/DenyTenantMembershipAccess.php`, `DenyPlatformAccess.php`.
- Create: `src/Http/Middleware/RequireTenantMembership.php`, `ResolvePublicTenant.php`.
- Create: `tests/Fixtures/ArrayTenantDirectory.php`, `tests/Fixtures/TestPlatformAccess.php`, `tests/Feature/TenantRunnerTest.php`, `tests/Feature/TenantAdmissionTest.php`.

**Interfaces:** Consumes F1. Produces the directory, runner, site and participant
contracts. Additional `TenantHttpResolver::resolve(Request $request): TenantId`
selects a candidate; it never grants membership. `EffectiveTenantConnection::name(?string $connection): string`
resolves Laravel's configured default; `assertCompatible(array $connections): void`
compares resolved connection identities before writes. Two separately named connections
with identical DSNs may still use separate PDO transactions; reject them unless
they are explicitly canonicalized to the same Laravel connection instance.

- [ ] Define `ArrayTenantDirectory` with an array of `TenantDescriptor`s indexed by canonical ID; `find()` returns the descriptor or throws `TenantNotFound`. Define an explicitly allowing `TestPlatformAccess` that records authorized operations; no production equivalent may allow implicitly.
- [ ] Write the runner test below; bind that directory with two Active descriptors and set `tenancy.enabled=true` before resolving services. There are no registered resource models in this test, so no adopted resource schema is required:

```php
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;

it('restores context when nested work throws', function (): void {
    $a = new TenantId('10000000-0000-4000-8000-000000000001');
    $b = new TenantId('10000000-0000-4000-8000-000000000002');
    $runner = app(TenantRunner::class);
    $context = app(TenantContext::class);
    $runner->run($a, function () use ($runner, $context, $a, $b): void {
        expect(fn () => $runner->run($b, fn () => throw new RuntimeException('probe')))
            ->toThrow(RuntimeException::class, 'probe');
        expect($context->requireTenant()->value)->toBe($a->value);
    });
    expect($context->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
});
```

- [ ] Run `TenantRunnerTest.php`; expect missing runner. Implement this state transition in `run`: validate directory Active status; inspect transaction levels on participating effective connections; reject a different context inside a transaction; save snapshot; set next snapshot; enter registered participants and collect their restorers; execute callback; unwind restorers in reverse order and restore the old snapshot in `finally`. Preserve the original failure if cleanup also fails, report cleanup failure and invalidate the scope. No request object or permission model is cached globally.
- [ ] Implement `platform` with deny-by-default authorization and durable operation recording (F3 storage); until storage exists, authorized platform work fails `TenantSchemaNotReady`. Test unauthorized and missing-audit-store paths now, successful platform execution in F3.
- [ ] Implement `TenantMaintenanceRunner::run(TenantId, PlatformOperation, Closure): mixed` using the same restoration/transaction machinery, an internal tenant-specific lease, actual maintenance mode and PlatformAccess. Require durable audit; fail until F3 storage exists. Unknown IDs deny; suspended/deleted records are admitted only inside this synchronous recovery boundary. Test ordinary runner denial before/after it, revocation on exception, failed authorization, no maintenance mode and rejected queue dispatch. The lease never removes ownership predicates or becomes job metadata.
- [ ] Write admission tests with real HTTP routes: membership check precedes route model binding; missing actor denies; conflicting route/header/domain selections deny; public resolver admits only its verified tenant/site; suspended/unknown tenants return the same public not-found result. The host owns resolver implementations; defaults have no resolver and deny when middleware is selected.
- [ ] Implement middleware as resolver → membership/public admission → runner → next. Register priority before SubstituteBindings, after authentication for membership routes. Do not attach middleware globally or change central Auth routes. The resolved site object is request scoped and must agree with active tenant.
- [ ] Run both tests plus participant failure, same-tenant nested transaction, different-tenant transaction, effective default connection alias, host binding precedence, and same-worker retained-service cases. Commit `feat(tenancy): add scoped execution and tenant admission`.

## F3: Explicit core schema and provisioning

**Files:**

- Create: `database/migrations/tenancy/2026_09_16_000001_create_tenancy_core_tables.php`.
- Create: `src/Models/Tenant.php`, `src/Actions/ProvisionTenantAction.php`, `src/Actions/ChangeTenantStatusAction.php`, `src/Services/TenantOperationRecorder.php`.
- Create: `tests/Feature/TenantCoreSchemaTest.php`, `tests/Fixtures/InMemoryMaintenanceMode.php`.
- Modify: Tenancy provider, configuration validation, package README/UPGRADING.

**Interfaces:** `ProvisionTenantAction::execute(string $name, PlatformOperation $operation): TenantDescriptor`;
`ChangeTenantStatusAction::execute(TenantId $tenant, TenantStatus $status, PlatformOperation $operation): void`.
Both require PlatformAccess; host directory adapters without package write support
reject these package-storage operations. `TenantOperationRecorder::record(PlatformOperation $operation): void`
writes bounded actor/purpose/time facts before privilege is exercised.

- [ ] Add a failing schema test: default `migrate` never creates tenant tables; explicitly enabled migration registration creates all five core tables; enabling the migration path after ordinary package migrations still works. Run `TenantCoreSchemaTest.php` red.
- [ ] Implement core tables on `tenancy.connection`: tenants (`id` UUID PK, name, status, timestamps); installation state (`resource` PK, schema_version, state, configuration_hash, run_id, timestamps); operations (`id` UUID PK, actor_type, actor_id, purpose, timestamps); adoption runs (`id` UUID PK, status, mapping_hash, configuration_hash, packages JSON, checkpoints JSON, timestamps); adoption mappings (`run_id`, resource, record_id, tenant_id, metadata JSON, unique run/resource/record). Add named indexes and bounded lengths. Use foreign keys only to the package tenant directory when that configured directory owns the table; host directories get verified application references.
- [ ] Register the separate migration path only when `tenancy.migrations.enabled=true`, and expose a publish tag. Provider feature enablement alone never registers it. Operator permissions for provisioning run before insert; names are validated and IDs generated server-side. Status transitions lock canonical tenant rows and suspend new admission immediately; active workers recheck before side effects. Deletion status marks access unavailable; package data cleanup is P1, not a cascade here.
- [ ] Define the test-only maintenance adapter exactly:

```php
final class InMemoryMaintenanceMode implements \Illuminate\Contracts\Foundation\MaintenanceMode
{
    private ?array $payload = [];
    public function activate(array $payload): void { $this->payload = $payload; }
    public function deactivate(): void { $this->payload = null; }
    public function active(): bool { return $this->payload !== null; }
    public function data(): array { return $this->payload ?? []; }
}
```

- [ ] Test platform audit insertion, denied provisioning, unknown/suspended directory lookup and host directory storage rejection. Run named test and F2 suite green. Commit `feat(tenancy): add opt-in directory and adoption storage`.

## F4: Resource registry, persisted boundary and installation guard

**Files:**

- Create: `src/Enums/TenantResourceKind.php`, `src/ValueObjects/TenantResourceDefinition.php`.
- Create: `src/Services/TenantResourceRegistry.php`, `TenantBoundary.php`, `TenantInstallationState.php`, `TenantOwnershipConfiguration.php`.
- Create: `tests/Fixtures/OwnedRecord.php`, `tests/Feature/TenantBoundaryTest.php`, `tests/Feature/TenantInstallationGuardTest.php`.

**Interfaces:** All registry/boundary APIs in contracts §3–4. Internal
`TenantInstallationState::assertUsable(string $resource): void` enforces persisted
mode/schema; `invalidate(): void` clears this process's compatibility probe after
authorized schema changes. These are not supported consumer bypass APIs.

- [ ] Add `OwnedRecord`, table `tenancy_test_records` with UUID id/tenant_id and name. Register `tests.records` as root family `tests`. In isolated boundary tests create the real state through F5 once available; for F4 marker tests explicitly seed a prepared/active marker as the behavior being tested, with its calculated configuration hash. Do not reuse manual markers as downstream fixture activation.
- [ ] Add a failing test: tenant A cannot `assertRecord` a canonical B row, including a B object whose dirty `tenant_id` was changed to A. A missing context, unknown resource, conflicting duplicate registry entry, incompatible connection and prepared resource each fail. Run `TenantBoundaryTest.php` red.
- [ ] Implement predicates with qualified column names and grouped caller conditions. Derive inherited ownership from a registered canonical parent; detect cycles at registration. Never interpret an unknown parent as global. Root queries use `where(qualified_tenant_id, current_id)`; platform catalog queries use `whereNull(qualified_tenant_id)->where(qualified_ownership_key, 'platform')`. Platform context cannot list tenant roots. Platform-kind vocabulary reads are package-specific explicit operations.
- [ ] Implement canonical row checks via a minimal unscoped ownership lookup on the model's validated connection; compare persisted tenant/ownership columns and canonical parent IDs, never trust client attributes. This internal lookup returns only ownership facts and throws a uniform boundary error. Package writers reload/lock under tenant predicates before mutation to avoid races. Model observers alone are insufficient because raw/quiet writes bypass them.

```php
// Stable cache/lock identity algorithm after checking the resource/context.
$identityParts = [$connectionName, $resourceKey, $snapshot->mode->value,
    $snapshot->tenantId?->value, $identity];
$key = 'nvl:tenant:'.hash('sha256', json_encode($identityParts, JSON_THROW_ON_ERROR));
```

- [ ] Implement the lazy state probe before each integrated resource's first use per connection generation: table absent and feature off returns legacy behavior; absent and feature on fails; prepared/incompatible/off-but-active fails; active matching state allows. Never catch a connection/query failure as “table absent.” Provider omission cannot skip this service because integrated packages explicitly depend on its inert provider. Test changed config after adoption in a newly booted app, and bounded schema-probe query count separately from action query budgets.
- [ ] Run both named tests plus retained preloaded A model/relation in B, nullable platform ownership checks, disabled identity-key equality, unsupported family dependency modes and config-cache round-trip. Commit `feat(tenancy): enforce registered resource ownership and adoption state`.

## F5: Resumable package adoption and executable fixtures

**Files:**

- Create: adoption DTOs, `Contracts/TenantAdoptionAdapter.php`, `Services/TenantAdoptionRegistry.php`, `TenantAdoptionMappings.php`, `TenantAdoptionCoordinator.php` from contracts §4.
- Create: `src/Console/Commands/TenancyAdoptCommand.php`, `TenancyDoctorCommand.php`.
- Create: `tests/Fixtures/RecordAdoptionAdapter.php`, `tests/Feature/TenantAdoptionTest.php`, `tests/TenancyDatabaseTestCase.php`.
- Modify: provider, Suite Doctor/configuration inspection integration and related root tests.

**Interfaces:** Exact coordinator APIs in contracts. CLI:
`nvl:tenancy:adopt {phase : prepare|backfill|verify|activate} {--packages=} {--mapping=} {--run=} {--limit=500} {--actor-type=} {--actor-id=} {--purpose=}`;
`nvl:tenancy:doctor {--json}`. Doctor is read-only. An authorizer must authorize CLI
identities; supplying command options does not prove identity or privilege.

- [ ] Define `RecordAdoptionAdapter` for `tests.records`: prepare adds nullable tenant column if absent using its own migration; backfill reads stable primary-key batches and `tenantFor`; verify rejects missing ownership/unknown tenant; activate enforces the final non-null/index schema. It is a real test adapter exercising the public protocol, not a shortcut into active state.
- [ ] Create `TenancyDatabaseTestCase` directly from Testbench with `DatabaseMigrations`, core opt-in migrations and providers; never subclass a package case that already uses `RefreshDatabase`. Bind ArrayTenantDirectory, TestPlatformAccess and InMemoryMaintenanceMode. Downstream package cases reproduce this small setup and use their own real adapters.
- [ ] Write the adoption test:

```php
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\ValueObjects\PlatformOperation;

it('activates verified empty registered resources through the public coordinator', function (): void {
    app(TenantAdoptionRegistry::class)->register('tests', RecordAdoptionAdapter::class);
    $operation = new PlatformOperation('test adoption', 'test', 'operator-1');
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['tests'], [], $operation);
    expect($coordinator->backfill($plan, 50, $operation))->toBeTrue()
        ->and($coordinator->verify($plan)->passed())->toBeTrue();
    $coordinator->activate($plan, $operation);
    expect(DB::table('nvl_tenancy_installation_state')->where('resource', 'tests.records')->value('state'))
        ->toBe('active');
});
```

- [ ] Run `TenantAdoptionTest.php` red. Implement a per-connection adoption lock and persisted run state machine: authorize + maintenance check → normalize packages/dependencies/connections → stream and validate mapping → hash immutable sorted input/config → mark graph prepared → adapter prepare → bounded adapter batches/checkpoints → verify whole graph → activate adapter constraints → mark run/resources active together. DDL may commit independently; therefore active markers are written only after every adapter succeeds. Resumption verifies hashes and actual schema, and safely retries idempotent completed phases.
- [ ] Reject duplicate/conflicting assignments, unmapped nonempty roots, invalid parents, input mutation, unknown adapter, zero/oversized batch limit, connection mismatch and activation after failed verification. A backfill result must advance its cursor or declare completion; reject stuck progress. Validate tenant directory status without letting ordinary context scopes hide rows being adopted. Coordinator's internal maintenance grant is scoped to the registered adapter/run and never exposed as an HTTP flag.
- [ ] Add interruption tests after prepare, midway batch and after first adapter DDL. Resume must not duplicate work or expose a partially adopted graph. Capture markers before callbacks; invalidate local probe only after persisted changes. Prohibit concurrent ordinary writes throughout maintenance; rollout drains other processes and restarts them before reopening.
- [ ] Run named test and F1–F4 tests green. Document reviewed JSONL mapping schema (`resource`, `record_id`, `tenant_id`, optional package-validated `metadata`), dry Doctor report, backup, prepare/backfill/verify/activate and forward recovery. The maintenance command environment disables database-derived bootstrap overrides while schemas are prepared, so Settings cannot read blocked resources before the coordinator runs; ordinary request/job guards remain active. Restore the validated bootstrap configuration and rebuild caches only after activation/restart. Commit `feat(tenancy): coordinate explicit resumable resource adoption`.

## F6: Public configuration and cross-package dependency diagnostics

**Files:**

- Modify: `src/Services/TenancyConfiguration.php`, `TenantOwnershipConfiguration.php`, `TenantResourceRegistry.php`, `TenancyDoctorCommand.php`.
- Modify: root `src/Services/SuiteConfigurationInspector.php`, `src/Services/SuitePackageConfigurationInspector.php`, `src/Console/Commands/SuiteDoctorCommand.php`.
- Create: `tests/Feature/TenantConfigurationTest.php`, root `tests/Feature/TenancyCompositionTest.php`.

**Interfaces:** The application profile derives installed family ownership; the
registry's fixed parent declarations prohibit conflicting child modes. Package
integrations add code-owned dependency rules via
`TenantOwnershipConfiguration::requireCompatible(string $family, string $dependency): void`.

- [ ] Write failing config tests for application profile defaults, explicit platform family, nested deep-map merge, atomic resolver list replacement, typo rejection, host adapter precedence and config-cache serialization. Add a composition case rejecting tenant Pages with platform-only mutable Content dependencies, while a code-backed catalog remains allowed.
- [ ] Run both files red. Implement validation against registered family metadata after provider registration, keeping connection/schema probing lazy. Validate enabled installed-but-unintegrated stateful packages as incompatible with the application profile until their integration lands; a partial development checkout is not production activation-ready. Report feature state, provider selection, resource ownership, effective connection and schema/adoption status separately.
- [ ] Check arbitrary table prefixes/configured models, consumer-owned migrations, host directories and standalone imports in tests. Do not accept client resource-family overrides or silently synthesize modes for unregistered owners.
- [ ] Run both named tests, root Suite config/module selection tests and `php tools/validate-package-family.php`. Update `docs/adoption-matrix.md`, `docs/consumer-readiness.md`, root README, package docs/skills and contract manifest. Commit `feat(tenancy): validate suite ownership configuration and dependency closure`.

## F7: Queue restoration before deserialization and worker cleanup

**Files:**

- Create: `src/ValueObjects/TenantJobEnvelope.php`, `src/Services/TenantQueueContext.php`, `TenantQueuePayload.php`, `TenantGlobalJobRegistry.php`, `src/Queue/TenantCallQueuedHandler.php`.
- Create: `tests/Fixtures/ProbeTenantJob.php`, `tests/Fixtures/ProbeRestoredModel.php`, `tests/Feature/TenantQueueContextTest.php`, `tests/Integration/TenantWorkerTest.php`.
- Modify: provider and Doctor; package consumer documentation.

**Interfaces:** Contracts §4 envelope and queue APIs. Internal
`TenantQueuePayload::encode(TenantJobEnvelope): array` and `decode(array): TenantJobEnvelope`
use only version/mode/tenant ID scalar fields and reject unknown versions. The
global registry permits only specific trusted global-identity job classes.

- [ ] Add a probe job whose `__unserialize()` records current context before `handle()`, and whose `failed()` records it again. Add a failure-before-handle case with attempts exhausted. Red tests must prove a job middleware-only implementation would restore too late.
- [ ] Run `TenantQueueContextTest.php` red. Capture envelope at dispatch into payload `data` via `Queue::createPayloadUsing`, preserving other hooks. Validate and enter it before calling Laravel's handler in both methods:

```php
// Structure of the adapter, with signatures kept compatible with installed Laravel.
public function call(Job $job, array $data): void
{
    $this->withinPayload($data, fn () => parent::call($job, $data));
}
public function failed(array $data, mixed $e, string $uuid, ?Job $job = null): void
{
    $this->withinPayload($data, fn () => parent::failed($data, $e, $uuid, $job));
}
```

`withinPayload(array $data, Closure $operation): mixed` is a private method:
decode scalar metadata, select validated tenant envelope or explicitly registered
global identity class, enter its permitted context, delegate, restore in finally.
For global identity jobs, validate payload commandName against its specific
registration and use Unresolved mode; never allowlist generic mailable/notification
wrappers. For disabled envelopes, require the current feature to be disabled and verify
that every participating resource is unadopted; never downgrade an enabled worker. Reject queue dispatch from a TenantMaintenanceRunner lease; it cannot be serialized. Reject missing metadata in enabled tenant workflows before
deserializing either the main command or its failure command.

- [ ] Bind the adapter for Laravel's `CallQueuedHandler` only through a documented compatible binding; preserve a host implementation that explicitly composes the boundary, and fail Doctor for an incompatible one. String jobs/custom handlers need an explicit adapter; do not claim arbitrary handlers are supported. Package jobs use scalar IDs; supported host model serialization additionally validates restored canonical ownership, because Laravel's restoration query is unscoped.
- [ ] Test sync dispatch inside another tenant, after-commit capture, chains/batch callbacks, unique/overlap locks, exception paths, retry, exhausted attempts, model restoration and persisted-work-item mismatch. A malformed envelope must never cause `failed()` to deserialize a foreign model as part of framework error handling.
- [ ] In `TenantWorkerTest.php`, use a file-backed test SQLite database or configured real database and a real `queue:work --stop-when-empty --tries=1` subprocess against a temporary Testbench consumer with isolated bootstrap/cache/storage. Queue A, B, malformed and failing A jobs; assert probe rows record exact tenants, no foreign restoration occurred and final context is Unresolved. Also test two requests handled by the same application instance; scoped objects and retained package service references cannot leak the first context.
- [ ] Run named feature/integration tests and all foundation tests, then commit `feat(tenancy): restore queued context before command deserialization`.

## F8: Foundation release contract and early utility proof

**Files:**

- Create: `packages/nvl/tenancy/tests/Feature/TenantConsumerContractTest.php`, root `tests/Contract/TenancyConsumerWorkflowTest.php`.
- Modify: Tenancy docs/skill, root README/CHANGELOG/UPGRADING, `tools/package-family.php`, `tools/package-contracts.json`, `docs/consumer-readiness.md`, `docs/adoption-matrix.md`.
- Modify: existing Filterable predicate-preservation and Data mutation-input test files identified by the settings/tools plan, without adding a reverse dependency in their manifests.

**Interfaces:** No new runtime API. Produces a stable prerequisite for downstream
plans and the F1–F7 contracts consumed there.

- [ ] Add a root consumer test that loads only Tenancy + its declared dependencies from an archive, caches configuration and invokes Doctor. Add two host fixture records sharing a business key; an existing Filterable OR/relation query must retain the caller's tenant restriction and a Data mutation payload must not populate ownership fields.
- [ ] Run `TenantConsumerContractTest.php` and root `TenancyConsumerWorkflowTest.php` red, then complete actual archive metadata, public-contract classification, type-source registration and configuration inspector support. Do not add production Tenancy references to Support, Data, Primitives or Filterable to satisfy tests.
- [ ] Run foundation package tests, affected root contract tests, Composer validate/autoload, dependency audit, family/contracts/type checks and canonical skill mirror sync. Verify README install example requires no NVL Auth. Commit `test(tenancy): prove foundation distribution and disabled compatibility`.

## Completion gate

Foundation is ready only when F1–F8 are green, adopted databases cannot be exposed
by disabling a flag, and the real queue probe passes before deserialization.
It is not a release of tenant support for every package. Continue in the program's
dependency order; Settings bootstrap and Activity partitioning are early gates.
