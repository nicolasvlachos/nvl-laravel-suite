# R6–R9 implementation report

Status: implementation surfaces completed under the requested implementation-first cadence. No runtime test, consumer, matrix, formatter, static-analysis, contract, package-validation, or independent-review command was run.

## R6 checklist — implemented

- [x] Added same-handle/same-owner tenant isolation and forged foreign-owner proofs.
- [x] Added three-phase definition/value graph schema, reviewed split metadata, deterministic copy ledger, preserved history, definition remapping, and activation verification.
- [x] Canonicalized owners on the effective connection; writers derive ownership server-side; definition/value queries remain tenant-local.
- [x] Guarded definition/value mutations and loaded/default/reference reads, including locale rows and restore/clear paths.
- [x] Routed reference existence/loading through stable aliases plus registered tenant resources; unknown/platform/foreign targets fail closed and reference-list validation remains atomic.
- [x] Added dedicated Testbench cases, owner adapter/scenario, schema proof, eager/direct-model/DTO ownership proofs, Doctor checks, resource declarations, Suite dependency metadata, and concrete grant model/schema.

## R7 checklist — implemented

- [x] Added independent-definition acceptance proof with committed copy surviving grant revocation.
- [x] Added grant → source graph → idempotency claim locking/rechecks, active-recipient validation, exact grant/source revision and scalar snapshot hashing.
- [x] Added complete locale/default/type/validation/JSON-schema copy, validated target assignment, explicit handle collision, and immutable provenance.
- [x] Added exact total reference-map validation, recipient canonical target resolution, source-order preservation for lists, and rejection of extra/missing mappings.
- [x] Kept catalog import atomic with no partial definition graph; replay requires the exact request fingerprint; committed copies survive source deletion; stale and revoked requests fail.
- [x] Added catalog import/revoke/source-delete/stale/replay/collision/reference-map proof surfaces and commit-aware grant audit facts.

## R8 checklist — implemented

- [x] Added same-slug tenant isolation and foreign attachment denial proofs.
- [x] Added configured table/connection adoption with reviewed ancestor/descendant graph splits, translation/position preservation, attachment rewrites, copy ledger, and unmapped/collision verification.
- [x] Added composite tenant/taxonomy parent, term, translation, and attachment constraints plus canonical locator reloads.
- [x] Guarded hierarchy snapshots and create/update/move/merge/delete/rebuild/prune paths with same-tenant/vocabulary checks and stable locks.
- [x] Added explicit tenant predicates to raw attachment/merge/prune and owner relation query paths; loaded term relations and owner deletion are guarded.
- [x] Tenant-scoped attachment/process lock identities and explicit CLI tenant selection are implemented; `SlugGenerator` is scoped.
- [x] Added isolation/schema/configured-alias/retained-relation/locale/deletion/prune/concurrency proof surfaces and Doctor/resource declarations.

## R9 checklist — implemented

- [x] Added one Auth-free owner composing `HasMedia`, `InteractsWithMedia`, `HasMetafields`, and `HasTaxonomies`; identical A/B business keys, list/detail/eager/translation locality, foreign Action rollback, and retained eager-model denial are covered.
- [x] Added coordinator-driven upgrade/recovery surfaces. Prepared resources deny ordinary work; unchanged mapping repair resumes; changed mapping fails and requires restore/new prepare. No readiness marker is fabricated.
- [x] Added independent archive consumers for Media, Metafields, and Taxonomy proving inert Tenancy, Auth/Suite absence, cached configuration/routes, and absent opt-in resource schema.
- [x] Documented pre-cutover backup, immutable mapping, no column-drop rollback, independent catalog copies, grant retention, and bounded package cleanup.
- [x] Updated Suite and package-family dependency catalogs, archive/consumer fixtures, mirrored package/root skills, and CI composition/recovery gates.
- [x] Left `tools/package-contracts.json` untouched for the parent combined baseline refresh.

## Deferred verification — UNRUN

- UNRUN — Metafields R6 feature/schema Pest files and existing focused Metafields files.
- UNRUN — Metafields R7 catalog Pest file, revoke/import and source-edit/import separate-process races.
- UNRUN — Taxonomy R8 feature/schema/concurrency Pest files and existing Taxonomy files.
- UNRUN — Root `TenantResourceCompositionTest.php` and `TenantResourceAdoptionTest.php`.
- UNRUN — Full focused package suites and root integration/contract suites.
- UNRUN — PostgreSQL, Redis, S3-compatible worker/race/consumer gates.
- UNRUN — MySQL 8.4 and MariaDB schema/adoption matrices.
- UNRUN — independent Composer archive consumers and production consumers.
- UNRUN — query-count budgets and tenant-leading representative query plans.
- UNRUN — `vendor/bin/pint --dirty --format agent` and all other formatting commands.
- UNRUN — PHPStan/Larastan and package analysis.
- UNRUN — `composer packages:validate`, `composer contracts:check`, consumer audit, archive gates, and package manifest validation.
- UNRUN — `composer skills:sync`; package and suite skill mirrors were edited together directly.
- UNRUN — contract baseline refresh; parent owns the final combined `tools/package-contracts.json` update.

## Commits

- `510ae73` — `feat(metafields): isolate definitions and owner values`
- `5744b76` — `feat(metafields): copy granted platform definitions into tenants`
- `32a851d` — `feat(taxonomy): isolate tenant trees and attachments`
- R9 — this report is included in the required `test(tenancy): verify resource composition and adoption` milestone commit.
