# Auth Tenancy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. This document authorizes no implementation during the planning review.

**Goal:** Add Auth-owned tenant memberships, tenant RBAC, invitations, tokens, and audit isolation while retaining global accounts and disabled-mode compatibility.

**Architecture:** Auth depends on the inert `nvl/tenancy` foundation. Membership is an Auth-owned relationship entity; roles and operational records carry direct tenant ownership. Global credentials and browser sessions remain global, with tenant admission and one-use intent handled separately.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4, installed Spatie Permission 8, Sanctum 4, optional existing Socialite adapter; SQLite/PostgreSQL/MySQL/MariaDB proof matrix.

**Spec:** [Tenancy execution contracts](../specs/2026-09-16-tenancy-execution-contracts.md), which takes precedence over the earlier [configurable tenancy proposal](../specs/2026-09-16-configurable-tenancy-design.md).

## Global constraints

- PHP 8.4 and Laravel 13 are the execution baseline.
- No new external Composer dependency is required.
- All tenancy types and runtime logic live in `Nvl\Tenancy`, including the disabled implementation. Support remains free of tenant domain logic.
- Each package owns its new schema, query predicates, grants, adoption adapter, audit facts, and lifecycle. Tenancy never writes another package's tables.
- Released migrations are immutable. Optional migration sets use distinct paths and explicit registration, never an `up()` that silently skips then records itself as applied.
- Membership uses `SubjectReference` type/identifier, including a host's string/integer identifiers. Existing UUID-only Spatie storage has a separate compatibility check.
- Membership `is_owner` is independent of role names. Tenant administration cannot deactivate/delete global accounts or revoke another tenant's credentials.
- No null-team role participates in enabled tenant RBAC. Platform authorization uses explicit capabilities, never a tenant `admin` role or a permissive Gate result alone.
- Central identity login, recovery, and authenticated self-service have narrow Auth-owned admission. They do not enter a general platform context or expose tenant data.
- `AuthClient` and `AuthClientSession` stay global correlations. Tenant intent is separate. A browser session is not permanently bound to one tenant.
- All paths below are relative to the repository root. Run package tests from that root, sequentially with other stateful suites. Do not change production databases to run tests.
- Keep unimplemented Auth integrations unavailable to tenant requests. Auth's tenant-ready marker is activated only after the complete plan and its release gates pass.

## Execution dependencies and test commands

Execute after the foundation package implements every interface in the execution-contract document, including context participation, resource registration, and adoption. The foundation's own schema must be available for the integration test fixture. Do not invent alternate context setters or a second installation marker.

The portable package command is:

```bash
vendor/bin/pest --test-directory=packages/nvl/auth/tests --configuration=packages/nvl/auth/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/auth/tests
```

For a task, replace the final directory with the exact test path shown by that task. A red test must fail because its expected behavior is missing, rather than because autoloading, migrations, or the database are broken. Each commit boundary includes its focused passing tests and `vendor/bin/pint --dirty --format agent`; commit only files belonging to that boundary. No commits are made by the current planning task.

## File and API map

New Auth public capabilities are deliberately small:

| Surface | Planned path and responsibility |
|---|---|
| Membership adapter | `packages/nvl/auth/src/Services/AuthTenantMembershipAccess.php`: implements `Nvl\Tenancy\Contracts\TenantMembershipAccess` |
| Membership persistence | `packages/nvl/auth/src/Models/TenantMembership.php`, `TenantMembershipLock.php`; lifecycle resides in Actions, never in these models |
| Member APIs | `packages/nvl/auth/src/Actions/Memberships/`: list/show/enroll/status/revoke/ownership transfer use cases |
| Context participant | `packages/nvl/auth/src/Services/AuthTenantContextParticipant.php`: Spatie context and retained-relation cleanup |
| Tenant admission | `packages/nvl/auth/src/Services/AuthTenantAdmission.php`: membership/token proof; HTTP middleware calls it before binding |
| Invitation proof | `packages/nvl/auth/src/Contracts/InvitationRecipientProof.php`, `Services/VerifiedInvitationRecipientProof.php` |
| Tenant intent | `packages/nvl/auth/src/Models/TenantAuthenticationIntent.php`, `Services/TenantAuthenticationIntents.php` |
| Adoption | `packages/nvl/auth/src/Tenancy/AuthTenancyAdoption.php`: package-owned prepare/backfill/verify/activate |

Existing signatures remain stable unless a task explicitly extends them with an optional argument or an additive result field. Tenant IDs come from context, a verified bootstrap record, or an operator-reviewed adoption mapping; they are not added to client mutation DTOs.

## Task 1: Inert dependency, configuration, feature admission, and disabled proof

**Files**

- Modify: `packages/nvl/auth/composer.json`, `packages/nvl/auth/config/nvl-auth.php`.
- Modify: `packages/nvl/auth/src/Providers/AuthServiceProvider.php`, `packages/nvl/auth/src/Enums/AuthFeature.php`, `packages/nvl/auth/src/Services/FeatureManifest.php`, `packages/nvl/auth/src/Services/AuthManagementAbilityCatalog.php`.
- Modify: `packages/nvl/auth/tests/Provider/DisabledProviderSafetyTest.php`, `packages/nvl/auth/tests/Feature/AuthConfigurationTest.php`, `packages/nvl/auth/tests/Unit/FeatureManifestTest.php`.
- Create: `packages/nvl/auth/tests/Provider/DisabledTenancyCompatibilityTest.php`.

**Interfaces and configuration**

Auth adds `AuthFeature::Memberships = 'memberships'`, independently enabled from principal management and RBAC. Its feature defaults to false, and its routes default to false. Enabled membership mutations require the Audit feature; feature admission reports that dependency instead of silently omitting audit facts. Add these literal configuration entries:

```php
'features' => [
    'memberships' => [
        'enabled' => false,
        'routes' => ['account' => ['enabled' => false], 'management' => ['enabled' => false]],
        'services' => ['principal_resolver' => null],
        'settings' => ['per_page' => 25, 'maximum_per_page' => 100],
    ],
],
'tenancy' => [
    'migrations' => ['enabled' => false],
    'recipient_proof' => VerifiedInvitationRecipientProof::class,
    'activity_bridge' => 'disabled',
],
```

Merge these entries into the existing map; do not replace other features. `tenancy.enabled` remains the only runtime tenancy switch. Auth's schema setting selects an optional migration path and does not enable tenancy. `migrations.install_all` continues to install legacy Auth features and does not select this path. `activity_bridge` accepts only `disabled` or `tenant-aware`; the latter requires the tested bridge contract from Task 9.

Add management aliases `memberships.viewAny`, `memberships.view`, `memberships.enroll`, `memberships.update`, `memberships.revoke`, `memberships.transferOwnership`, and `memberships.manageAccess`, all owned by `AuthFeature::Memberships`. Preserve existing principal aliases in disabled mode.

The following is the registration inventory; implement registration in Task 2 after its model and adapter files exist, so this task's disabled provider gate does not reference missing classes. Register immutable `TenantResourceDefinition` values through `TenantResourceRegistry::register()`; use the configured model class for roles/tokens. Register `AuthTenancyAdoption::class` with `TenantAdoptionRegistry::register('auth', AuthTenancyAdoption::class)`.

| Resource key | Family | Model | Kind / extra option |
|---|---|---|---|
| `auth.memberships` | `auth.memberships` | `TenantMembership` | Root |
| `auth.membership_locks` | `auth.memberships` | `TenantMembershipLock` | Root; internal lock state |
| `auth.roles` | `auth.rbac` | configured `Role` | Root; tenant-only |
| `auth.invitations` | `auth.invitations` | `Invitation` | Root; `allowsPlatformRows: true` |
| `auth.tokens` | `auth.tokens` | configured `PersonalAccessToken` | Root; `allowsPlatformRows: true` |
| `auth.challenges` | `auth.challenges` | `Challenge` | Root; `allowsPlatformRows: true` |
| `auth.audits` | `auth.audits` | `AuthAudit` | Root; `allowsPlatformRows: true` |
| `auth.authentication_intents` | `auth.authentication_intents` | `TenantAuthenticationIntent` | Root |
| `auth.permissions` | `auth.permissions` | configured `Permission` | Platform vocabulary |

`allowsPlatformRows` supports explicit mixed operational ownership and never enables sharing/catalog access. Auth does not register a shareable platform catalog. Enabled `auth.rbac`/`auth.memberships` require tenant mode; a `resources` override cannot create platform/null-team roles or platform memberships. Permission vocabulary remains fixed Platform kind. Membership lock rows and Spatie link tables belong to the root's dependency closure; map their ownership through memberships/roles and never independently expose them. Global principal/credential/client operations have fixed code-owned classification through `AuthOperationBoundary`, including their central identity exception.

- [ ] Add a provider test using the existing disabled provider fixture:

```php
use Illuminate\Support\Facades\Schema;

it('keeps tenancy schema absent when Auth is installed without tenancy', function (): void {
    expect(config('tenancy.enabled'))->toBeFalse()
        ->and(config('nvl-auth.features.memberships.enabled'))->toBeFalse()
        ->and(Schema::hasTable('nvl_auth_tenant_memberships'))->toBeFalse()
        ->and(Schema::hasTable('nvl_tenancy_tenants'))->toBeFalse();
});
```

- [ ] Run the exact provider test with the package command. Initially it fails because the explicit inert dependency/defaults are missing.
- [ ] Add `nvl/tenancy` at the same internal package major as Support/Data; update the root's package catalog/lock through the foundation integration owner, without an external dependency. Add scoped binding defaults only when their implementation exists; Task 2 owns resource/adopter registration. No tenant table access occurs during provider registration. Use the foundation lazy adoption guard on first participating connection use.
- [ ] Run provider/config/manifest tests. Task 10 adds the activated-marker regression through the real coordinator: disabling Auth tenancy or omitting the feature cannot return a global role query. It must raise `TenantSchemaNotReady` or `TenantConfigurationInvalid` according to the foundation state mismatch contract.
- [ ] Commit boundary: inert Auth dependency and explicit feature admission; no enabled tenant data operations yet.

## Task 2: Optional schema and reusable integration fixtures

**Files**

- Create: `packages/nvl/auth/database/migrations/tenancy/2026_09_16_000000_prepare_auth_tenancy.php`.
- Create: `packages/nvl/auth/database/factories/TenantMembershipFactory.php`.
- Create: `packages/nvl/auth/src/Models/TenantMembership.php`, `packages/nvl/auth/src/Models/TenantMembershipLock.php`, `packages/nvl/auth/src/Models/TenantAuthenticationIntent.php`.
- Create: `packages/nvl/auth/src/Enums/MembershipStatus.php`, `packages/nvl/auth/src/Enums/TenantAuthenticationPurpose.php`.
- Modify: `packages/nvl/auth/src/Definitions/Tables/AuthTables.php`, `packages/nvl/auth/src/Services/AuthSchemaManager.php`, `packages/nvl/auth/src/Providers/AuthServiceProvider.php`, `packages/nvl/auth/tests/Pest.php`.
- Create: `packages/nvl/auth/tests/TenancyTestCase.php`, `packages/nvl/auth/tests/Fixtures/AuthTestTenantDirectory.php`, `packages/nvl/auth/tests/Fixtures/AuthTestPlatformAccess.php`, `packages/nvl/auth/tests/Fixtures/AuthTestMaintenanceMode.php`, `packages/nvl/auth/tests/Fixtures/AuthTenancyScenario.php`.
- Create: `packages/nvl/auth/tests/Feature/TenancyAdoption/SchemaTest.php`.
- Create the empty-install adapter path: `packages/nvl/auth/src/Tenancy/AuthTenancyAdoption.php`; Task 10 extends this same class for existing data.

**Schema contract**

| Table | Required shape |
|---|---|
| `nvl_auth_tenant_memberships` | UUID `id`; non-null UUID `tenant_id`; `subject_type` 160; `subject_id` 191; status `active|suspended|revoked`; `is_owner` false; unsigned revision starting at 1; timezone timestamps; unique `(tenant_id, subject_type, subject_id)`; lookup `(subject_type, subject_id, status, tenant_id)` |
| `nvl_auth_tenant_membership_locks` | UUID `tenant_id` primary key and timestamps; one stable row per tenant; retained after membership revocation |
| `nvl_auth_tenant_authentication_intents` | UUID `id`; non-null tenant UUID; purpose; hashed opaque nonce unique; hashed session binding; optional subject type/ID; bounded encrypted payload for provider/return path/challenge reference; expiry/consumed timestamps; expiry index |
| Existing roles | Prepare nullable `tenant_id`; final active schema makes it non-null; unique `(tenant_id, name, guard_name)` replaces legacy global name uniqueness; unique `(tenant_id,id)` supports same-tenant references |
| Existing `model_has_roles` / `model_has_permissions` | Prepare nullable tenant UUID; final primary keys include tenant before permission/role+principal tuple; direct permissions remain tenant-bound; same-tenant role/pivot and parent constraints where portable |
| Existing `role_has_permissions` | Ownership inherited through canonical tenant role; retain `(permission_id, role_id)` key because permissions are global vocabulary and role UUIDs are unique; do not add a required tenant pivot field that Spatie's role permission writer does not populate |
| Existing invitations, tokens, challenges, audits | Prepare nullable tenant UUID plus non-null `ownership_key`; explicit `platform` or `tenant:<uuid>` consistency; tenant-leading read/lifecycle indexes; token tenant binding is immutable |

`MembershipStatus` has `Active`, `Suspended`, `Revoked`. `TenantAuthenticationPurpose` has `Login`, `SocialLogin`, `SocialLink`, `MagicLink`, `SecurityCode`, `PasskeyLogin`, `Invitation`. Global credential tables, users, password reset tokens, social identities, clients, and browser correlation rows receive no ownership column. A global challenge can carry an intent ID in its server-generated payload; it is not reclassified as a tenant credential.

Only the reviewed adapter mapping may backfill prepared rows. Do not create fake tenants or null-team platform role rows. Directory foreign keys are conditional on the foundation directory schema contract; do not assume `nvl_tenancy_tenants` exists for a host adapter.

**Fixture interfaces used by every later test**

`TenancyTestCase` extends `Orchestra\Testbench\TestCase` directly and uses `Illuminate\Foundation\Testing\DatabaseMigrations`; do not extend the current Auth case, which inherits an outer `RefreshDatabase` transaction. Its provider list is the existing Auth list plus `TenancyServiceProvider` before `AuthServiceProvider`. Copy the existing Auth `defineEnvironment()` values for guard/broker/principal/API-token abilities, then enable `tenancy.enabled`, select the host directory and explicit test platform adapter, enable Auth memberships/invitations/RBAC/audit/API tokens, select the Auth tenancy migration path, and configure `AuthTenantMembershipAccess`. Set `tenancy.access.membership=AuthTenantMembershipAccess::class`, `tenancy.access.platform=AuthTestPlatformAccess::class`, and `tenancy.directory=['driver'=>'host','adapter'=>AuthTestTenantDirectory::class]`. Bind `AuthTestMaintenanceMode` to Laravel's `MaintenanceMode` contract. Load the foundation core opt-in migrations explicitly. Preserve the existing Auth test's `user(string $email = 'user@example.test'): TestUser` helper and its permissive management/system adapters so boundary tests prove that business policy cannot defeat isolation. In `tests/Pest.php`, apply the legacy case only to the existing immediate Feature/Unit test files and the new case to `Feature/Tenancy`; do not stack two test cases over the same directory.

Use the exact foundation core migration path `packages/nvl/tenancy/database/migrations/tenancy/2026_09_16_000001_create_tenancy_core_tables.php`. The new Auth tenancy migration path stays independently selected. Replace the existing broad Feature test-case assignment with this explicit registration:

```php
use Nvl\Auth\Tests\DisabledAuthProviderTestCase;
use Nvl\Auth\Tests\LegacyAuthTenancyTestCase;
use Nvl\Auth\Tests\TenancyTestCase;
use Nvl\Auth\Tests\TestCase;

$legacyFeatureFiles = glob(__DIR__.'/Feature/*Test.php') ?: [];
uses(TestCase::class)->in(...$legacyFeatureFiles);
uses(TestCase::class)->in('Unit');
uses(TenancyTestCase::class)->in('Feature/Tenancy');
uses(LegacyAuthTenancyTestCase::class)->in('Feature/TenancyAdoption');
uses(DisabledAuthProviderTestCase::class)->in('Provider');
```

`AuthTestTenantDirectory::find(TenantId): TenantDescriptor` implements the foundation directory. It recognizes exactly the two UUIDs below, initially active; `setStatus(TenantId, TenantStatus): void` mutates only the fake directory. Unknown IDs throw `TenantNotFound`. `AuthTestPlatformAccess::authorize(PlatformOperation): void` allows exactly purposes beginning `auth-test.` with actor type `system` and actor ID `fixture`; all other operations throw. These are test adapters, never package defaults.

`AuthTenancyScenario` exposes these setup methods and no alternative production mutation implementation:

```php
namespace Nvl\Auth\Tests\Fixtures;

use Closure;
use Nvl\Auth\Enums\MembershipStatus;
use Nvl\Auth\Models\TenantMembership;
use Nvl\Auth\Models\TenantMembershipLock;
use Nvl\Auth\ValueObjects\SubjectReference;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;

final class AuthTenancyScenario
{
    public function a(): TenantId { return new TenantId('018f0000-0000-7000-8000-000000000001'); }
    public function b(): TenantId { return new TenantId('018f0000-0000-7000-8000-000000000002'); }
    public function run(TenantId $tenant, Closure $operation): mixed
    {
        return app(TenantRunner::class)->run($tenant, $operation);
    }
    public function platform(Closure $operation): mixed
    {
        return app(TenantRunner::class)->platform(
            new PlatformOperation('auth-test.setup', 'system', 'fixture'), $operation,
        );
    }
    public function member(TenantId $tenant, SubjectReference $subject, bool $owner = false): TenantMembership
    {
        TenantMembershipLock::query()->firstOrCreate(['tenant_id' => $tenant->value]);
        return TenantMembership::factory()->create([
            'tenant_id' => $tenant->value,
            'subject_type' => $subject->type,
            'subject_id' => $subject->identifier,
            'status' => MembershipStatus::Active,
            'is_owner' => $owner,
            'revision' => 1,
        ]);
    }
}
```

Add strict types and the repository PHPDocs in actual files. Fixture row creation is explicit arrangement, never the behavior being asserted. `AuthTestMaintenanceMode` implements `activate(array $payload): void`, `deactivate(): void`, `active(): bool`, and `data(): array`; hold an in-memory active flag initially true and bounded payload. It changes no actual application maintenance file.

`TenancyTestCase` executes empty-database Auth adoption through the real Task 10 adapter before tenant Action tests. Put the following body in a protected `activateEmptyAuthTenancy(): void` method; imports belong at the top of the test-case file:

```php
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\ValueObjects\PlatformOperation;

$coordinator = app(TenantAdoptionCoordinator::class);
$operation = new PlatformOperation('auth-test.adoption', 'system', 'fixture');
$plan = $coordinator->prepare(['auth'], [], $operation);
while (! $coordinator->backfill($plan, 100, $operation)) {
    // Continue only a bounded, advancing empty-fixture adoption cursor.
}
expect($coordinator->verify($plan)->passed())->toBeTrue();
$coordinator->activate($plan, $operation);
```

Schema/adoption tests use `LegacyAuthTenancyTestCase`, a second case in `packages/nvl/auth/tests/LegacyAuthTenancyTestCase.php`, extending `TenancyTestCase` and overriding the protected `activateEmptyAuthTenancy(): void` with an empty body. Move those tests to `packages/nvl/auth/tests/Feature/TenancyAdoption/` and configure its Pest base once; all later references to `SchemaTest.php`, `AdoptionTest.php`, and `DoctorTest.php` refer to that directory. Schema tests explicitly select/call stages; no test inserts active markers by hand. Implement the empty-install adapter path in Task 2, then extend its existing-data mapping behavior in Task 10. This avoids making every lifecycle test depend on unfinished adoption work and preserves real coordinator semantics.

- [ ] Write migration tests for no schema when unselected, selection after legacy migrations have already run, non-null final membership keys, duplicate tenant/principal rejection, and two tenants accepting the same role name. Representative final-schema test:

```php
use Illuminate\Database\QueryException;
use Nvl\Auth\Tests\Fixtures\AuthTenancyScenario;
use Nvl\Auth\ValueObjects\SubjectReference;

it('enforces one membership per principal and tenant', function (): void {
    $s = new AuthTenancyScenario;
    $subject = SubjectReference::fromAuthenticatable($this->user());
    $s->member($s->a(), $subject);
    $s->member($s->b(), $subject);
    expect(fn () => $s->member($s->a(), $subject))->toThrow(QueryException::class);
});
```

- [ ] Run `SchemaTest.php` with the focused package command; verify the expected absent-schema/constraint failure.
- [ ] Implement the optional migration and model/factory metadata, then register the Task 1 resource inventory and real empty-install adoption adapter. Add schema inventory without replaying conditional legacy migrations to discover tenant upgrades. Preparation records a `prepared` marker; ordinary actions deny access until finalization. Historical migration files remain byte-for-byte unchanged.
- [ ] Run schema tests on SQLite and the existing PostgreSQL/MySQL/MariaDB jobs. Verify vendor-owned and copied migration ownership separately. Final tenant constraint installation is the explicit Task 10 activation stage, not a second silently skipped migration.
- [ ] Commit boundary: prepared Auth schema and fixtures with no activation of an existing installation.

## Task 3: Membership lifecycle and owner invariants

**Files**

- Create: `packages/nvl/auth/src/Contracts/MembershipPrincipalResolver.php`, `packages/nvl/auth/src/Services/EloquentMembershipPrincipalResolver.php`.
- Create: `packages/nvl/auth/src/Services/AuthTenantMembershipAccess.php`, `packages/nvl/auth/src/Services/MembershipLocator.php`, `packages/nvl/auth/src/Services/MembershipWriter.php`, `packages/nvl/auth/src/Services/MembershipOwnerGuard.php`.
- Create: `packages/nvl/auth/src/Data/Mutations/EnrollMembershipData.php`, `packages/nvl/auth/src/Data/Mutations/UpdateMembershipStatusData.php`, `packages/nvl/auth/src/Data/Mutations/TransferMembershipOwnershipData.php`, `packages/nvl/auth/src/Data/Display/TenantMembershipData.php`.
- Create: `packages/nvl/auth/src/Actions/Memberships/EnrollMembershipAction.php`, `SetMembershipStatusAction.php`, `RevokeMembershipAction.php`, `TransferMembershipOwnershipAction.php`, `ListMembershipsAction.php`, `ShowMembershipAction.php` in that same directory.
- Create: `packages/nvl/auth/src/Actions/Memberships/ListOwnMembershipsAction.php`, `packages/nvl/auth/src/Actions/Memberships/ProvisionTenantOwnerAction.php`.
- Create: `packages/nvl/auth/tests/Feature/Tenancy/MembershipLifecycleTest.php`, `packages/nvl/auth/tests/Feature/Tenancy/MembershipOwnerConcurrencyTest.php`.

**Exact new interfaces**

```text
// MembershipPrincipalResolver: configured host adapter; no dependency on principal_management.
public function resolve(SubjectReference $reference, bool $lock = false): Authenticatable;
public function assertEligible(Authenticatable $principal): void;
public function connectionName(): string;

// AuthTenantMembershipAccess implements the foundation contract.
public function assertMember(Authenticatable $actor, TenantId $tenant): void;

// Mutation DTO constructors (each in its named file).
EnrollMembershipData::__construct(SubjectReference $subject, array $roles = [], array $permissions = []);
UpdateMembershipStatusData::__construct(MembershipStatus $status, int $expectedRevision);
TransferMembershipOwnershipData::__construct(string $recipientMembershipId, int $expectedRevision);

// Public Actions, each execute in its named file.
EnrollMembershipAction::execute(Authenticatable|SystemMutationContext $authority, EnrollMembershipData $data): TenantMembership;
SetMembershipStatusAction::execute(Authenticatable|SystemMutationContext $authority, TenantMembership|string $membership, UpdateMembershipStatusData $data): TenantMembership;
RevokeMembershipAction::execute(Authenticatable|SystemMutationContext $authority, TenantMembership|string $membership, int $expectedRevision): TenantMembership;
TransferMembershipOwnershipAction::execute(Authenticatable|SystemMutationContext $authority, TenantMembership|string $membership, TransferMembershipOwnershipData $data): TenantMembership;
ListMembershipsAction::execute(Authenticatable $actor, ?string $search = null, int $perPage = 25): LengthAwarePaginator;
ShowMembershipAction::execute(Authenticatable $actor, TenantMembership|string $membership): TenantMembershipData;
ListOwnMembershipsAction::execute(Authenticatable $subject): Collection;
ProvisionTenantOwnerAction::execute(SystemMutationContext $authority, SubjectReference $subject): TenantMembership;
```

Use imports for `Authenticatable`, `LengthAwarePaginator`, Support `Collection`, `SubjectReference`, `TenantId`, membership models/DTOs, and `SystemMutationContext` in implementation files. `ListOwnMembershipsAction` is an authenticated global-identity operation returning only that subject's minimal active membership projection (tenant UUID, membership UUID/status/revision); it never discloses other members. Tenant management projections expose only allowlisted display name/email plus membership fields, never global profile, login IP, lock status, MFA state, or recovery metadata.

`MembershipLocator::find(TenantMembership|string $membership, bool $lock = false): TenantMembership` always reloads through `TenantBoundary::query(..., 'auth.memberships')`. `MembershipWriter::enroll(SubjectReference $subject): TenantMembership` and `MembershipWriter::setStatus(TenantMembership $membership, MembershipStatus $status): TenantMembership` require the caller's transaction. This internal service is reused by invitation acceptance without calling one public Action from another. It locks/reactivates an existing revoked tuple or inserts one active membership; increments revision on a real change.

`MembershipOwnerGuard::lock(TenantId $tenant): void` uses the stable Auth lock row. `assertCanRemoveOwner(TenantMembership $membership): void` re-reads active owners while that lock is held. `assertPrincipalCanBeDisabled(SubjectReference $subject): void` visits affected tenant lock rows in sorted UUID order, rechecks the principal's memberships, and rejects unresolved last-owner responsibility.

- [ ] Add the behavior test:

```php
use Nvl\Auth\Actions\Memberships\RevokeMembershipAction;
use Nvl\Auth\Exceptions\AuthException;
use Nvl\Auth\Tests\Fixtures\AuthTenancyScenario;
use Nvl\Auth\ValueObjects\SubjectReference;
use Nvl\Tenancy\Contracts\TenantMembershipAccess;

it('removes one membership without removing the other tenant or global account', function (): void {
    $s = new AuthTenancyScenario;
    $actor = $this->user('actor@example.test');
    $member = $this->user('member@example.test');
    $s->member($s->a(), SubjectReference::fromAuthenticatable($actor), true);
    $a = $s->member($s->a(), SubjectReference::fromAuthenticatable($member));
    $s->member($s->b(), SubjectReference::fromAuthenticatable($member), true);
    $s->run($s->a(), fn () => app(RevokeMembershipAction::class)->execute($actor, $a, 1));
    expect(fn () => app(TenantMembershipAccess::class)->assertMember($member, $s->a()))
        ->toThrow(AuthException::class);
    app(TenantMembershipAccess::class)->assertMember($member, $s->b());
    expect($member->fresh())->not->toBeNull();
});
```

- [ ] Run `MembershipLifecycleTest.php`; expect missing behavior, then implement the signatures above.
- [ ] In each mutation: resolve authority, begin the normalized Auth connection transaction, lock the tenant membership-lock row, reload principal and membership, recheck directory/principal/member state, compare revision, validate canonical tenant roles, mutate membership/assignments atomically, increment revision, record tenant audit, and dispatch captured-context events after commit. No membership cache authorizes a request. Role revocation and tenant token revocation affect only that tenant. First-owner provisioning is an explicitly authorized system operation; ordinary enroll cannot set `is_owner` from a DTO. Ownership transfer activates an eligible existing recipient membership and flips ownership in one transaction.
- [ ] Add denial datasets for foreign ID/model, same-tenant stale model, revoked principal, stale revision, suspended tenant, host principal with principal management off, and removal of the sole owner. Revoked membership reactivation does not restore old permissions implicitly. Suspension keeps assignments for explicit resume but denies every admission until active.
- [ ] Add the real two-process owner-removal test using the barrier/independent connection approach in `InvitationDeliveryOutcomeConcurrencyTest.php`. Introduce one test fixture helper `AuthMembershipRace::run(string $connectionName, array $workers): array` in `tests/Fixtures/AuthMembershipRace.php`, with the same bounded ready/go barrier and cleanup, and worker result shape `array{ok: bool, error?: class-string, code?: string}`. Commit fixture rows before forking. Two simultaneous removals of the two owners must produce one success, one `membership_last_owner` error, and exactly one active owner. SQLite explicitly skips only this infrastructure proof; the PostgreSQL/MySQL/MariaDB jobs must execute it.
- [ ] Run both exact tests and commit membership lifecycle plus concurrency proof.

## Task 4: Spatie lifecycle, canonical RBAC queries, and global vocabulary separation

**Files**

- Create: `packages/nvl/auth/src/Services/AuthTenantContextParticipant.php`, `packages/nvl/auth/src/Services/RbacPrincipalTracker.php`, `packages/nvl/auth/src/Services/AuthTenantRbacQueries.php`.
- Modify: `packages/nvl/auth/src/Providers/AuthServiceProvider.php`, `packages/nvl/auth/src/Models/Role.php`, `packages/nvl/auth/src/Models/Permission.php`.
- Modify: `packages/nvl/auth/src/Services/RbacEntityLocator.php`, `RbacAssignmentService.php`, `RbacManager.php`, `EloquentRbacPrincipalAccess.php`, `RbacOptionReadService.php`, `RbacSynchronizer.php`, `RoleHierarchy.php` in the same Services directory.
- Modify: `packages/nvl/auth/src/Actions/Users/SyncUserRolesAction.php`, `packages/nvl/auth/src/Actions/Users/SyncUserPermissionsAction.php`.
- Modify every RBAC entry point listed in the inventory below, plus `packages/nvl/auth/src/Data/Mutations/StoreRoleData.php` and `packages/nvl/auth/src/Data/Mutations/UpdateRoleData.php`.
- Create: `packages/nvl/auth/tests/Feature/Tenancy/RbacIsolationTest.php`, `RbacLifecycleTest.php`, `RbacCatalogBoundaryTest.php` in the same Tenancy test directory.

**Mandatory entry-point inventory:** Under `packages/nvl/auth/src/Actions/Rbac/`, review and adapt `AddRolePermissionsAction.php`, `ApplyRoleTemplateAction.php`, `BootstrapRbacAction.php`, `CheckRoleNameAvailabilityAction.php`, `CloneRoleAction.php`, `CreatePermissionAction.php`, `CreatePermissionWithRolesAction.php`, `CreateRoleAction.php`, `DeletePermissionAction.php`, `DeleteRoleAction.php`, `ListPermissionCatalogAction.php`, `ListPermissionGroupsAction.php`, `ListPermissionOptionsAction.php`, `ListPermissionsAction.php`, `ListRoleCatalogAction.php`, `ListRoleHierarchyAction.php`, `ListRoleOptionsAction.php`, `ListRoleTemplatesAction.php`, `ListRolesAction.php`, `ResolvePermissionIdentifiersAction.php`, `ResolveRoleIdentifiersAction.php`, `ShowPermissionAction.php`, `ShowRbacAnalyticsAction.php`, `ShowRoleAction.php`, `ShowRoleAnalyticsAction.php`, `SuggestPermissionsAction.php`, `SuggestRolesAction.php`, `SyncRolePermissionsAction.php`, `SynchronizePermissionCatalogAction.php`, `SynchronizeRbacAction.php`, `SynchronizeRoleTemplatesAction.php`, `UpdatePermissionAction.php`, and `UpdateRoleAction.php`.

**Interfaces and implementation contract**

`AuthTenantContextParticipant` implements `Nvl\Tenancy\Contracts\TenantContextParticipant::enter(TenantContextSnapshot $next): Closure`. Register its class with `Nvl\Tenancy\Services\TenantContextParticipants::register(AuthTenantContextParticipant::class)` and resolve it scoped. `RbacPrincipalTracker::track(Model $principal): void` stores weak references to registered principal instances; `clearRelations(): void` unsets `roles`, `permissions`, and wildcard permission state where the installed Spatie API exposes it. Track guard principals, every canonical principal resolver result, and Eloquent `retrieved`/`created` events for the configured RBAC model. Passed models are still reloaded in public mutations; tracking is not an authorization substitute.

`AuthTenantRbacQueries::roles(): Builder` calls `TenantBoundary::query` with `auth.roles`. `role(string $id, bool $lock = false): Role` reloads by configured guard and active tenant. `assertAssignmentPrincipal(Authenticatable $principal): Authenticatable` reloads the principal, validates eligibility and active membership, and clears prior relations. `permissions(): Builder` reads the fixed global vocabulary without tenant assignment counts. Inverse `Role::users()` and `Permission::users()` counts explicitly constrain assignment tenant and active memberships.

Keep Spatie's cached vocabulary/role map global, with an explicit cache-reader path that loads all tenant role definitions without depending on current tenant. Do not put a context-dependent global scope on `Role` or `Permission` that would poison the registrar's cache. General consumer reads use canonical queries and permission projections; registrar objects are internal. Every authority check constrains active tenant assignments and current membership. All roles have a non-null tenant UUID after adoption, eliminating Spatie's null-team fallback. Doctor rejects host role models that bypass these semantics.

The global cache is not a public permission serializer. In particular, permission detail/list/options output must constrain any nested roles and assignment counts to the current tenant; the registrar's `Permission::roles()` relation remains an internal cache-build concern. A host must not treat `$user->can()` alone as membership evidence; HTTP/Action admission is mandatory even when a stale or permissive Gate result says true.

- [ ] Write a test demonstrating identical role names with distinct effective permissions:

```php
use Nvl\Auth\Actions\Rbac\CreateRoleAction;
use Nvl\Auth\Actions\Users\SyncUserRolesAction;
use Nvl\Auth\Data\Mutations\StoreRoleData;
use Nvl\Auth\Data\Mutations\SyncUserRolesData;
use Nvl\Auth\Models\Permission;
use Nvl\Auth\Tests\Fixtures\AuthTenancyScenario;
use Nvl\Auth\ValueObjects\SubjectReference;

it('restores tenant RBAC on the same retained principal', function (): void {
    $s = new AuthTenancyScenario;
    $user = $this->user();
    $ref = SubjectReference::fromAuthenticatable($user);
    $s->member($s->a(), $ref, true);
    $s->member($s->b(), $ref, true);
    $s->platform(fn () => Permission::query()->create(['name' => 'documents.write', 'guard_name' => 'web']));
    foreach ([$s->a(), $s->b()] as $tenant) {
        $s->run($tenant, function () use ($tenant, $s, $user): void {
            app(CreateRoleAction::class)->execute($user, new StoreRoleData(
                name: 'manager', permissions: $tenant->value === $s->a()->value ? ['documents.write'] : [],
            ));
            app(SyncUserRolesAction::class)->execute($user, $user, new SyncUserRolesData(['manager']));
        });
    }
    $s->run($s->a(), function () use ($s, $user): void {
        expect($user->can('documents.write'))->toBeTrue();
        $s->run($s->b(), fn () => expect($user->can('documents.write'))->toBeFalse());
        expect($user->can('documents.write'))->toBeTrue();
    });
});
```

- [ ] Run `RbacIsolationTest.php` and `RbacLifecycleTest.php`; expect global uniqueness/leaked relation failures before implementation.
- [ ] Replace `permission.teams=false` only for active/adopted tenant RBAC, before registrar resolution in register/boot; set `permission.column_names.team_foreign_key=tenant_id`. Verify actual column/constraint state before first RBAC use. Prepared/mismatched schemas deny business operations but still allow read-only Doctor and the adoption CLI. An already-resolved incompatible registrar is an operational configuration error, not silently reconfigured while requests run. The sole in-process exception is the explicit maintenance adoption command: after constraint verification it applies deployment-level teams configuration and calls the installed registrar's `initializeCache()` to discard the pre-adoption map. This is one cutover, never per-tenant Config mutation; all serving workers still restart.
- [ ] Participant entry saves the previous Spatie team, clears tracked relations, sets the next tenant UUID (or null for explicit platform/unresolved), and returns a restoration callback that clears relations and restores the prior team. Failed entry must restore its own partial state. Platform mode cannot perform tenant role assignment even with a null team; the canonical boundary denies it before Spatie is called. Convert Request/context-dependent provider bindings from singleton to scoped while retaining immutable configuration/catalog registries as singletons.
- [ ] Route all inventoried reads and writes through canonical queries. Scope role uniqueness and parent validation to tenant/guard, reject foreign parents and forged models, and acquire the same stable Auth tenant lock row before hierarchy/assignment changes so simultaneous parent edits cannot create a cycle. Validate role IDs before calling Spatie even when supplied as model objects, and require active target membership for role/direct-permission writes. Clear and repopulate the registrar cache after durable vocabulary/role changes, not before a transaction can roll back.
- [ ] Separate global vocabulary mutation from tenant role seeding. `SynchronizePermissionCatalogAction` and permission create/update/delete are explicit platform operations. Tenant `ApplyRoleTemplateAction` resolves existing allowed permissions and never `findOrCreate`s vocabulary. In enabled mode, combined `BootstrapRbacAction`, `SynchronizeRbacAction`, and `CreatePermissionWithRolesAction` reject with `rbac_mixed_context_operation`; consumers invoke the existing separate catalog and role-template Actions in their correct contexts. Preserve combined behavior only while tenancy is disabled. Never switch runner context inside their existing transaction.
- [ ] Add cache-first-in-A/read-in-B and reverse-order tests, exception restoration, null-role rejection, direct permission isolation, foreign UUID/model denial, same-name DTO validation, read/analytics counts, disabled legacy fixture, and role-template missing-vocabulary rejection. Run the three named tests and existing `RbacManagementTest.php`, `SpatiePermissionIntegrationTest.php`, `RbacPrincipalAccessTest.php`.
- [ ] Commit boundary: schema-gated tenant RBAC and explicitly separated vocabulary APIs.

## Task 5: Global account authority and tenant membership HTTP surface

**Files**

- Create: `packages/nvl/auth/src/Services/AuthOperationBoundary.php`, `packages/nvl/auth/src/Enums/AuthIdentityOperation.php`.
- Create: `packages/nvl/auth/src/Http/Controllers/Management/MembershipController.php`, `packages/nvl/auth/src/Http/Controllers/Account/MembershipController.php`, `packages/nvl/auth/routes/management/memberships.php`, `packages/nvl/auth/routes/account/memberships.php`.
- Modify: `packages/nvl/auth/src/Services/UserLocator.php`, `packages/nvl/auth/src/Services/ManagementAuthorizer.php`, `packages/nvl/auth/src/Services/MutationAuthorizer.php`.
- Modify under `packages/nvl/auth/src/Actions/Users/`: `ListUsersAction.php`, `SuggestUsersAction.php`, `ShowUserAction.php`, `CreateUserAction.php`, `UpdateUserAction.php`, `DeleteUserAction.php`, `SetUserActiveAction.php`, `RestoreUserAction.php`, `BulkUpdateUsersAction.php`, `DeleteOwnAccountAction.php`, `ShowProfileAction.php`, `UpdateProfileAction.php`.
- Create: `packages/nvl/auth/tests/Feature/Tenancy/PrincipalBoundaryTest.php`, `packages/nvl/auth/tests/Feature/Tenancy/MembershipHttpTest.php`.

**New interface**

```text
// AuthOperationBoundary: identity-only admission; does not change TenantContext.
public function central(AuthIdentityOperation $operation, ?Authenticatable $subject = null): void;
public function requirePlatformAdministration(): void;
```

`AuthIdentityOperation` is a closed enum with `Login`, `Logout`, `Recovery`, `VerifyEmail`, `Profile`, `Password`, `Mfa`, `SocialIdentity`, `MembershipDiscovery`. `central()` validates the corresponding existing feature/purpose and, for self-service, the authenticated canonical subject; it grants no directory-wide or tenant resource reads. Public login/recovery retain existing anti-enumeration behavior. `requirePlatformAdministration()` denies tenant/unresolved contexts in enabled mode even when host policies return true. It preserves existing behavior when disabled.

- [ ] Write the negative test with permissive host business policy:

```php
use Nvl\Auth\Actions\Users\DeleteUserAction;
use Nvl\Auth\Tests\Fixtures\AuthTenancyScenario;
use Nvl\Auth\ValueObjects\SubjectReference;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

it('does not let a tenant owner delete a global principal', function (): void {
    $s = new AuthTenancyScenario;
    $owner = $this->user('owner@example.test');
    $target = $this->user('target@example.test');
    $s->member($s->a(), SubjectReference::fromAuthenticatable($owner), true);
    expect(fn () => $s->run($s->a(), fn () => app(DeleteUserAction::class)->execute($owner, $target)))
        ->toThrow(TenantBoundaryViolation::class);
    expect($target->fresh())->not->toBeNull();
});
```

- [ ] Run `PrincipalBoundaryTest.php`; then put mandatory ownership/operation admission before optional host policy admission. Keep global user CRUD platform-only; member CRUD uses the new membership APIs. Canonical account mutation locators reload passed objects and enforce normalized connection identity.
- [ ] Call `MembershipOwnerGuard::assertPrincipalCanBeDisabled` for global delete/deactivate/self-delete under one stable lock order. Reject unresolved sole ownership with `membership_last_owner`; explicit emergency account containment requires a platform procedure that first suspends affected tenants through Tenancy-owned APIs. Do not disguise that emergency procedure as tenant membership deletion.
- [ ] Add routes beneath the existing configured Auth prefix: management `GET memberships`, `GET memberships/{membership}`, `POST memberships`, `PATCH memberships/{membership}/status`, `DELETE memberships/{membership}`, `POST memberships/{membership}/transfer-ownership`; account `GET memberships` lists only the authenticated principal's memberships. Route names are `nvl.auth.management.memberships.*` / `nvl.auth.account.memberships.index`, matching `packages/nvl/auth/src/Providers/RouteServiceProvider.php`. Tenant routes use the foundation's authenticated tenant admission before model binding. Account discovery is a central identity route. Update that route provider and the feature manifest's family inventory in this task.
- [ ] Keep transport tests narrow: verb/name/admission, foreign ID maps to the same 404 as unknown, validation rejects tenant/owner mass assignment, stable status envelopes, and member projections omit global security fields. Membership access synchronization reuses the tenant-safe role Actions, with `memberships.manageAccess` authority in enabled mode.
- [ ] Run both tests and the existing principal HTTP/management tests; commit the account/membership authority split.

## Task 6: Invitation recipient proof and atomic membership acceptance

**Files**

- Create: `packages/nvl/auth/src/Contracts/InvitationRecipientProof.php`, `packages/nvl/auth/src/Services/VerifiedInvitationRecipientProof.php`, `packages/nvl/auth/src/Services/InvitationTenantBootstrap.php`.
- Modify every file under `packages/nvl/auth/src/Actions/Invitations/`: `AcceptInvitationAction.php`, `CreateInvitationAction.php`, `FindActiveInvitationAction.php`, `ListInvitationProjectionsAction.php`, `ListInvitationsAction.php`, `PreviewInvitationAction.php`, `RecordInvitationDeliveryOutcomeAction.php`, `RegisterInvitationAction.php`, `ResendInvitationAction.php`, `RevokeInvitationAction.php`.
- Modify: `packages/nvl/auth/src/Services/PackageInvitationSubjectResolver.php`, `packages/nvl/auth/src/Http/Controllers/Public/InvitationController.php`, `packages/nvl/auth/routes/public/invitations.php`, `packages/nvl/auth/src/ValueObjects/InvitationIssuanceContext.php`, `packages/nvl/auth/src/ValueObjects/AuthDeliveryRequest.php`.
- Create: `packages/nvl/auth/tests/Feature/Tenancy/InvitationAcceptanceTest.php`, `packages/nvl/auth/tests/Feature/Tenancy/InvitationBootstrapTest.php`.

**New interfaces**

```text
// Contract is configurable for host account models.
InvitationRecipientProof::assertMatches(Invitation $invitation, Authenticatable $subject): void;

// Bounded internal token lookup, not an arbitrary unscoped query helper.
InvitationTenantBootstrap::tenantForToken(string $token): ?TenantId;

// Add optional subject argument without breaking existing disabled callers.
RegisterInvitationAction::execute(AcceptInvitationData $data, ?Authenticatable $authenticatedRecipient = null): InvitationRegistrationResult;
```

The default recipient proof canonicalizes the principal via the configured resolver, requires a verified email identity equal to the encrypted invitation recipient, and denies a mismatched/ineligible principal. Host principals lacking a verified-address API require an explicit proof adapter. Direct `AcceptInvitationAction` callers already supply an authenticated principal; preserve that trusted-input contract and run the same proof as HTTP. A raw request principal ID never becomes proof.

- [ ] Write the wrong-recipient test using actual existing issuance APIs:

```php
use Nvl\Auth\Actions\Invitations\AcceptInvitationAction;
use Nvl\Auth\Actions\Invitations\CreateInvitationAction;
use Nvl\Auth\Data\Mutations\StoreInvitationData;
use Nvl\Auth\Exceptions\AuthException;
use Nvl\Auth\Tests\Fixtures\AuthTenancyScenario;
use Nvl\Auth\ValueObjects\SubjectReference;

it('does not attach an invitation to a different authenticated identity', function (): void {
    $s = new AuthTenancyScenario;
    $owner = $this->user('owner@example.test');
    $wrong = $this->user('wrong@example.test');
    $s->member($s->a(), SubjectReference::fromAuthenticatable($owner), true);
    $issued = $s->run($s->a(), fn () => app(CreateInvitationAction::class)->execute(
        new StoreInvitationData(recipient: 'invited@example.test'), $owner,
    ));
    expect(fn () => app(AcceptInvitationAction::class)->execute($issued->token, $wrong))
        ->toThrow(AuthException::class);
    expect($issued->invitation->fresh()->accepted_at)->toBeNull();
});
```

- [ ] Run `InvitationAcceptanceTest.php`; verify wrong-recipient rejection is the missing behavior.
- [ ] Scope issuance/active lookup/resend/list/revoke/delivery outcomes to stored tenant. Active keys hash `(ownership_key, normalized recipient hash, purpose)`, so the same recipient can have one active invitation per tenant/purpose. Resolve and store canonical tenant role IDs on issuance; revalidate on acceptance so deleted/changed roles cannot be reinterpreted by name in another tenant. Actorless issuance booleans cannot bypass explicit system/platform authority in enabled mode.
- [ ] Bootstrap reads only hash-matched invitation identity and stored ownership. Check expiry/revocation and active tenant before entering `TenantRunner::run`; inside the runner start the transaction, lock the membership lock row, then reload invitation under the tenant boundary with a lock. Revalidate token/recipient/current inviter membership and invitation-create authority; then use `MembershipWriter`, assign roles/direct permissions, consume invitation, record audit, and dispatch captured delivery/membership events after commit. Resolve principal before selecting its lock; use stable row ordering across invitation and member mutations. Normalize all participating connection names and reject any cross-connection transaction before mutation.
- [ ] If registration email already exists, require the optional authenticated recipient and proof; otherwise return the neutral `invitation_identity_proof_required` failure without logging in or changing the existing account. For a new identity, preserve existing registration rules and treat the valid invitation token as proof of the invited mailbox only for that new registration. Race-safe global email uniqueness must roll back membership and invitation consumption on conflict.
- [ ] Platform invitations remain explicit central account-provisioning workflows and cannot carry tenant role payloads; tenant invitation acceptance never switches context inside an already-open transaction. Preview exposes only minimum safe data and no role/membership authority. A requested tenant conflicting with stored invitation ownership fails neutrally.
- [ ] Add tests for same-recipient A/B issuance, stale inviter, revoked/suspended tenant, role deleted/replaced before accept, atomic rollback, existing account proof, duplicate consumption, resend rotation, old delivery callback, and bootstrap replay against B. Run both named tests and existing `InvitationLifecycleTest.php` / `InvitationDeliveryOutcomeConcurrencyTest.php`.
- [ ] Commit invitation binding, proof, and atomic membership acceptance.

## Task 7: Complete Sanctum token lifecycle and request admission

**Files**

- Create: `packages/nvl/auth/src/Services/AuthTenantAdmission.php`, `packages/nvl/auth/src/Http/Middleware/EnsureAuthTenantAccess.php`.
- Modify: `packages/nvl/auth/src/Adapters/ApiTokens/SanctumApiTokenManager.php`, `packages/nvl/auth/src/Contracts/ApiTokenManager.php`, `packages/nvl/auth/src/Models/PersonalAccessToken.php`, `packages/nvl/auth/src/Services/ApiTokenPolicy.php`, `packages/nvl/auth/src/ValueObjects/ApiTokenSnapshot.php`.
- Modify under `packages/nvl/auth/src/Actions/ApiTokens/`: `CreateApiTokenAction.php`, `ListApiTokensAction.php`, `UpdateApiTokenAction.php`, `RotateApiTokenAction.php`, `RevokeApiTokenAction.php`, `RevokeAllApiTokensAction.php`.
- Create: `packages/nvl/auth/tests/Feature/Tenancy/TokenLifecycleTest.php`, `packages/nvl/auth/tests/Feature/Tenancy/TokenAdmissionHttpTest.php`.

**New interface**

```text
AuthTenantAdmission::assertAllowed(Authenticatable $subject, TenantId $tenant): void;
```

The service validates fresh principal eligibility and membership, and if the current access token is a persisted Sanctum token, verifies immutable stored tenant equality and expiry. A Sanctum transient first-party token selects the session path and still requires membership. Token abilities supplement authorization and never establish membership. Custom token managers must declare the same binding behavior through an additive `TenantBoundApiTokenManager` contract with `tenantForToken(Authenticatable $subject, string $tokenId): ?TenantId`; implement it in the Sanctum adapter and require it in Doctor before enabling tenant token routes. Put this new interface at `packages/nvl/auth/src/Contracts/TenantBoundApiTokenManager.php`.

- [ ] Write the cross-tenant lifecycle test:

```php
use Nvl\Auth\Actions\ApiTokens\CreateApiTokenAction;
use Nvl\Auth\Actions\ApiTokens\RevokeAllApiTokensAction;
use Nvl\Auth\Contracts\ApiTokenManager;
use Nvl\Auth\Data\Mutations\ApiTokenData;
use Nvl\Auth\Tests\Fixtures\AuthTenancyScenario;
use Nvl\Auth\ValueObjects\SubjectReference;

it('revokes only the active tenants tokens', function (): void {
    $s = new AuthTenancyScenario;
    $user = $this->user();
    $ref = SubjectReference::fromAuthenticatable($user);
    $s->member($s->a(), $ref);
    $s->member($s->b(), $ref);
    foreach ([$s->a(), $s->b()] as $tenant) {
        $s->run($tenant, fn () => app(CreateApiTokenAction::class)->execute(
            $user, new ApiTokenData('automation', ['profile:read']),
        ));
    }
    expect($s->run($s->a(), fn () => app(RevokeAllApiTokensAction::class)->execute($user)))->toBe(1);
    expect($s->run($s->b(), fn () => app(ApiTokenManager::class)->list($user)))->toHaveCount(1);
});
```

- [ ] Run `TokenLifecycleTest.php`; implement tenant scoping in every managed token query, not merely create. Construct the new token row with tenant binding in the same transaction as issuance; do not expose a token before ownership is durable. Rotation locks/reloads the old token in active tenant and creates its replacement with the same immutable binding. Updates ignore/reject supplied tenant ownership fields.
- [ ] Append optional `?string $tenantId = null` to `ApiTokenSnapshot` after existing constructor arguments and expose it only on the enabled display contract. Preserve disabled JSON/OpenAPI compatibility with a conditional projection rather than emitting a new null field globally. Tenant tokens are unavailable to other tenants even when the principal owns both. Platform token issuance requires explicit platform authority; unbound legacy tokens are never accepted on tenant routes.
- [ ] Wire `EnsureAuthTenantAccess` after global authentication/candidate resolution and before the foundation enters tenant context/model binding. Reuse foundation selector conflict handling. Middleware executes the service for session and bearer-token requests; private/public tenant resource admission remains the owning package's responsibility.
- [ ] Add HTTP tests for token A on route B, forged tenant header, session A/B membership differences, membership revoked after token issuance, principal disabled after issuance, first-party `tokenCan()` true without membership, rotation retaining binding, foreign token update/revoke/list, and package namespace isolation. Run both named tests and existing `SanctumIntegrationTest.php`.
- [ ] Commit token lifecycle and admission.

## Task 8: One-use tenant authentication intent and central session behavior

**Files**

- Create: `packages/nvl/auth/src/Services/TenantAuthenticationIntents.php`, `packages/nvl/auth/src/ValueObjects/IssuedTenantAuthenticationIntent.php`.
- Modify: `packages/nvl/auth/src/ValueObjects/AuthenticationRequestContext.php`, `packages/nvl/auth/src/Adapters/Laravel/LaravelBrowserSession.php`, `packages/nvl/auth/src/Adapters/Laravel/LaravelRequestAuditContextProvider.php`, `packages/nvl/auth/src/Adapters/Socialite/SocialiteIdentityProvider.php`.
- Modify under `packages/nvl/auth/src/Actions/Authentication/`: `LoginAction.php`, `EstablishAuthenticatedSessionAction.php`, `LogoutAction.php`.
- Modify under `packages/nvl/auth/src/Actions/SocialIdentities/`: `StartSocialAuthorizationAction.php`, `CompleteSocialAuthorizationAction.php`, `LinkSocialIdentityAction.php`.
- Modify under `packages/nvl/auth/src/Actions/Challenges/`: `IssueChallengeAction.php`, `RequestMagicLinkAuthenticationAction.php`, `ConsumeMagicLinkAction.php`, `ConsumeChallengeAction.php`, `ConsumeChallengeByIdAction.php`, `RequestSecurityCodeAction.php`, `VerifySecurityCodeAction.php`.
- Modify under `packages/nvl/auth/src/Actions/Passkeys/`: `BeginPasskeyAuthenticationAction.php`, `FinishPasskeyAuthenticationAction.php`.
- Create: `packages/nvl/auth/tests/Feature/Tenancy/AuthenticationIntentTest.php`, `packages/nvl/auth/tests/Feature/Tenancy/AuthenticationIntentHttpTest.php`.

**New interfaces**

```text
TenantAuthenticationIntents::issue(
    TenantId $tenant,
    TenantAuthenticationPurpose $purpose,
    string $sessionBinding,
    ?SubjectReference $subject = null,
    ?string $provider = null,
    ?string $returnPath = null,
): IssuedTenantAuthenticationIntent;

TenantAuthenticationIntents::consume(
    string $nonce,
    TenantAuthenticationPurpose $purpose,
    string $sessionBinding,
    ?SubjectReference $subject = null,
    ?string $provider = null,
): TenantId;

IssuedTenantAuthenticationIntent::__construct(string $id, string $nonce, CarbonImmutable $expiresAt);
```

`issue` validates tenant status and allowlisted local return path, records the hash of a cryptographically random nonce plus stable server-side session flow binding, and returns the raw nonce once. `consume` uses a row lock, constant-time hash verification, purpose/session/provider/subject match, expiry and tenant status, then atomically stamps consumed time. An incorrect match must not consume another flow's nonce. TTL is a bounded configuration value, default 10 minutes. The session binding is a server-generated per-flow secret preserved during session ID regeneration, not a client-supplied session ID. Do not treat issuing intent as membership authorization.

- [ ] Write a replay denial test:

```php
use Nvl\Auth\Enums\TenantAuthenticationPurpose;
use Nvl\Auth\Exceptions\AuthException;
use Nvl\Auth\Services\TenantAuthenticationIntents;
use Nvl\Auth\Tests\Fixtures\AuthTenancyScenario;

it('binds and consumes one tenant intent once', function (): void {
    $s = new AuthTenancyScenario;
    $intents = app(TenantAuthenticationIntents::class);
    $issued = $intents->issue($s->a(), TenantAuthenticationPurpose::SocialLogin, 'server-flow-secret', provider: 'github');
    expect(fn () => $intents->consume($issued->nonce, TenantAuthenticationPurpose::SocialLogin, 'wrong-flow', provider: 'github'))
        ->toThrow(AuthException::class);
    expect($intents->consume($issued->nonce, TenantAuthenticationPurpose::SocialLogin, 'server-flow-secret', provider: 'github')->value)
        ->toBe($s->a()->value);
    expect(fn () => $intents->consume($issued->nonce, TenantAuthenticationPurpose::SocialLogin, 'server-flow-secret', provider: 'github'))
        ->toThrow(AuthException::class);
});
```

- [ ] Run `AuthenticationIntentTest.php`; implement the typed store and expiry cleanup in existing Auth pruning.
- [ ] OAuth retains Socialite's state validation; store the intent reference server-side bound to that state/provider/flow, never override reserved `state` through `with()`. Passwordless challenge payloads and passkey ceremony session state store a server-owned intent reference. Callback/consumption compares any requested tenant with stored intent, then checks current membership before selecting a tenant. Login can succeed globally while tenant selection is denied; it cannot silently choose another tenant or grant requested-tenant membership.
- [ ] Keep authentication eligibility, MFA, social identity linking, recovery, and profile operations global through `AuthOperationBoundary::central`. Explicit self-service changes never rewrite another user's credentials. Make Request-capturing adapters scoped and ensure post-request/container lifecycle cleanup. Keep global AuthClient/client-session uniqueness and lifecycle unchanged; never overwrite a global session correlation with the last tenant used.
- [ ] Add HTTP tests for concurrent browser tabs selecting different tenants, session ID regeneration preserving the correct flow binding, intended destination mismatch, social provider mismatch, missing/expired intent, suspended tenant at callback, passwordless replay, real passkey ceremony state, central recovery with no tenant context, and no membership auto-creation. Run both named tests and existing authentication, social, challenge, and WebAuthn tests selected by changed paths.
- [ ] Commit captured intent with global session semantics.

## Task 9: Audit projection, events, deferred delivery, and isolation proof

**Files**

- Modify: `packages/nvl/auth/src/Services/AuthAuditRecorder.php`, `packages/nvl/auth/src/Models/AuthAudit.php`, `packages/nvl/auth/src/Actions/Audit/ListAuthAuditsAction.php`, `packages/nvl/auth/src/Actions/Audit/ShowAuthAuditAction.php`.
- Modify: `packages/nvl/auth/src/Events/RbacAssignmentChanged.php`, `packages/nvl/auth/src/Events/InvitationAccepted.php`, `packages/nvl/auth/src/Events/PrincipalChanged.php`, `packages/nvl/auth/src/Events/AuthDeliveryRequested.php`, `packages/nvl/auth/src/ValueObjects/AuthDeliveryRequest.php`.
- Create: `packages/nvl/auth/src/Contracts/TenantAwareAuthActivityBridge.php`, `packages/nvl/auth/src/ValueObjects/AuthEventContext.php`, `packages/nvl/auth/src/Services/CentralIdentityAuditRecorder.php`, `packages/nvl/auth/src/Services/AuthAuditWriter.php`.
- Create: `packages/nvl/auth/tests/Feature/Tenancy/AuditIsolationTest.php`, `packages/nvl/auth/tests/Feature/Tenancy/QueuedDeliveryContextTest.php`.

**New interface**

```text
AuthEventContext::__construct(TenantContextMode $mode, ?TenantId $tenantId = null);
TenantAwareAuthActivityBridge::record(string $action, AuthEventContext $context, array $metadata): void;
CentralIdentityAuditRecorder::record(AuthIdentityOperation $operation, string $action, string $outcome = 'success', ?SubjectReference $subject = null, ?Authenticatable $actor = null, array $metadata = []): ?AuthAudit;
AuthAuditWriter::write(AuthEventContext $context, string $action, string $outcome, ?SubjectReference $subject, ?Authenticatable $actor, ?string $clientId, array $metadata): ?AuthAudit;
```

The bridge metadata is an allowlisted `array<string, scalar|null>` projection. It never transports encrypted credentials, invitation tokens, full models, or global account metadata. Its implementer in the Activity integration owns Activity writes. `activity_bridge=disabled` refuses a tenant event bridge invocation; it does not write a fallback platform event. Auth's own mandatory audit recording remains available independently of Activity. The Activity workstream must register an implementation only after its recording/read path is tenant-safe.

The existing `AuthAuditRecorder` contract stays compatible: its implementation captures current context and delegates validated persistence to the internal `AuthAuditWriter`. Global identity Actions call `CentralIdentityAuditRecorder`, which validates the closed identity-operation/action pairing and emits `Platform` audit ownership without changing the runner context. The whitelist comprises the authentication, password, email verification, profile, TOTP, passkey, recovery-code, and social-identity events emitted by the existing corresponding Actions; unknown pairings fail configuration. This prevents a login initiated at tenant A from recording global credential facts in A's tenant timeline. Custom configured audit recorders must supply a compatible central identity recorder through the existing extension/provider binding mechanism before those features are declared tenant-ready.

- [ ] Add a test asserting record-time ownership survives later context changes:

```php
use Nvl\Auth\Contracts\AuthAuditRecorder;
use Nvl\Auth\Models\AuthAudit;
use Nvl\Auth\Tests\Fixtures\AuthTenancyScenario;

it('persists audit ownership when the event is recorded', function (): void {
    $s = new AuthTenancyScenario;
    $s->run($s->a(), fn () => app(AuthAuditRecorder::class)->record('membership.test'));
    $s->run($s->b(), fn () => app(AuthAuditRecorder::class)->record('membership.test'));
    $rows = AuthAudit::query()->where('action', 'membership.test')->orderBy('created_at')->get();
    expect($rows->pluck('tenant_id')->sort()->values()->all())->toBe([$s->a()->value, $s->b()->value]);
});
```

- [ ] Run `AuditIsolationTest.php`; record context as first-class columns at event creation. Tenant reads use the boundary and safe actor/subject projections. Global authentication/security facts use explicit `platform` ownership via the central identity recorder path, even if a user happened to start login at a tenant URL. Tenant administrators cannot read global login IP, MFA, recovery, or other tenants' events.
- [ ] Extend event/delivery values additively with optional captured `AuthEventContext` for disabled compatibility. Enabled tenant events require it. Queue payloads use scalar tenant/record IDs and `TenantJobEnvelope::capture(TenantContext $context)`, restored through `TenantQueueContext::run(TenantJobEnvelope $envelope, Closure $operation)` before deserialization. Register the central Auth mail job class using `TenantGlobalJobRegistry::register(string $jobClass)`; its input cannot carry tenant resources, and it cannot use an unresolved envelope to bypass ordinary tenant jobs. After-commit closures capture context values, then enter the runner when invoked; never read whichever context is current at callback execution.
- [ ] Add a real queued consumer fixture `packages/nvl/auth/tests/Fixtures/RecordQueuedAuthDelivery.php`: a `ShouldQueue` listener with `handle(AuthDeliveryRequested $event): void`; write only the received tenant UUID/message ID into a test-owned receipt table. Add `packages/nvl/auth/tests/Fixtures/ThrowingQueuedAuthDelivery.php` with the same signature, throwing once to exercise retry cleanup. The test invokes an actual database worker process, switches A/B messages, retries failure, and asserts each receipt/context plus no residual team/tenant state. It does not call `handle()` directly or use Queue fakes as proof.
- [ ] Test delayed invitation delivery outcome against a revoked/rotated invitation, disabled bridge fail-closed behavior, foreign audit ID/model, and retained subject relations. Run the two named tests and the existing delivery-context/value tests.
- [ ] Commit audit and durable delivery context before marking any tenant-emitting bridge ready.

## Task 10: Reviewed adoption, diagnostics, compatibility, and release gates

**Files**

- Modify: `packages/nvl/auth/src/Tenancy/AuthTenancyAdoption.php` from Task 2; create `packages/nvl/auth/src/Tenancy/AuthTenancyMapping.php`.
- Modify: `packages/nvl/auth/src/Services/AuthSchemaManager.php`, `packages/nvl/auth/src/Console/Commands/AuthDoctorCommand.php`, `packages/nvl/auth/src/Console/Commands/InstallAuthSchemaCommand.php`, `packages/nvl/auth/src/Console/Commands/PruneAuthStateCommand.php`, `packages/nvl/auth/src/Actions/PruneAuthStateAction.php`.
- Create: `packages/nvl/auth/tests/Feature/TenancyAdoption/AdoptionTest.php`, `packages/nvl/auth/tests/Feature/TenancyAdoption/DoctorTest.php`.
- Modify: `packages/nvl/auth/README.md`, `packages/nvl/auth/docs/configuration.md`, `packages/nvl/auth/docs/php-api.md`, `packages/nvl/auth/docs/http-api.md`, `packages/nvl/auth/docs/schema.md`, `packages/nvl/auth/docs/principal-adoption.md`, `packages/nvl/auth/docs/operations.md`, `packages/nvl/auth/docs/security.md`.
- Modify: `packages/nvl/auth/tests/Unit/OpenApiContractTest.php`, `packages/nvl/auth/docs/openapi.json`, `packages/nvl/auth/docs/openapi.md`; generate `resources/js/types/generated/auth.d.ts` through the existing generator.
- Modify: `tools/fixtures/auth-production-consumer/app/Console/Commands/AuthConsumerSmokeCommand.php`, `tools/fixtures/auth-production-consumer/config/nvl-auth.php`, `tools/fixtures/auth-production-consumer/typescript/auth-consumer.ts`, `tools/run-auth-production-consumer.sh`, `tests/Contract/AuthProductionConsumerWorkflowTest.php`.

**Auth mapping contract**

`AuthTenancyMapping::validate(TenantAssignment $assignment): void` validates an immutable coordinator assignment; it does not create a second manifest store. The coordinator hashes metadata together with resource/record/tenant identity. Use `TenantAdoptionMappings::assignments(TenantAdoptionPlan $plan, string $resource, ?string $afterRecordId, int $limit): array` for paginated destination rows and `metadataFor(TenantAdoptionPlan $plan, string $resource, string $recordId): array` for exact payload lookup. `tenantFor()` remains the canonical single-row ownership resolver.

Exact Auth metadata schemas, with unknown keys rejected:

| Resource | `recordId` | Metadata shape |
|---|---|---|
| `auth.memberships` | Destination membership UUID | `array{subject_type: string, subject_id: string, status: 'active'|'suspended'|'revoked', is_owner: bool, role_ids: list<string>, permission_ids: list<string>}` |
| `auth.roles` | Destination role UUID | `array{source_id: string, parent_destination_id: string|null}`; UUID fields reference reviewed source/destination roles |
| `auth.invitations` | Existing invitation UUID | `array{role_ids: list<string>, permission_ids: list<string>}`; only live, unconsumed invitations are eligible |
| `auth.audits` | Existing audit UUID with verified tenant evidence | `array{evidence_reference: string}` bounded to 191 characters; no sensitive payload |

Subject type/ID lengths match `SubjectReference`; arrays are capped at the existing RBAC identifier limit, UUIDs are canonical, duplicates rejected, and role/direct-permission destinations must be in that membership's tenant. Active owner assignment requires active eligible principal. Repeated role copying uses distinct destination UUIDs, with permission links copied from the source; explicit `parent_destination_id` closes the tenant parent graph. Membership role/permission lists are reviewed grants, not automatically copied from every legacy user-role row. Verify that every legacy assignment has a reviewed membership disposition before discarding old pivot rows. Live credentials (MFA/passkeys/social/passwords) are neither copied nor tenant-owned.

First-release cutover deliberately revokes and reissues live package-managed unbound tokens; it never guesses the principal's first tenant or converts an existing bearer secret to tenant authority. Drain/invalidate active global authentication flows during maintenance. Ambiguous invitations are revoked through the existing authorized workflow before preparation; every remaining live invitation requires a mapping. Previously global historical audits and consumed/revoked invitations/challenges use the adapter's fixed, explicit platform-history policy; an evidence-backed audit mapping can instead identify a tenant. This policy does not permit unmapped live tenant roots. Unrelated host tokens outside Auth's namespace remain owned by the host adapter and are excluded from package revocation.

`AuthTenancyAdoption` implements the exact `TenantAdoptionAdapter` methods `resources(): array`, `prepare(TenantAdoptionPlan): void`, `backfill(TenantAdoptionPlan, ?string $cursor, int $limit): TenantBackfillResult`, `verify(TenantAdoptionPlan): TenantVerification`, and `activate(TenantAdoptionPlan): void`. Its resources are the eight Root declarations in Task 1. Use the coordinator's checkpoints and normalized connection. The cursor encodes an allowlisted phase plus the last destination UUID; each non-final result must advance and report processed count. Verification emits bounded codes/record identifiers only.

- [ ] Add the concrete existing-data red test below in the legacy adoption case. The helper `user()` comes from the defined Testbench case; all coordinator methods are frozen shared APIs:

```php
use Illuminate\Support\Str;
use Nvl\Auth\Models\Role;
use Nvl\Auth\Tests\Fixtures\AuthTenancyScenario;
use Nvl\Auth\ValueObjects\SubjectReference;
use Nvl\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAssignment;

it('maps a shared legacy role into reviewed tenant memberships', function (): void {
    $s = new AuthTenancyScenario;
    $one = $this->user('one@example.test');
    $two = $this->user('two@example.test');
    $source = Role::query()->create(['name' => 'manager', 'guard_name' => 'web']);
    $one->assignRole($source);
    $two->assignRole($source);
    $mappings = [];
    foreach ([[$one, $s->a()], [$two, $s->b()]] as [$principal, $tenant]) {
        $roleId = (string) Str::uuid();
        $ref = SubjectReference::fromAuthenticatable($principal);
        $mappings[] = new TenantAssignment('auth.roles', $roleId, $tenant, [
            'source_id' => $source->id, 'parent_destination_id' => null,
        ]);
        $mappings[] = new TenantAssignment('auth.memberships', (string) Str::uuid(), $tenant, [
            'subject_type' => $ref->type, 'subject_id' => $ref->identifier,
            'status' => 'active', 'is_owner' => true, 'role_ids' => [$roleId], 'permission_ids' => [],
        ]);
    }
    $coordinator = app(TenantAdoptionCoordinator::class);
    $operation = new PlatformOperation('auth-test.adoption', 'system', 'fixture');
    $plan = $coordinator->prepare(['auth'], $mappings, $operation);
    while (! $coordinator->backfill($plan, 1, $operation)) {}
    expect($coordinator->verify($plan)->errors)->toBe([]);
    $coordinator->activate($plan, $operation);
    app(TenantMembershipAccess::class)->assertMember($one, $s->a());
    app(TenantMembershipAccess::class)->assertMember($two, $s->b());
    expect($s->run($s->a(), fn () => $one->fresh()->hasRole('manager')))->toBeTrue();
    expect($s->run($s->b(), fn () => $one->fresh()->hasRole('manager')))->toBeFalse();
});
```

Add a second test that resumes the same run via `resume($plan->id)` without duplicate rows and rejects changed input/configuration fingerprints. The first coordinator activation invalidates this process's probe; the sealed-consumer test separately proves a real worker/application restart.
- [ ] Run `AdoptionTest.php` and `DoctorTest.php`; implement the foundation adapter methods with the package algorithm below:

```text
prepare: acquire the shared adoption checkpoint; confirm maintenance and writer/worker drain;
         create only selected Auth optional schema; mark affected resources prepared.
backfill: validate immutable input fingerprint; write memberships/lock rows first;
          clone/map roles and parent graph; map assignment pivots;
          classify invitation/token/challenge/audit ownership; checkpoint batches.
verify: normalize connections; compare mapped/source counts; assert UUID compatibility,
        active owners, canonical references, role-parent/assignment tenant equality,
        no null roles, no unmapped principals/assignments, and token dispositions.
activate: replace global role uniqueness and pivot primary keys; validate non-null and
          same-tenant constraints; verify current ownership hash; mark resources active;
          initialize the command's registrar with teams=true/team_foreign_key=tenant_id;
          clear Spatie caches and require process restart before serving traffic.
```

No activation command discovers and silently assigns existing data. Mixed connections for memberships, role assignments, invitation consumption, or token issuance deny configuration. Resolve aliases to effective connection identity; `null` and the named default are compatible when they refer to the same connection. Do not promise atomic cross-database account registration.

- [ ] Extend Auth Doctor with named checks: membership adapter/principal resolver; compatible subject key type; optional migration ownership; actual unique/index/foreign-key shape; normalized transaction connections; provider/registrar team config; no null-team roles; prepared/active marker consistency; one active owner per active tenant at minimum; public invitation proof adapter; tenant-capable API token adapter; route admission before binding; scoped Request/context services; global-only clients; Activity bridge capability. Checks are read-only and report context/configuration errors distinctly from empty data.
- [ ] Enforce the disabled/adopted matrix: untouched disabled install, prepared maintenance install, activated tenant install, feature disabled after adoption, provider omitted after adoption, vendor migration mode, copied migration mode, host directory, principal management disabled with host principal, and existing legacy schema enabling tenancy later. Cache config/routes before exercising each supported consumer mode.
- [ ] Update actual package docs/OpenAPI/generated types for membership APIs, strict tenant token lifecycle, invitation proof-required result, platform-only principal/vocabulary APIs, and the central identity exception. Keep disabled legacy request/response contracts stable. Generate with `php artisan nvl:data:types:generate --no-interaction`, then check with `php artisan nvl:data:types:check --no-interaction` and the existing Auth TypeScript consumer command. Verify exact command availability with `php artisan list --no-interaction` before execution.
- [ ] Extend the sealed consumer harness with one explicit tenancy profile for each migration ownership mode; preserve existing disabled profiles. The tenant smoke journey is one global principal in A/B, distinct roles, invitation acceptance, membership revocation, tenant-bound token, and cached configuration. It includes a restore rehearsal from the pre-adoption snapshot; it never treats dropping tenant columns or toggling false as rollback.
- [ ] Run the full Auth package command once after focused tests pass; run `php artisan test --compact tests/Contract/AuthProductionConsumerWorkflowTest.php` and the existing generated-type consumer check. Run `../../../vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=2G` with working directory `packages/nvl/auth`; this matches its relative `src`/`database/factories` analysis paths. Finish with the existing PostgreSQL 17 and MySQL/MariaDB stateful package jobs, real owner race/worker tests, and `bash tools/run-auth-production-consumer.sh`. Report skips/environment failures rather than claiming proof from SQLite.
- [ ] Commit boundary: complete Auth adopter/Doctor/docs/consumer proof. Only now may the suite mark Auth tenant-ready and proceed to production adoption rehearsal.

## Review checklist and completion evidence

### Focused command index

These are literal root-directory commands for one representative red/green gate per boundary; run the adjacent tests named in each task after its focused gate passes. Task 2's schema test supplies its own legacy/prepared setup. Task 3 onward use the nontransactional active fixture.

```bash
vendor/bin/pest --test-directory=packages/nvl/auth/tests --configuration=packages/nvl/auth/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/auth/tests/Provider/DisabledTenancyCompatibilityTest.php
vendor/bin/pest --test-directory=packages/nvl/auth/tests --configuration=packages/nvl/auth/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/auth/tests/Feature/TenancyAdoption/SchemaTest.php
vendor/bin/pest --test-directory=packages/nvl/auth/tests --configuration=packages/nvl/auth/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/auth/tests/Feature/Tenancy/MembershipLifecycleTest.php
vendor/bin/pest --test-directory=packages/nvl/auth/tests --configuration=packages/nvl/auth/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/auth/tests/Feature/Tenancy/RbacIsolationTest.php
vendor/bin/pest --test-directory=packages/nvl/auth/tests --configuration=packages/nvl/auth/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/auth/tests/Feature/Tenancy/PrincipalBoundaryTest.php
vendor/bin/pest --test-directory=packages/nvl/auth/tests --configuration=packages/nvl/auth/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/auth/tests/Feature/Tenancy/InvitationAcceptanceTest.php
vendor/bin/pest --test-directory=packages/nvl/auth/tests --configuration=packages/nvl/auth/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/auth/tests/Feature/Tenancy/TokenLifecycleTest.php
vendor/bin/pest --test-directory=packages/nvl/auth/tests --configuration=packages/nvl/auth/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/auth/tests/Feature/Tenancy/AuthenticationIntentTest.php
vendor/bin/pest --test-directory=packages/nvl/auth/tests --configuration=packages/nvl/auth/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/auth/tests/Feature/Tenancy/AuditIsolationTest.php
vendor/bin/pest --test-directory=packages/nvl/auth/tests --configuration=packages/nvl/auth/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/auth/tests/Feature/TenancyAdoption/AdoptionTest.php
```

### Acceptance evidence

- [ ] Every existing Auth Action has one classification: global identity, explicit platform management, tenant-owned operation, or bounded bootstrap. No permissive policy changes that classification.
- [ ] Role lookup, role DTO validation, inverse counts, hierarchy, catalog synchronization, templates, assignments, caches, and retained relations all have tenant proof.
- [ ] Membership suspension/revocation, role edits, global account deletion, invitation acceptance, and last-owner races have compatible lock ordering and same-connection rollback proof.
- [ ] All six token operations plus bearer/session admission preserve tenant binding and live membership checks.
- [ ] OAuth/passwordless/passkey intent survives legitimate session regeneration and rejects replay, mismatched tenant/provider/session, and suspended tenants.
- [ ] Global identity data is absent from tenant audit/member projections; deferred deliveries carry record-time context.
- [ ] Schema-before-teams ordering and disabled-mode compatibility hold in standalone and suite installations, with package-owned and host-copied migrations.
- [ ] Completion report lists exact focused/package/database/consumer commands, pass/fail/skip counts, and outstanding release-environment gates. No claim of completion relies solely on an unexecuted plan or a Queue fake.
