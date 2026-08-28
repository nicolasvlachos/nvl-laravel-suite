# Auth Consumer API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let consumers implement RBAC lists, pickers, validation, and role analytics without querying Auth models.

**Architecture:** New RBAC Actions reuse `FeatureGate`, `ManagementAuthorizer`, `AuthModelRegistry`, and `RbacEntityLocator`. Every Action returns TypeScript-enabled DTOs, selects only projection columns, applies portable ordering, and clamps inputs through Auth-owned limits.

**Tech Stack:** PHP 8.4, Laravel 13 Eloquent, Spatie Laravel Data, TypeScript Transformer, Pest 4.

**Spec:** `docs/superpowers/specs/2026-08-28-consumer-readiness-v2-design.md`

## Global Constraints

- All Actions require `AuthFeature::Rbac` read admission and `nvl-auth.rbac.view` authorization.
- Suggestion searches accept either an empty value for initial options or 2–160 characters; one-character non-empty searches return an empty collection.
- Role limits are clamped to 1–50 and permission limits to 1–100, with package configuration allowed only to lower those maxima.
- Option and analytics APIs return DTOs/results, never Eloquent models or builders.
- Identifier resolution accepts at most 100 unique UUID/name inputs, preserves caller order, and rejects unknown or ambiguous identifiers.
- Query ordering must be portable across SQLite, PostgreSQL, MySQL, and MariaDB.

---

### Task 1 (CR-05): Define RBAC consumer DTOs and limits

**Files:**
- Create: `packages/nvl/auth/src/Data/Display/RoleOptionData.php`
- Create: `packages/nvl/auth/src/Data/Display/PermissionOptionData.php`
- Create: `packages/nvl/auth/src/Data/Display/PermissionGroupData.php`
- Create: `packages/nvl/auth/src/Data/Display/RoleListItemData.php`
- Create: `packages/nvl/auth/src/Data/Display/PermissionListItemData.php`
- Create: `packages/nvl/auth/src/Data/Display/RoleNameAvailabilityData.php`
- Create: `packages/nvl/auth/src/Data/Display/RoleAnalyticsData.php`
- Modify: `packages/nvl/auth/config/nvl-auth.php`
- Modify: `packages/nvl/auth/tests/Feature/RbacManagementTest.php`
- Modify: `packages/nvl/auth/tests/Unit/OpenApiContractTest.php`

**Interfaces:**
- Consumes: existing Auth model fields and `Nvl\Data\Traits\DataTransform`.
- Produces: stable DTO constructors used by CR-06 through CR-08.

- [ ] **Step 1: Write failing DTO serialization tests**

```php
$role = RoleOptionData::fromModel(Role::factory()->create([
    'name' => 'editor',
    'display_name' => 'Editor',
    'description' => 'Edits content',
    'is_system' => false,
]));

expect($role->toArray())->toMatchArray([
    'name' => 'editor',
    'label' => 'Editor',
    'description' => 'Edits content',
    'is_system' => false,
]);
```

Add equivalent assertions for permission option/group, name availability, and
role analytics serialization.

- [ ] **Step 2: Run the focused test and verify missing DTOs fail**

Run: `vendor/bin/pest --configuration=packages/nvl/auth/phpunit.xml.dist --compact packages/nvl/auth/tests/Feature/RbacManagementTest.php`

Expected: FAIL because the display DTO classes do not exist.

- [ ] **Step 3: Implement the DTO contracts**

Use `#[MapOutputName(CamelCaseMapper::class)]`, `#[TypeScript]`, and
`DataTransform` on all seven DTOs. Constructors:

```php
RoleOptionData(string $id, string $name, string $label, ?string $description, bool $isSystem)
PermissionOptionData(string $id, string $name, string $label, ?string $description, string $group)
PermissionGroupData(string $value, string $label, int $permissionsCount)
RoleListItemData(string $id, string $name, string $label, ?string $description, string $guard, bool $isSystem, int $priority, ?string $parentId, ?string $parentName, array $permissionIds, int $permissionsCount, int $usersCount, CarbonImmutable $createdAt)
PermissionListItemData(string $id, string $name, string $label, ?string $description, string $guard, string $group, array $roleIds, int $rolesCount, int $usersCount, CarbonImmutable $createdAt)
RoleNameAvailabilityData(string $name, bool $available, ?string $conflictingRoleId)
RoleAnalyticsData(string $roleId, int $users, int $activeUsers, int $inactiveUsers, int $permissions, int $children, int $descendants, ?string $parentName, array $permissionGroups)
```

`label` falls back to `name`; blank permission groups normalize to `general`.
`permissionGroups` is `array<string, int>` sorted by descending count and then
ascending key.

- [ ] **Step 4: Add package-owned limits**

Under `features.rbac.settings` add:

```php
'role_option_limit' => 50,
'permission_option_limit' => 100,
'identifier_resolution_limit' => 100,
```

Extend the unit contract test so non-positive or over-hard-cap values are
rejected by the Action-level normalization used in later tasks.

- [ ] **Step 5: Run DTO, TypeScript, and formatting checks**

Run: `vendor/bin/pest --configuration=packages/nvl/auth/phpunit.xml.dist --compact packages/nvl/auth/tests/Feature/RbacManagementTest.php packages/nvl/auth/tests/Unit/OpenApiContractTest.php`

Expected: PASS.

Run: `php artisan nvl:data:types:generate --fail-on-warning`

Run: `php artisan nvl:data:types:check --fail-on-warning`

Expected: PASS with all seven DTOs represented in generated contracts.

- [ ] **Step 6: Commit CR-05**

```bash
git add packages/nvl/auth/src/Data/Display packages/nvl/auth/config/nvl-auth.php packages/nvl/auth/tests/Feature/RbacManagementTest.php packages/nvl/auth/tests/Unit/OpenApiContractTest.php resources/js/types
git commit -m "feat(auth): add RBAC consumer projections"
```

### Task 2 (CR-06): Add role/permission catalog and option Actions

**Files:**
- Create: `packages/nvl/auth/src/Actions/Rbac/ListRoleOptionsAction.php`
- Create: `packages/nvl/auth/src/Actions/Rbac/SuggestRolesAction.php`
- Create: `packages/nvl/auth/src/Actions/Rbac/ListPermissionOptionsAction.php`
- Create: `packages/nvl/auth/src/Actions/Rbac/SuggestPermissionsAction.php`
- Create: `packages/nvl/auth/src/Actions/Rbac/ListPermissionGroupsAction.php`
- Create: `packages/nvl/auth/src/Actions/Rbac/ListRoleCatalogAction.php`
- Create: `packages/nvl/auth/src/Actions/Rbac/ListPermissionCatalogAction.php`
- Modify: `packages/nvl/auth/src/Data/Queries/RoleIndexQueryData.php`
- Modify: `packages/nvl/auth/src/Data/Queries/PermissionIndexQueryData.php`
- Modify: `packages/nvl/auth/tests/Feature/RbacManagementTest.php`

**Interfaces:**
- Consumes: CR-05 DTOs, `FeatureGate`, `ManagementAuthorizer`, `AuthModelRegistry`, and Auth configuration limits.
- Produces: five bounded option/group Collections and two DTO paginators.

- [ ] **Step 1: Write failing authorization, projection, and bound tests**

```php
it('returns bounded authorized role options as DTOs', function (): void {
    Role::factory()->count(60)->create();

    $items = app(ListRoleOptionsAction::class)->execute($this->actor, limit: 500);

    expect($items)->toHaveCount(50)
        ->and($items->first())->toBeInstanceOf(RoleOptionData::class);
});

it('returns no suggestions for a one-character search', function (): void {
    expect(app(SuggestPermissionsAction::class)->execute(
        $this->actor,
        search: 'a',
    ))->toBeEmpty();
});
```

Also assert denied actors cannot infer counts/groups, search matches canonical
name/display name/description, group filtering is exact, selected columns do
not trigger lazy loads, and group counts are deterministic. Add catalog tests
for allowlisted filters/sorts, stable pagination, optional assignment IDs,
absence of related user identity data, and one-versus-100 query ceilings.

- [ ] **Step 2: Run the focused tests and verify missing Actions fail**

Run: `vendor/bin/pest --configuration=packages/nvl/auth/phpunit.xml.dist --compact packages/nvl/auth/tests/Feature/RbacManagementTest.php`

Expected: FAIL because the seven Actions do not exist.

- [ ] **Step 3: Implement role options and suggestions**

Public signatures:

```php
public function execute(Authenticatable $actor, ?string $search = null, ?int $limit = null): Collection
```

Both role Actions select `id`, `name`, `display_name`, `description`, and
`is_system`; normalize search; apply portable `where` clauses; order system
roles first and then `name`; clamp to the configured/hard maximum; and map each
row through `RoleOptionData::fromModel()`.

`SuggestRolesAction` returns an empty collection for a one-character non-empty
search. `ListRoleOptionsAction` permits an empty search for initial picker data.

- [ ] **Step 4: Implement permission options, suggestions, and groups**

Permission signatures:

```php
public function execute(Authenticatable $actor, ?string $search = null, ?string $group = null, ?int $limit = null): Collection
public function execute(Authenticatable $actor): Collection // groups
```

Select `id`, `name`, `display_name`, `description`, and `group`; order by group
then name; clamp at 100; map to `PermissionOptionData`. Group listing performs a
single `select group, count(*)` aggregate, normalizes blank groups to `general`,
orders by group, and returns `PermissionGroupData`.

- [ ] **Step 5: Implement safe DTO catalogs**

Extend `RoleIndexQueryData` with optional `isSystem`, `guard`, `sort`,
`direction`, and `includeAssignments`; extend `PermissionIndexQueryData` with
optional `guard`, `sort`, `direction`, and `includeAssignments`. Validate sort
aliases and directions in the DTOs. The new catalog Actions accept those DTOs,
authorize before querying, clamp pagination to 100, select only the projection
columns, load only assignment IDs when requested, preserve paginator metadata
with `through()`, and return `RoleListItemData`/`PermissionListItemData`.
Allowlisted role sorts are `name`, `label`, `priority`, and `created_at`;
permission sorts are `name`, `label`, `group`, and `created_at`. Related user
records/emails are never an include.

- [ ] **Step 6: Prove query ceilings and package quality**

Add a query-count assertion comparing one and fifty options with a ceiling of
one query per Action.

Run: `vendor/bin/pest --configuration=packages/nvl/auth/phpunit.xml.dist --compact packages/nvl/auth/tests/Feature/RbacManagementTest.php`

Expected: PASS.

Run: `php tools/run-package-quality.php auth`

Expected: PASS.

Run: `php artisan nvl:data:types:generate --fail-on-warning`

Run: `composer types:check`

Expected: PASS with the extended catalog query DTOs generated.

- [ ] **Step 7: Commit CR-06**

```bash
git add packages/nvl/auth/src/Actions/Rbac packages/nvl/auth/src/Data/Queries packages/nvl/auth/tests/Feature/RbacManagementTest.php resources/js/types
git commit -m "feat(auth): add RBAC catalog and option reads"
```

### Task 3 (CR-07): Add name availability and identifier resolution

**Files:**
- Create: `packages/nvl/auth/src/Actions/Rbac/CheckRoleNameAvailabilityAction.php`
- Create: `packages/nvl/auth/src/Actions/Rbac/ResolveRoleIdentifiersAction.php`
- Create: `packages/nvl/auth/src/Actions/Rbac/ResolvePermissionIdentifiersAction.php`
- Create: `packages/nvl/auth/src/Actions/Rbac/AddRolePermissionsAction.php`
- Create: `packages/nvl/auth/src/Actions/Rbac/SyncRolePermissionsAction.php`
- Create: `packages/nvl/auth/src/Actions/Rbac/CreatePermissionWithRolesAction.php`
- Create: `packages/nvl/auth/src/Services/RbacAssignmentService.php`
- Modify: `packages/nvl/auth/src/Services/RbacEntityLocator.php`
- Modify: `packages/nvl/auth/tests/Feature/RbacManagementTest.php`

**Interfaces:**
- Consumes: CR-05 option/availability DTOs and `RbacEntityLocator`.
- Produces: deterministic identifier reads plus atomic assignment workflows used by host form DTOs and mutation adapters.

- [ ] **Step 1: Write failing validation and ordering tests**

```php
it('resolves mixed role ids and names in caller order', function (): void {
    $first = Role::factory()->create(['name' => 'editor']);
    $second = Role::factory()->create(['name' => 'reviewer']);

    $resolved = app(ResolveRoleIdentifiersAction::class)->execute(
        $this->actor,
        [$second->id, $first->name],
    );

    expect($resolved->pluck('id')->all())->toBe([$second->id, $first->id]);
});
```

Also test duplicates, empty strings, more than 100 values, unknown values,
guard collisions, authorization denial, and availability with `exceptId`. Add
assignment tests for add versus replace semantics, empty replacement, duplicate
IDs, rollback, audit/event emission, system-role rules, and creating a
permission with initial roles in one transaction.

- [ ] **Step 2: Run the focused tests and verify missing Actions fail**

Run: `vendor/bin/pest --configuration=packages/nvl/auth/phpunit.xml.dist --compact packages/nvl/auth/tests/Feature/RbacManagementTest.php`

Expected: FAIL because the resolution and assignment Actions do not exist.

- [ ] **Step 3: Implement role name availability**

Signature:

```php
public function execute(
    Authenticatable $actor,
    string $name,
    ?string $exceptId = null,
): RoleNameAvailabilityData
```

Trim and require 1–160 characters, use the configured role class and guard,
exclude only the exact UUID when supplied, and return the conflicting role ID
without returning the model.

- [ ] **Step 4: Implement two-query identifier resolution**

Normalize strings, reject duplicates before querying, partition UUID-like IDs
from names, perform at most one ID query and one name query, index results by
both canonical ID and name, reject missing/ambiguous inputs with `AuthException`,
then emit option DTOs in original order. Add focused methods to
`RbacEntityLocator` for the shared normalization/query logic; do not expose its
builders publicly.

- [ ] **Step 5: Implement package-owned assignment workflows**

`RbacAssignmentService` resolves the role and permission identifiers through
the configured model registry, authorizes the specific manage ability before
loading assignments, uses the Auth connection with deadlock retries, syncs in
deterministic canonical-name order, clears the permission cache, records one
bounded audit entry, and dispatches one `RbacChanged` event after the durable
change. `AddRolePermissionsAction` unions with existing assignments;
`SyncRolePermissionsAction` replaces them. `CreatePermissionWithRolesAction`
creates the permission and attaches it to all resolved roles inside one outer
transaction so a missing/denied role rolls back creation.

- [ ] **Step 6: Run feature, analysis, and formatting checks**

Run: `vendor/bin/pest --configuration=packages/nvl/auth/phpunit.xml.dist --compact packages/nvl/auth/tests/Feature/RbacManagementTest.php`

Expected: PASS with a maximum of two resolution queries for one and 100 inputs.

Run: `composer analyse --working-dir=packages/nvl/auth`

Expected: PASS.

- [ ] **Step 7: Commit CR-07**

```bash
git add packages/nvl/auth/src/Actions/Rbac/CheckRoleNameAvailabilityAction.php packages/nvl/auth/src/Actions/Rbac/ResolveRoleIdentifiersAction.php packages/nvl/auth/src/Actions/Rbac/ResolvePermissionIdentifiersAction.php packages/nvl/auth/src/Actions/Rbac/AddRolePermissionsAction.php packages/nvl/auth/src/Actions/Rbac/SyncRolePermissionsAction.php packages/nvl/auth/src/Actions/Rbac/CreatePermissionWithRolesAction.php packages/nvl/auth/src/Services/RbacEntityLocator.php packages/nvl/auth/src/Services/RbacAssignmentService.php packages/nvl/auth/tests/Feature/RbacManagementTest.php
git commit -m "feat(auth): add bounded RBAC resolution and assignments"
```

### Task 4 (CR-08): Add bounded per-role analytics

**Files:**
- Create: `packages/nvl/auth/src/Actions/Rbac/ShowRoleAnalyticsAction.php`
- Modify: `packages/nvl/auth/src/Data/Display/RoleAnalyticsData.php`
- Modify: `packages/nvl/auth/tests/Feature/RbacManagementTest.php`
- Modify: `packages/nvl/auth/README.md`
- Modify: `tools/consumer-readiness.php`
- Modify: `tests/Contract/ConsumerReadinessTest.php`

**Interfaces:**
- Consumes: CR-05 `RoleAnalyticsData`, Auth role hierarchy, and principal active-attribute mapping.
- Produces: `ShowRoleAnalyticsAction::execute(Authenticatable $actor, Role|string $role): RoleAnalyticsData`.

- [ ] **Step 1: Write failing analytics and constant-query tests**

```php
$analytics = app(ShowRoleAnalyticsAction::class)->execute($this->actor, $role->id);

expect($analytics->toArray())->toMatchArray([
    'roleId' => $role->id,
    'users' => 3,
    'activeUsers' => 2,
    'inactiveUsers' => 1,
    'permissions' => 4,
]);
```

Compare one and twenty-five users/permissions/children and require the same
query count. Add a persisted-cycle fixture and assert descendant traversal
terminates without counting a role twice.

- [ ] **Step 2: Run the focused tests and verify the Action is missing**

Run: `vendor/bin/pest --configuration=packages/nvl/auth/phpunit.xml.dist --compact packages/nvl/auth/tests/Feature/RbacManagementTest.php`

Expected: FAIL because `ShowRoleAnalyticsAction` does not exist.

- [ ] **Step 3: Implement package-owned role analytics**

Authorize before loading aggregates. Resolve the role through the configured
model registry. Use aggregate queries for active/inactive users and permission
groups, load the hierarchy graph with only `id`, `parent_id`, and `name`, and
compute descendants iteratively with a visited-ID set. Do not query Activity;
consumers compose Activity history through `ActivityReadService`.

- [ ] **Step 4: Document the replacement boundary**

Add an Auth README example that combines:

```php
$role = $showRole->execute($actor, $roleId);
$analytics = $showRoleAnalytics->execute($actor, $roleId);
$activity = $activityReads->paginateForSubjectKey($role->getMorphClass(), $role->getKey(), 20);
```

State that the consumer may use the Action-returned role identity for the
Activity call but must not initiate a Role query.

- [ ] **Step 5: Run package and suite contract gates**

Run: `php tools/run-package-quality.php auth`

Expected: PASS.

Run: `php artisan test --compact tests/Contract/ConsumerReadinessTest.php`

Expected: PASS.

Run: `composer types:check`

Expected: PASS.

- [ ] **Step 6: Commit CR-08**

```bash
git add packages/nvl/auth/src/Actions/Rbac/ShowRoleAnalyticsAction.php packages/nvl/auth/src/Data/Display/RoleAnalyticsData.php packages/nvl/auth/tests/Feature/RbacManagementTest.php packages/nvl/auth/README.md tools/consumer-readiness.php tests/Contract/ConsumerReadinessTest.php
git commit -m "feat(auth): add role analytics projection"
```

### Workstream acceptance gate

- [ ] Run `php tools/run-package-quality.php auth`.
- [ ] Run `composer contracts:check` and `composer types:check` from the suite root.
- [ ] In KPO, replace `RbacPresentationReadService` methods one endpoint at a time and run the focused Auth tests after each replacement.
- [ ] Run `php artisan nvl:suite:consumer-audit --strict` in KPO and confirm the RBAC presentation findings are gone before deleting the service.
