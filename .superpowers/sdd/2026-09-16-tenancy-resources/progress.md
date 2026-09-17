# SDD ledger — plan: docs/superpowers/plans/2026-09-16-tenancy-resources.md

Preflight only. No resource task implemented or dispatched. Program order is R1–R5 after Foundation/Translatable/Auth, then P1, then R6–R9. User's execution instruction supersedes historical planning-only language. Append this plan's complete schema, registration and fixture contracts to every extracted task brief before dispatch; the raw task extracts alone are insufficient.

## Preflight: task internal agreement

| Task | Check | Finding / resolution |
|---|---|---|
| R1 | Complete Media graph/adopter/three-phase schema vs models | Includes future grant schema and registry keys; minimal grant model declaration must exist with registration. Persisted original storage_path required before root path configuration can change. Full ingestion behavior waits R2/R3. |
| R2 | Action inventory, owner resolver, global scope, public delivery | Scope and explicit boundaries must compose without duplicate/recursive admission; canonical builder storage restriction is foundation-owned. Asset public site resolver comes before binding/cache responses. |
| R3 | Binary paths/locks/idempotency and transaction callbacks | Legacy path identity remains stable. Callback-lifetime stress tests must respect foundation no-transaction-leak rule; see prerequisite ruling. |
| R4 | Grants, staged DTOs, public port and standalone Action | Same transaction required for persist; source reads narrowly projected, no tenant accessors on platform models. Import/revoke final grant lock defines outcome; exact staged tuple identity prevents path substitution. |
| R5 | Actual worker, dedup/import races, commands | Real queue/service proof mandatory. Directory find-only contract does not provide all-tenants enumeration; use explicit reviewed worklists or separately authorized narrow directory enumerator. |
| R6 | Definition/value graph, owners/reference registries and adapter | Definitions may be mixed but values strictly inherit owner; extra tenant/id unique supports value FK. Minimal catalog grant model needed before complete graph registration, even though Actions wait R7. |
| R7 | Reference remapping, catalog snapshot and import transaction | All source snapshot writes need root revision lock, including translated copy/default changes; public translation services must participate. Concrete snapshot wrapper is required though listed later in prose. |
| R8 | Configured tree tables, parent/attachment constraints and scoped locale | Complete owner/term checks for subqueries and loaded relations, same-tenant hierarchy lock. SlugGenerator scoped change explicitly required. |
| R9 | Upgrade/restore/repair composition and copied consumers | Repairing mappings then same-run resume contradicts immutable core input. Only source/schema repair consistent with old input resumes; changed interrupted mappings require backup restore/new reviewed preparation. |

## Preflight: shared interfaces/files

| Tasks | Producer / consumer | Finding / ordering |
|---|---|---|
| R1/R2 | Models/provider/schema vs guarded mutations/reads | Schema fixture may arrange roots explicitly; public upload helper not exercised until actual writer supports ownership. Inert unsupported paths stay closed. |
| R1/R3 | Persisted storage_path/multipart/slot tables | R1 inventories actual legacy bytes/path; R3 normal writers produce immutable tenant prefix and preserve recorded original. |
| R1/R4 | Grant/import schema/resources/model | Move minimal model into R1 if required for complete descriptors; R4 consumes it and adds catalog behavior. |
| R1/R5 | Complete adapter/setup and real consumer | Copied app uses actual core/package/owner adapters, no automatic worker boot migrations. |
| R1/R9 | Split map ledger and recovery/checksums | Core mapping immutable, concrete copy ledger mutable/idempotent only under owning adapter. |
| R2/R3 | Upload/replace/relocate Actions and canonical owners | Ownership checks before deriving any source/target path or dedup lookup. |
| R2/R4 | Normal source denial vs authorized catalog snapshot | No Eloquent source model escapes snapshot reader; fixed source ID/partition/columns only. |
| R2/R5 | Job canonical reload/availability/public route guards | Worker establishes context before model restore; public flag not cross-tenant permission. |
| R2/R9 | Sanctioned traits/eager owner composition | Already-loaded model relations validated; broad consumer cannot retain A into B. |
| R3/R4 | File rollback/idempotency/staging port | Registered staged tuple exact to tenant/operation/path; replay discards redundant bytes, rollback tied to root transaction. |
| R3/R5 | Dedup/slot locks and actual service race | Process barrier controls winner and tests same-name A/B isolation; no fake cache substitute. |
| R3/R9 | Changed root config and restore rehearsal | Source path from persisted identity, never reconstructed using new configuration. |
| R4/R5 | Grant lock/source revision/import race | Force both final-lock outcomes, no promise to undo already committed copies. |
| R4/R7 | Concrete grants semantic consistency | Same permission-for-copy semantics but independent package models/readers/importers; no generic tenant_resources table. |
| R4/R9 | Provenance/source deletion/composition | No source FK cascade into committed copies; grants may cascade only. |
| R5/R9 | Real workers/database/S3 and release claims | Preserve proof metadata and fail expected-service skips; package-only SQLite suite not sufficient. |
| R6/R7 | Definition graph/schema/revisions and catalog import | Imported rows ordinary tenant definitions; handle conflict explicit, all defaults remapped/validated, full locale map preserved. |
| R6/R8 | Owner/reference classification and taxonomy | Class alias alone never authority; registered canonical resource must resolve same tenant. |
| R6/R9 | Required sections/reference/default/eager composition | Foreign required assignments cannot invalidate local owner; all list members reject atomically. |
| R7/R9 | Imported defaults/provenance/revocation and composed owner | Committed local values survive grant revoke/source deletion. |
| R8/R9 | Tree splits, configured tables/aliases/locale | Ancestor/descendant copies preserve canonical slugs/positions; known owner map required, orphan pruner never hides incompatible rows. |

## Rulings

Ruling: Create minimal concrete catalog grant model declarations with the first complete schema/resource task (Media R1, Metafields R6) if their registry/adopter inventories require them; later grant tasks extend those same classes — immutable resource registration cannot reference missing model classes and full graph adoption must include grants — if wrong, task file boundaries shift, but no second resource/model is introduced.

Ruling: Separate source/schema repair from immutable reviewed input in R9; source changes consistent with the same map can resume, while changed interrupted mappings require the rehearsed pre-adoption restore and a new reviewed prepare — foundation frozen contracts prohibit in-place mapping/hash mutation — if wrong, additional explicit recovery tooling is needed. F5 allows fully active graph re-adoption under fresh authorized maintenance, but never supersedes another prepared run.

Ruling: Test callback capture after simulated context restoration using a narrowly controlled test-only context fixture where necessary, and separately prove native TenantRunner rejects transactions that outlive its boundary — requested Media callback stress behavior must not weaken the reviewed transaction lifetime invariant — if wrong, fixture technique changes; no production context setter/bypass is added.

Cross-plan prerequisites: Translatable ownershipResource API and full guarded read/write paths must exist before R1. MediaCatalogImport is a public package-owned staged transaction port consumed later by Templates/Content. Auth not required by standalone package fixtures; final suite journey additionally tests memberships. Directory enumeration for all-tenants commands remains explicitly authorized and bounded; frozen TenantDirectory::find is not silently extended with guessed scans.

During F6 implementation, controller appended exact global/shared interface/test setup context to remaining briefs 1,2,3,4,5,6,7,8,9. These are prepared requirements only, not dispatched or completed. Before EACH dispatch add current reviewed dependency/API handoff and task-specific preflight rulings; read ledger. Full source plan need not be reread by worker.

## R1 — Media ownership schema and registration

- Added opt-in Media ownership declarations for assets, associations, variations, translations, multipart sessions, owner-slot operations, and concrete catalog grants. Disabled standalone Media remains inert and does not require Auth; enabled deployments with no canonical owner classification fail closed.
- Added three-phase expansion/grant/constrain schema and a package adoption adapter. The persisted root path is captured before cutover, child ownership is derived from the canonical asset, and ownership columns are guarded against mutation.
- Added a real fixture-owner adoption adapter and coordinator-driven Media tenancy scenario. The focused composite-FK mismatch proof passes on SQLite.
- Evidence: `MediaTenancySchemaTest.php`, `ProviderConfigurationTest.php`, and `MediaModelTest.php` — 31 tests, 94 assertions. Focused production PHPStan is green except the package's pre-existing standalone `InteractsWithMedia` unused-trait diagnostic when the test stubs are excluded.
- Concern carried forward: reviewed multi-tenant legacy asset split/copy execution is intentionally still fail-closed and must be completed before the R1-R5 final adoption matrix; it is not silently inferred.

## R2 — Media tenant entry boundaries

- Added composable global ownership scopes to Media roots and concrete children, plus explicit canonical-owner resolution for uploads, associations, owner-slot reads/writes, API associable resolution, and trait loaded-relation reads. Disabled mode preserves the legacy owner surface.
- Public and private asset routes now establish the trusted public tenant before Laravel route-model binding. Cross-tenant public reuse of an already-passed model is rejected explicitly, and null/privileged library reads remain tenant-scoped.
- Bulk delete/tag/move now validate the entire identifier set before mutation, preventing partial success when any identifier is unavailable to the active tenant.
- Evidence: new boundary proof plus Actions/API/InteractsWithMedia focused matrix — 126 tests, 347 assertions.

## R3 — Media storage and operation identity

- Tenant writes now persist immutable object identities under `root/tenants/<uuid>/...`; platform writes use `root/platform/...`. Existing adopted records always resolve their persisted path, while disabled untouched rows retain legacy reconstruction.
- Deduplication, mutation, multipart, and owner-slot operation identities include the foundation tenant key. Multipart roots and completed assets carry derived tenant ownership and persisted object paths.
- The exact dedup isolation proof passes: repeated bytes deduplicate inside A, while B receives a distinct row and physical path. Existing multipart/slot/transaction/path/storage-health coverage remains green.
- Evidence: 143 focused existing/new tests, 574 assertions, plus the dedicated storage proof (1 test, 9 assertions).

## R4 — Media catalog grants and copy imports

- Added concrete platform grant/revoke Actions, a scalar-only authorized reader, immutable snapshot/staged DTOs, and the public `MediaCatalogImport` transaction port.
- Stage copies and verifies exact bytes into the active tenant partition. Persist requires the caller's canonical open transaction, locks grant then source, rechecks recipient/revisions/digest/status, enforces tenant-local idempotency provenance, and registers rollback cleanup.
- Revocation blocks every new/replayed import authorization while committed tenant copies remain independent. Caller-substituted tuples and out-of-transaction persistence fail before writes.
- Evidence: catalog revocation/import and graph rollback tests — 2 tests, 15 assertions.

## R5 — Media queue and operational boundaries

- All three Media jobs now carry the foundation scalar tenant envelope without changing their existing positional arguments. Producer dispatchers capture the envelope before after-commit callbacks, child jobs preserve it, and tenant identity participates in uniqueness keys only when tenancy is enabled.
- Regeneration rejects unresolved tenant-wide scans. `--tenant` enters one bounded runner; `--all-tenants` obtains an explicitly authorized active-tenant worklist and runs each tenant independently. Disabled installations retain the legacy command behavior.
- Added Media's own sealed consumer fixture with explicit schema/adoption setup and a real database queue worker. It proves same-worker A/B image variation isolation, stale work rejection, failure retry, corrupted-envelope rejection before lookup, tenant-prefixed storage, and clean worker scope. The gate exposed and fixed missing inherited ownership on newly generated variation rows.
- Evidence: focused queue/command/job/action/worker matrix — 41 tests, 124 assertions before the inherited-writer correction; corrected action plus real-worker proof — 16 tests, 75 assertions. Focused Media PHPStan is green.
- Remaining final-matrix work: run the portable package suite and the provisioned PostgreSQL/MySQL/MariaDB, Redis, S3-compatible, race, contract, and sealed-consumer release gates once after the R1-R5 code is committed.

## R1–R5 final Media closure

- Completed reviewed legacy shared-asset adoption. One legacy root shared by owners in A/B now copies and checksum-verifies original and variation bytes into independent tenant paths before atomically retaining the reviewed primary ID, creating deterministic reviewed destination IDs, cloning translations/variations, and rewiring associations. `px_media_tenant_adoption_copies` is the durable resumable old-ID+tenant mapping; the shared source is retained until every destination verifies.
- Added the dedicated separate-process catalog import-versus-revoke race. A test-only port decorator pauses immediately before the real final grant lock; PostgreSQL forces import first and revoke first once each. Committed copies survive later revocation, while revoke-first rejects persistence and removes staged bytes. TenantRunner transaction accounting is unchanged.
- Removed new Media service-locator usage: model scopes are registered with the provider-injected `TenantBoundary`; jobs accept explicit envelopes and default to disabled/unresolved fail-closed envelopes; `MediaPathResolver` requires injected context for tenant paths. Added Tenancy to Media's package-family dependency inventory.
- Final portable suite before the two narrowly scoped closure changes: 1,014 tests, 1,011 passed, 3,354 assertions, 3 expected SQLite service skips. Per direction, it was not run a third time; affected post-closure regression runs were 98/228, real worker 1/16, and final scope/queue/adoption 7/48, all green.
- Native closure evidence: PostgreSQL split+races 2/35; MySQL 8.4 schema+split 2/21; MariaDB 12.3 schema+split 2/21. Earlier final matrix remained green: PostgreSQL schema 1/6, MySQL schema 1/6, MariaDB schema 1/6, PostgreSQL owner-slot two-process race 2/20, real database worker 1/16, PostgreSQL+Redis+S3-compatible production stack 1/14.
- Sealed copied-package consumer installed `nvl/media` 2.0.0 with `nvl/tenancy` 2.0.0, non-symlinked, and proved Auth absent. Package-family validation passed 21 distributions; root and Media Composer manifests passed strict validation; Pint and strict max-level PHPStan over every modified Media source passed with zero errors.
- Contract baseline refresh is intentionally deferred to the parent after concurrent Auth settles. `contracts:check` currently reports the intentional Media migration/public constructor delta plus the active Auth delta; no baseline file from this closure is staged.
- Independent-review correction closed C1-C2, I1-I14, and M1 with no disputed finding. The consolidated portable rerun was 1,032 tests / 1,024 passed / 3,481 assertions with two narrow failures and six native skips; the documentation/worklist failures were corrected and passed in exact follow-up (worklist 1/7), without a third broad run. Native Media schema/adoption/races passed 10/116 on each PostgreSQL, MySQL, and MariaDB; the authorized polymorphic string/UUID Foundation regression passed 1/5 on each engine; PostgreSQL+Redis+MinIO passed 1/20. Package family validated 21 distributions, changed production-source strict PHPStan and Pint passed, and a sealed mirrored consumer proved Media+Tenancy present with Auth absent. Combined Auth+Media contract baseline remains parent-owned.

## R6 — Metafield ownership graph

- Added the complete definition/value ownership graph, mixed platform catalog partition, concrete grant resource, three-phase schema, reviewed split-copy ledger, and package adoption adapter.
- Canonical owner/reference resolution now uses registered Foundation resources and the active tenant boundary. Definitions, assignments, locale rows, values, defaults, serialized reference lists, and loaded reads fail closed across tenant contexts.
- Added Doctor readiness/marker/connection/schema checks and dedicated coordinator-driven fixtures, feature isolation tests, and schema constraints. Disabled installs remain inert.
- Implementation-only cadence: syntax and diff hygiene were performed; every Pest/database/service/analysis/format/contract command remains UNRUN.

## R7 — Metafield definition catalog copies

- Added exact platform grant/revoke Actions, scalar snapshot reader, independent definition importer, immutable provenance, revision/hash/idempotency checks, explicit handle conflicts, total reference remapping, and commit-aware grant audit facts.
- Import locks recipient grant identity and source graph, claims the tenant idempotency key, preserves complete locale/type/default/schema copy, and writes only canonical recipient reference IDs. Committed copies survive revocation and source deletion.
- Added catalog fixtures and tests for independence, replay mismatch, collision, revocation, stale source revision, provenance, locale copy, and source deletion.
- Implementation-only cadence: syntax and diff hygiene were performed; all runtime verification remains UNRUN.

## R8 — Tenant-local taxonomy

- Added configured-table/connection resource registration, expansion/constrain migrations, reviewed graph-copy adoption ledger, canonical term/owner resolution, and composite hierarchy/translation/attachment constraints.
- Guarded create/update/move/merge/delete/rebuild/prune/attachment paths, tenant-leading relation queries and loaded relations, tenant-scoped locks/CLI maintenance, and scoped locale-aware slug generation.
- Added dedicated owner adapter/scenario plus isolation, schema, deletion, pruning, locale contamination, and separate-process hierarchy concurrency proof surfaces. Vocabulary definitions remain global code; terms remain tenant data.
- Implementation-only cadence: PHP syntax and diff hygiene were performed; every test and broad verification command remains UNRUN.

## R9 — Composition, recovery, and independent packages

- Added an Auth-free Orchestra composition consumer with one canonical owner using Media, Metafields, and Taxonomy together; coordinator-driven adoption and denial tests cover identical business keys, eager relations, translations, foreign model/ID rollback, and retained-model tenant switching.
- Added interrupted adoption recovery tests enforcing the immutable mapping ruling: source/schema repair may resume; a changed mapping is rejected and requires restore/new prepare; prepared resources remain closed without fabricated markers.
- Extended independent archive consumers for Media, Metafields, and Taxonomy with inert Tenancy, no Auth/Suite, cached configuration/routes, and no automatically created resource schema. Added package-family/Suite dependency catalogs and CI composition/recovery wiring.
- Updated all three package README/UPGRADING/CHANGELOG files and mirrored Boost skills with copy semantics, canonical owner rules, backup/recovery, bounded cleanup, and no-column-drop rollback claims.
- `tools/package-contracts.json` intentionally remains unchanged for the parent combined baseline refresh. All requested runtime and release verification is UNRUN.
