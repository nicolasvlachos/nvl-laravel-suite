# Tenancy Execution Contracts

**Status:** Execution baseline for the requested second architecture review.
No runtime implementation is included. Shared database and global identities
with multiple memberships are the explicit planning assumptions. Changing either
requires revising these contracts before executing migrations.

**Parent design:** [Configurable tenancy](2026-09-16-configurable-tenancy-design.md).

## 1. Binding decisions

- PHP 8.4 and Laravel 13 are the execution baseline.
- No new external Composer dependency is required.
- All tenancy types and runtime logic live in `Nvl\Tenancy`, including the
  disabled implementation. Support remains free of tenant domain logic.
- `nvl/tenancy` requires only `nvl/support`, `nvl/data`, and their existing
  framework infrastructure; it never requires Auth or another domain package.
- Integrating packages explicitly require `nvl/tenancy`. Its library/provider
  can be present with `tenancy.enabled=false`; this creates no tenant schema,
  middleware, route, or data adoption automatically.
- Independent package installation remains supported. Installing Media brings
  the Tenancy library as a dependency but never requires NVL Auth.
- CSV also requires the library when its queued-import integration ships. Data,
  Filterable, Primitives, and Support keep their current dependency direction;
  their integration tests prove preservation of caller-owned isolation.
- Existing public Actions retain their normal signatures where possible.
  Context is injected; tenant IDs are not mass-assignable client DTO fields.
- Each package owns its new schema, query predicates, grants, adoption adapter,
  audit facts, and lifecycle. Tenancy never writes another package's tables.
- Missing context in enabled mode fails closed. Platform access, central identity
  use cases, and tenant operations are explicit and separate.
- Released migrations are immutable. Optional migration sets use distinct paths
  and explicit registration, never an `up()` that silently skips then records
  itself as applied.

## 2. Configuration and module selection

The canonical namespace is `tenancy`, file `config/tenancy.php`. Use the existing
deep-map/atomic-list merger. Values are scalars, literal arrays, or class strings;
no closures, actor IDs, or current tenant selections belong in cached config.

```php
return [
    'enabled' => false,
    'strategy' => 'shared-database',
    'connection' => null,
    'profile' => 'application',
    'directory' => [
        'driver' => 'package',
        'adapter' => null,
    ],
    'resolvers' => [
        'http' => null,
        'public_site' => null,
    ],
    'access' => [
        'membership' => null,
        'platform' => null,
    ],
    'resources' => [],
    'sharing' => [
        'media' => 'none',
        'metafields' => 'none',
        'templates' => 'none',
    ],
    'migrations' => ['enabled' => false],
];
```

`application` derives tenant ownership for installed, integrated stateful
families. Explicit `resources` entries override one family to `tenant` or
`platform`, subject to compatibility validation. There is no arbitrary mode
for individual children: translations, pivots, receipts, revisions, and files
inherit the root. Code-backed catalogs and global identities retain their fixed
classification. Unknown family names and unsupported sharing values are errors.

Sharing accepts only `none` or `copy` for the three registered families. Typed
package-owned grant records determine which tenant may inspect/import which
platform resource. There is no shared mutable resource mode in this release.

The Suite catalog places `tenancy` after Support/Data and before its consumers.
Add it to dependencies as each package integrates. Preserve the current
`full-suite` feature behavior, while transparently reporting the new inert
provider as dependency-enabled. Legacy maps may omit `tenancy`: dependency
closure includes the provider but does not enable the feature. Explicitly
excluding a required library continues to produce the existing conflict error.
Do not claim that the effective provider list stays at 20 after adding a 21st
library; diagnostics distinguish provider registration from tenancy activation.

The provider is always registered for an integrated package, including standalone
Composer installs. Register fallbacks with `scopedIf`/`bindIf`; validate configured
adapter classes after registration without constructing request-scoped services
in singleton registries. A host binding wins over a default; conflicting explicit
class configuration and host binding must be reported, not silently overwritten.

Default disabled installations require no Tenancy table. Before first use of a
participating connection, perform one lazy compatibility probe for the adoption
marker. If present, verify resource state even when configuration is disabled.
Database errors are errors, not equivalent to "not adopted". Cache the probe
within the boot/worker generation; activation requires maintenance and worker
restart. Measure this bounded bootstrap cost separately from query budgets;
do not promise zero additional schema queries in disabled mode.

### Reviewed F6 readiness split

`TenantOwnershipConfiguration::requireCompatible(string $family, string $dependency): void`
is the package integration entry point for code-owned dependency rules. Structural
configuration, unknown families and contradictory parent/dependency modes fail
after provider registration. `TenantOwnershipConfiguration::assertReady(): void`
is the metadata-only admission guard for actual tenant execution and activation;
`incompatibleFamilies(): array` lists loaded runtime packages lacking integration,
and `inspect(): array` reports configuration without probing schema.

Incomplete loaded stateful integrations remain bootable in Unresolved context so
Doctor and narrowly admitted platform bootstrap can run. They are readiness errors
with enabled Tenancy and deny TenantRunner, TenantMaintenanceRunner, TenantBoundary
(including supported host contexts), and adoption activation. This does not grant
legacy Settings tenant access. No configuration bypass list is supported.

Known stateful runtime providers require family and adoption registrations. CSV
requires an adoption-only registration and may expose zero resources. Translatable
is owner-driven without its own schema/adopter; owning domains supply declarations
and guards. Support/Data/Filterable/Primitives remain neutral. Composer presence
without a loaded runtime provider is not activation. Registration compatibility
and actual prepared/active installation markers remain separate checks.

## 3. Shared PHP interfaces

Paths below are **planned new files**, not existing APIs. Each named class is
implemented by the foundation plan before a consuming plan uses it. Include
normal strict types, imports, PHPDoc, and per-file class separation in actual code.

### Context values

Under `packages/nvl/tenancy/src/`:

```php
// Enums/TenantContextMode.php
enum TenantContextMode: string
{
    case Disabled = 'disabled';
    case Unresolved = 'unresolved';
    case Tenant = 'tenant';
    case Platform = 'platform';
}

// ValueObjects/TenantId.php
final readonly class TenantId
{
    public function __construct(public string $value);
}

// ValueObjects/TenantContextSnapshot.php
final readonly class TenantContextSnapshot
{
    public function __construct(
        public TenantContextMode $mode,
        public ?TenantId $tenantId = null,
    );
}

// Contracts/TenantContext.php
interface TenantContext
{
    public function snapshot(): TenantContextSnapshot;
    public function requireTenant(): TenantId;
}
```

`TenantId` validates/canonicalizes a UUID. Snapshot construction rejects a tenant
ID with a non-Tenant mode or Tenant mode without an ID. `requireTenant()` throws
`TenantContextMissing` unless mode is Tenant. The context interface has no setter.

### Access and execution

```php
// Contracts/TenantMembershipAccess.php
interface TenantMembershipAccess
{
    public function assertMember(Authenticatable $actor, TenantId $tenant): void;
}

// ValueObjects/PlatformOperation.php
final readonly class PlatformOperation
{
    public function __construct(
        public string $purpose,
        public string $actorType,
        public string $actorId,
    );
}

// Contracts/PlatformAccess.php
interface PlatformAccess
{
    public function authorize(PlatformOperation $operation): void;
}

// Services/TenantRunner.php
final class TenantRunner
{
    /** @template T @param Closure(): T $operation @return T */
    public function run(TenantId $tenant, Closure $operation): mixed;
    /** @template T @param Closure(): T $callback @return T */
    public function platform(PlatformOperation $operation, Closure $callback): mixed;
}

// Services/TenantMaintenanceRunner.php
final class TenantMaintenanceRunner
{
    /** @template T @param Closure(): T $callback @return T */
    public function run(TenantId $tenant, PlatformOperation $operation, Closure $callback): mixed;
}
```

`run` is a trusted application execution boundary, not an authentication API.
It checks active tenant status, installs context, executes, and restores context
in `finally`. HTTP enters only after membership/public admission. It rejects
switching tenant or mode during an open participating database transaction;
reentering the identical tenant is allowed. Platform execution calls the
configured authorizer and writes a durable operation audit before privileged
work. Unconfigured adapters deny entry; `actorType=system` is not a bypass.

Central Auth login/recovery/self-service uses narrow Auth-owned operations with
declared global identity access. It does not call `platform()` or receive
arbitrary access to other packages. Those routes never grant tenant data access.

`TenantMaintenanceRunner` is the explicit recovery/cleanup boundary for existing
Active, Suspended or Deleted directory entries. It requires actual application
maintenance mode, PlatformAccess authorization and durable audit before entry.
An internal scoped lease admits only that tenant for the callback, retains all
resource predicates and transaction restrictions, and expires in finally.
It never exposes an HTTP flag, broad platform query, normal membership admission,
or serialized privilege. Queue dispatch from maintenance callbacks is rejected;
maintenance cleanup uses synchronous package APIs with resumable checkpoints.
No generic job envelope carries the lease. Unknown tenant IDs still fail. Normal
HTTP/jobs and online webhooks cannot use this maintenance-only path.

### Resource isolation

```php
// Services/TenantBoundary.php
final class TenantBoundary
{
    public function query(Builder $query, string $resource): Builder;
    public function assertRecord(Model $record, string $resource): void;
    /** @return array{tenant_id?: string|null, ownership_key?: string} */
    public function attributes(string $resource): array;
    public function key(string $resource, string $identity): string;
}
```

Resource strings are package-owned, registered keys such as `media.assets`,
`auth.roles`, and `taxonomy.terms`; they are not arbitrary model names supplied
by requests. The registry maps a resource to its family, concrete model,
tenant column, effective connection, parent policy, and optional platform catalog
support. Parent policy and global catalog classification are code-owned.

`query` applies the required predicate and validates the adopted schema/context.
Disabled untouched resources preserve current queries. Tenant mode scopes tenant
rows; Platform mode scopes platform rows, not all tenants. Privileged maintenance
iterates tenants with `run`. Catalog import uses a dedicated authorized source
reader; a grant never changes the general query scope.

`assertRecord` checks persisted ownership, context, model registration, and
effective connection, not just dirty in-memory attributes. Package locators still
own canonical reload and locking. Already-loaded relationships may only be
returned after ownership validation. `attributes` supplies server-generated
ownership columns for a new root; package writers derive child values from the
canonical parent and reject disagreement. In tenant-only schemas these values
cannot be null. `key` prefixes/hashes a canonical tuple including connection,
resource, mode, tenant, and caller identity; it returns the legacy identity for
disabled unadopted resources.

### Public site context

```php
// ValueObjects/TenantSiteContext.php
final readonly class TenantSiteContext
{
    public function __construct(
        public TenantId $tenantId,
        public string $site,
        public string $canonicalOrigin,
    );
}

// Contracts/TenantSiteResolver.php
interface TenantSiteResolver
{
    public function resolve(Request $request): TenantSiteContext;
}

// Contracts/TenantHttpResolver.php
interface TenantHttpResolver
{
    public function resolve(Request $request): TenantId;
}
```

The origin is a verified serving/canonical origin from the directory/site
adapter. It is not the browser's `Origin` or `Referer`. Locale stays a separate
validated package concern. Pages, SEO, Forms, and asset routes consume the same
tenant/site mapping, with package-specific publication and access checks.

## 4. Directory, schema and lifecycle

Planned core tables: `nvl_tenancy_tenants`, `nvl_tenancy_installation_state`,
`nvl_tenancy_operations`, `nvl_tenancy_adoption_runs`, and
`nvl_tenancy_adoption_mappings`. Operations stores bounded privileged-operation
audits without forcing an Activity dependency; the last two persist reviewed
mapping inputs and resumable progress. Register a typed directory
adapter for host UUID directories; the default directory owns tenant status.

The directory contract is `TenantDirectory::find(TenantId): TenantDescriptor`
under `Contracts/`; `TenantDescriptor` is an immutable DTO/value under
`ValueObjects/` containing `TenantId $id` and `TenantStatus $status`.
`Enums/TenantStatus` has `Active`, `Suspended`, and `Deleted` cases. An unknown
ID throws `TenantNotFound`. Directory writes use tenant-owned Actions and can
only occur through authorized provisioning/platform operations. A host adapter
does not need to implement package storage writes; unsupported writes fail.

Installation state is keyed by resource key with schema version, state
`prepared|active`, and a hash of the structural ownership configuration. A
prepared resource denies ordinary access during the maintenance window. Only
the adoption coordinator may authorize the reviewed backfill. It then validates
constraints, marks active, and requires process restart. Provider/feature omission
cannot bypass an existing marker. State changes are explicit, serialized, and
resumable; no request discovers rows and assigns them to a default tenant.

Each package registers an adoption adapter with `prepare`, `backfill`, `verify`,
and `activate` responsibilities. The coordinator owns ordering/checkpoints;
adapters own package writes. A mapping groups existing resource IDs by tenant
and carries an input fingerprint so resumption cannot silently use changed data.
Effective connections, including default aliases, are normalized before checking
transaction compatibility. Do not compare raw `null` and connection names.
Identical DSNs under different connection names do not prove shared transaction
identity; participating writes must use the same canonical Laravel connection
instance. Explicit platform family overrides use a verified platform partition
schema; they cannot retain a non-null tenant-only constraint. Structural mode
changes require a new reviewed adoption and are rejected as runtime toggles.

HTTP, queue, scheduler, and CLI integration capture immutable context references.
Queue metadata is established before command deserialization; package jobs use
scalar IDs and reload afterward. Context cleanup covers synchronous execution,
chain/batch callbacks, failures, retries, and after-commit callbacks. Do not retain
tenant state in singleton registries or swap global Config for per-tenant values.

### Registration, adoption, and lifecycle APIs

These definitions also live under `packages/nvl/tenancy/src/`; registries retain
immutable definitions/class names only. Code snippets specify the public shape,
not complete PHP implementation bodies.

```php
// Enums/TenantResourceKind.php
enum TenantResourceKind: string
{
    case Root = 'root';
    case Inherited = 'inherited';
    case Platform = 'platform';
}

// ValueObjects/TenantResourceDefinition.php
final readonly class TenantResourceDefinition
{
    /** @param class-string<Model> $model */
    public function __construct(
        public string $key,
        public string $family,
        public string $model,
        public TenantResourceKind $kind = TenantResourceKind::Root,
        public ?string $parentResource = null,
        public ?string $parentRelation = null,
        public bool $allowsPlatformCatalog = false,
        public bool $allowsPlatformRows = false,
    );
}

// Services/TenantResourceRegistry.php
final class TenantResourceRegistry
{
    public function register(TenantResourceDefinition $resource): void;
    public function get(string $key): TenantResourceDefinition;
    public function forModel(Model $model): TenantResourceDefinition;
    /** @return array<string, TenantResourceDefinition> */
    public function all(): array;
}

// ValueObjects/TenantAdoptionPlan.php
final readonly class TenantAdoptionPlan
{
    public function __construct(
        public string $id,
        public string $connection,
        public string $mappingHash,
        public string $configurationHash,
    );
}

// ValueObjects/TenantBackfillResult.php
final readonly class TenantBackfillResult
{
    public function __construct(public ?string $nextCursor, public int $processed);
}

// ValueObjects/TenantVerification.php
final readonly class TenantVerification
{
    /** @param list<string> $errors */
    public function __construct(public array $errors);
    public function passed(): bool;
}

// Contracts/TenantAdoptionAdapter.php
interface TenantAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array;
    public function prepare(TenantAdoptionPlan $plan): void;
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult;
    public function verify(TenantAdoptionPlan $plan): TenantVerification;
    public function activate(TenantAdoptionPlan $plan): void;
}

// Contracts/TenantAdoptionMetadataValidator.php (optional, additive F5 ruling)
interface TenantAdoptionMetadataValidator
{
    public function validateAssignment(TenantAssignment $assignment): void;
}

// Services/TenantAdoptionRegistry.php
final class TenantAdoptionRegistry
{
    /** @param class-string<TenantAdoptionAdapter> $adapter */
    public function register(string $package, string $adapter): void;
}

// Services/TenantAdoptionMappings.php
final class TenantAdoptionMappings
{
    public function tenantFor(TenantAdoptionPlan $plan, string $resource, string $recordId): TenantId;
}

// ValueObjects/TenantAssignment.php
final readonly class TenantAssignment
{
    /** @param array<string, mixed> $metadata Bounded, package-validated JSON values. */
    public function __construct(
        public string $resource,
        public string $recordId,
        public TenantId $tenantId,
        public array $metadata = [],
    );
}

// Services/TenantAdoptionCoordinator.php
final class TenantAdoptionCoordinator
{
    /** @param list<string> $packages @param iterable<TenantAssignment> $mappings */
    public function prepare(array $packages, iterable $mappings, PlatformOperation $operation): TenantAdoptionPlan;
    /** True means every adapter has finished its backfill. */
    public function backfill(TenantAdoptionPlan $plan, int $limit, PlatformOperation $operation): bool;
    public function verify(TenantAdoptionPlan $plan): TenantVerification;
    public function activate(TenantAdoptionPlan $plan, PlatformOperation $operation): void;
    public function resume(string $runId): TenantAdoptionPlan;
}

// Contracts/TenantContextParticipant.php
interface TenantContextParticipant
{
    /** @return Closure(): void */
    public function enter(TenantContextSnapshot $next): Closure;
}

// Services/TenantContextParticipants.php
final class TenantContextParticipants
{
    /** @param class-string<TenantContextParticipant> $participant */
    public function register(string $participant): void;
}

// ValueObjects/TenantJobEnvelope.php
final readonly class TenantJobEnvelope
{
    public function __construct(public TenantContextSnapshot $context, public int $version = 1);
    public static function capture(TenantContext $context): self;
}

// Contracts/TenantQueuedJob.php — F7 approved additive producer carrier
interface TenantQueuedJob
{
    public function tenantJobEnvelope(): TenantJobEnvelope;
}

// Services/TenantQueueContext.php
final class TenantQueueContext
{
    /** F7 additive producer boundary; native database batches require one captured owner. */
    public function captureBatch(\Illuminate\Bus\PendingBatch $batch): \Illuminate\Bus\PendingBatch;
    /** @template T @param Closure(): T $operation @return T */
    public function run(TenantJobEnvelope $envelope, Closure $operation): mixed;
}

// Services/TenantGlobalJobRegistry.php
final class TenantGlobalJobRegistry
{
    /** @param class-string $jobClass */
    public function register(string $jobClass): void;
}
```

F7 requires enabled tenant commands to implement `TenantQueuedJob` and capture
`TenantJobEnvelope::capture(TenantContext)` before native scheduling or producer
lock acquisition. Payload-time ambient fallback is forbidden: native afterResponse
and sync-afterCommit can serialize in a later scope. This additive explicit producer
contract preserves the host dispatcher and frozen envelope/run APIs. Disabled legacy
and explicitly registered scalar global-identity jobs use separate admission paths.
The handler validates scalar payload metadata, the actual native serialized root,
carried envelope equality, and supported model identifiers before native execution
or failure deserialization. Encrypted commands retain native encryption semantics.
Host models require canonical registered ownership, no serialized relations, and no
custom collections; package jobs carry scalar IDs. Chains require one captured
tenant. Custom handlers require explicit adapters. Exact native queued mail,
notification and listener wrappers extract capture from their mailable, notification
or event-argument `TenantQueuedJob` carriers; mismatched/uncaptured wrappers fail
before user deserialization. `captureBatch` binds all jobs/callbacks to one tenant;
the compatible native database repository validates inert persisted options before
callback deserialization, including native signed closure payloads. Mixed batches,
uncaptured batch IDs, incompatible repositories, and later unrelated-scope batch
publication fail closed. PostgreSQL base64 options retain native representation. No platform or maintenance grant is serializable.

Root/inherited columns use the fixed `tenant_id` name; supported mixed platform
catalog roots additionally use `ownership_key`. Existing package table and
effective-connection configuration remain authoritative. A resource cannot be
registered twice under conflicting classes/policies; unknown model/resource
lookups throw. A Platform kind does not gain arbitrary tenant read permission;
its owning package exposes specific read-only vocabulary/catalog operations.

`allowsPlatformRows` also declares mixed operational ownership for Auth tokens,
invitations and audit rows; it grants no catalog/sharing rights. A catalog flag
implies mixed ownership as well. Both mixed variants use `ownership_key`.
For an inherited polymorphic relation, `parentResource=null` plus an explicit
`parentRelation` resolves the canonical parent through the owning package's
allowlisted owner registry, then `forModel`; it never means global ownership.
All possible parent types must be registered. A concrete parentResource cannot
disagree with the resolved canonical model.

Mappings are streamed from a reviewed input into the run's indexed mapping table
before backfill. Input hashes and resource-record keys are immutable after
preparation; `tenantFor` throws on any unmapped row. Package adapters may derive
child ownership from canonical parents instead of requiring redundant mappings.
Verify reports contain bounded codes/record identifiers, not row payloads.

`TenantAdoptionMappings` additionally exposes
`assignments(TenantAdoptionPlan $plan, string $resource, ?string $afterRecordId, int $limit): array`
returning `list<TenantAssignment>` ordered by record ID, and
`metadataFor(TenantAdoptionPlan $plan, string $resource, string $recordId): array`
returning the stored package-validated metadata. Core storage includes a JSON
metadata column; canonical JSON bytes participate in mappingHash. Packages
validate their exact metadata schema before preparation, reject unknown fields,
bound size and exclude credentials or record content. A package may declare
planned destination IDs for memberships/role clones and record the source ID
and explicit disposition in metadata. Existing roots still map to one primary
tenant; additional copies use reviewed owner mappings and a package-owned split
ledger keyed by source/target tenant. The coordinator never invents copies or
principal memberships and never infers an owner flag from an editable role name.

The coordinator derives and validates one effective connection and the package
dependency closure. Every mutation authorizes its supplied PlatformOperation,
requires active maintenance mode, and verifies the persisted run fingerprint.
Preparation records resource markers before invoking adapter DDL; interruption
leaves the affected graph blocked and resumable. Activation requires all
backfills and verification to pass; it activates adapters before marking the
whole run active and invalidates this process's probe cache. Other processes
must be drained/restarted. A DTO containing a run ID is not authorization.
Empty mappings are allowed for verified empty roots; any existing unmapped root
selected for tenant ownership fails verification. Code-owned platform vocabulary
and explicitly classified historical global operational records do not receive
fake tenant assignments: the package adapter verifies their platform disposition.
Ambiguous Auth invitations are revoked and legacy live tokens revoked/reissued
before cutover; immutable global identity audits/challenges remain explicitly
platform-classified unless an evidence-backed tenant mapping exists.
A changed input creates a reviewed replacement run, never
an in-place mutation of a prepared mapping.

Integration tests use a dedicated Testbench case with `DatabaseMigrations`
instead of an outer `RefreshDatabase` transaction. Register the package's real
adoption adapter, load core opt-in migrations explicitly, bind a test directory
with tenants A/B and an explicitly allowing test PlatformAccess, and bind an
in-memory implementation of Laravel's MaintenanceMode contract whose active()
returns true. Then call prepare/backfill/verify/activate through this coordinator.
Do not insert active markers by hand or bypass TenantRunner's transaction check.
These fake authorization/directory/maintenance adapters belong only to test
fixtures; the production defaults deny authorization and inspect actual state.

Each context participant saves and installs state, then returns its restoration
closure. The runner unwinds entered participants in reverse order even if a
later participant fails. Failed `enter` implementations restore their own partial
state before rethrowing. Auth uses this to reset Spatie team state/relations;
Settings and locale integrations must not mutate process-wide configuration.

Envelopes serialize scalar snapshot fields using an explicit versioned payload
codec. The first release permits Tenant and Disabled envelopes only; Unresolved
fails at dispatch, and platform-wide queued work must be a registered maintenance
command that enumerates tenants into separate Tenant envelopes. Central Auth mail
is a declared global identity workflow rather than a platform tenant-data job.
This prevents serializing a stale platform privilege into a generic job envelope.
Disabled envelopes are accepted only while the current feature is disabled and
the participating resources are unadopted. Old disabled payloads must be drained
or explicitly migrated before activation; they never downgrade an enabled worker.
Package jobs carry the envelope plus scalar IDs; the queue adapter restores it
before any model/closure restoration and rejects envelope/persisted-tenant mismatch.

The Laravel adapter wraps both CallQueuedHandler::call and ::failed before
delegating to Laravel, using scalar metadata inside payload `data`. This covers
failure paths invoked before handle(), including exhausted attempts. Queue
events alone are insufficient. Preserve a pre-existing compatible host binding;
an incompatible handler requires an explicit adapter and fails diagnostics.
Global identity jobs require a dedicated registered job class with scalar IDs;
never allowlist generic SendQueuedMailable/SendQueuedNotifications wrappers that
could carry tenant work. Their decoder executes under Unresolved global-identity
context, without platform data privileges, and restores the caller afterward.

## 5. Ownership, pivots, and catalog copies

Direct `tenant_id` means ownership. A grant pivot means availability of a
platform catalog item for inspection/import; it never means shared ownership.

| Owner package | Planned table | Meaning |
|---|---|---|
| Auth | `nvl_auth_tenant_memberships` | Principal membership, status, revision, explicit `is_owner`; unique tenant/subject type/subject ID |
| Media | `media_tenant_grants` | Allow tenant to inspect/import a platform asset |
| Metafields | `metafield_definition_tenant_grants` | Allow tenant to inspect/import a platform definition |
| Templates | `template_tenant_grants` | Allow tenant to inspect/import a published template version |

Grant tables use UUID `id`, `tenant_id`, a concrete resource/version foreign key,
revision, enabled/revoked state, and timestamps; require unique tenant/resource
identity and a tenant-leading lookup index. Keep grants out of the client
mutation field bag. Tenant directory foreign keys are conditional on the chosen
directory adapter's schema contract; resource foreign keys remain concrete.
Existing `media_associations`, `termables`, and definition-owner-type assignments
retain their separate domain meanings and gain inherited ownership.

The first release has **copy-on-import** semantics for shared Media, Metafields,
and Templates. Lock and validate the grant and exact source revision; create
independent tenant-owned rows and binary objects through owning-package APIs.
Store immutable provenance IDs/revision hashes without a deletion cascade back
to the source. Template imports copy the full version/Content/asset graph.
Metafield reference defaults must be explicitly mapped to accessible tenant data
or rejected; never copy a platform record ID into a tenant reference field.

Revocation stops subsequent inspection/import and pending import commit. It does
not delete or retroactively change an already imported tenant-owned copy. A
retry checks the grant again; a staged file is cleaned up if the import aborts.
Taxonomy vocabularies remain global code declarations; their terms are local to
one tenant. No live term sharing, shared writable definitions, or universal
polymorphic `tenant_resources` table is included.

## 6. Auth and early cross-package rules

- Auth membership uses the existing `SubjectReference` type/identifier model,
  not a hardcoded UUID foreign key to package User. UUID restrictions in existing
  RBAC storage are validated separately for custom principals.
- `is_owner` is membership state, not an editable role name. Auth-owned per-tenant
  lock rows serialize owner changes. Global principal removal/deactivation
  rejects unresolved last-owner responsibilities; emergency suspension is an
  explicit platform procedure.
- No null-team roles participate in tenant RBAC. Platform permissions remain a
  vocabulary, platform administration uses explicit capabilities, and role
  templates instantiate tenant-owned roles. Legacy role mappings require review.
- Central clients and browser sessions remain global correlations. Tenant
  selection/intent is separate, validated, and one-use where appropriate.
- Existing-account invitation acceptance requires authenticated recipient proof;
  registration must not authenticate an existing identity solely by email.
- Partition Auth audit facts and the minimum Activity recording/read path before
  enabling packages that emit them. Until integrated, an Activity bridge must
  deny tenant use instead of writing tenant payload into the platform timeline.
- Make Settings' current bootstrap overrides platform-only before the first
  tenant-enabled boot. Full tenant overlays can arrive later.
- Prove CSV queued closure/batch behavior and Filterable predicate preservation
  before enabling those features in tenant workflows.

## 7. Error contracts and proof cases

Planned exceptions under `Nvl\Tenancy\Exceptions`: `TenantContextMissing`,
`TenantNotFound`, `TenantInactive`, `TenantBoundaryViolation`,
`TenantConfigurationInvalid`, and `TenantSchemaNotReady`. They use stable
machine-readable codes and bounded transport-neutral messages. HTTP adapters
map foreign/unknown resource IDs to the same 404; domain Actions preserve typed
errors; configuration/schema mismatch is an operational error, not empty data.

All plans test two tenants with identical business keys, differing memberships,
global catalogs, forged model instances, retained loaded relations, empty/missing
context, sequential worker transitions, and disabled-mode compatibility. Testing
must include real serialization, not only direct `handle()` calls or Queue fakes.
Schema tests run on SQLite and PostgreSQL plus the supported MySQL/MariaDB release
matrix; concurrency proof uses separate processes/connections.

Execution is phased. A package is marked tenant-ready only after its public
Actions, traits, jobs, raw queries, schema, configuration, diagnostics, and
cross-package acceptance tests satisfy these contracts.
