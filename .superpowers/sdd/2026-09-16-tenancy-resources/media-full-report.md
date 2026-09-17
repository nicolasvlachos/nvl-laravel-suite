# Media tenancy R1–R5 report

Status: implementation complete. No push, merge, deployment, or infrastructure cleanup was performed.

## Delivered

- R1: opt-in complete Media resource graph, three-phase tenant schema, concrete grant declaration, adoption/Doctor integration, disabled standalone compatibility, and fail-closed enabled configuration.
- R2: canonical owner reloads, tenant-bounded Actions/read surfaces/traits, public-site-before-binding asset delivery, exact bulk ID validation, and provider-injected global scopes.
- R3: immutable tenant/platform object paths, tenant-keyed deduplication/locks/multipart/idempotency, and transaction-safe file effects.
- R4: concrete grant/revoke/import Actions, scalar catalog snapshot and staged tuple, public `MediaCatalogImport` port, grant-then-source lock order, transaction-required graph persistence, rollback cleanup, provenance, and tenant-local replay protection.
- R5: scalar queue envelopes and uniqueness partitioning, after-commit capture, real worker fixture, bounded regeneration command, production-stack storage checks, and native process races.
- Final closure: resumable checksum-verified legacy shared-asset split/copy with child graph cloning and association rewrites; import-versus-revoke PostgreSQL race forcing each final-lock winner once.

Tenancy remains disabled by default. Independent Media installation brings inert Tenancy and does not install Auth. Translation ownership derives from Media; no translation-to-tenant pivot was added. Unknown enabled owner types remain fail closed.

## Commits

- `011308b` R1 ownership schema/registration
- `d2096d7` R2 entry boundaries
- `6024c68` R3 storage/operation partitioning
- `8499349` R4 grants/imports
- `efa3c7b` R5 queue/worker/storage isolation
- `46662a1` coordinated Auth+Media contract baseline requested by the parent
- `1a04380`, `3d48392` portable coverage and deterministic fixture corrections
- Final closure commit follows this report.

## Verification evidence

| Gate | Result |
|---|---:|
| Final portable Media suite | 1,014 tests; 1,011 passed; 3,354 assertions; 3 expected SQLite service skips |
| Post-closure focused regression | 98 tests; 228 assertions |
| Final provider/scope/queue/adoption regression | 7 tests; 48 assertions |
| Real database queue worker | 1 test; 16 assertions |
| PostgreSQL shared split + import/revoke race | 2 tests; 35 assertions |
| MySQL 8.4 schema + shared split | 2 tests; 21 assertions |
| MariaDB 12.3 schema + shared split | 2 tests; 21 assertions |
| PostgreSQL owner-slot two-process race | 2 tests; 20 assertions |
| PostgreSQL + Redis + S3-compatible production stack | 1 test; 14 assertions |
| Strict modified-source PHPStan | 0 errors |
| Package family | 21 distributions validated |
| Pint / manifest validation / diff check | passed |
| Sealed copied-package consumer | Media + inert Tenancy present; Auth absent |

The three portable skips are the native production/concurrency gates and were executed successfully against the provisioned services; no expected-service skip was accepted as release evidence.

## Coordination and remaining shared step

`tools/package-contracts.json` is not staged in the final closure commit. Concurrent Auth work changes the same generated baseline, so the parent requested one deterministic combined `contracts:update` / `contracts:check` after both Media and Auth commits land. The intentional Media delta includes the PostgreSQL-safe `ownership_key` migration correction and the adapter/provider public reflection changes.

No functional Media concern remains from R1–R5. The canonical Larastan package bootstrap exited silently in the concurrently dirty shared app, so every modified Media PHP source was instead run through strict max-level serial PHPStan and passed; the parent can rerun the combined package analysis once Auth is stable.

## Independent-review correction wave

The 2 Critical, 14 Important, and 1 Minor findings in `media-full-review.md`
were verified against the committed implementation. None was disputed; all were
closed in `703f6d3`, `e0eab9d`, `b7bc07a`, and the final focused correction commit.

| Finding | Closure |
| --- | --- |
| C1 | Tenant orphan inventory/deletion is restricted to the active tenant's canonical prefix; A/B destructive cleanup proves B survives A cleanup. |
| C2 | Private capabilities bind tenant UUID, Media revision, relative signature, and verified canonical origin; disabled mode retains its absolute legacy signature. Wrong host/tenant, stale revision, suspension, selector conflict, HEAD/range, and neutral-not-found cases are covered. |
| I1 | Platform expansion, constrain, and adoption consistently use `ownership_key=platform` with null tenant identity; platform-only activation is covered. |
| I2 | Resume/final verification recheck mapping identity, root/variation bytes, size/checksum, translations, associations, parent ownership, and multipart objects instead of trusting ledger status. |
| I3 | Every reviewed root requires a SHA-256 digest and every destination is justified by registered canonical owners; unknown owners fail closed. |
| I4 | Final constraints have reverse-order driver-correct teardown/reapplication, and activated root `storage_path` is non-null. |
| I5 | Multipart completion converts the physical tenant path back to one logical folder before replacement, preventing duplicate tenant prefixes. |
| I6 | Staging records the exact grant/source/revision/digest/size tuple; persist requires equality with the supplied and locked snapshot. |
| I7 | Grant/import/revoke use a durable grant-identity lock before source/claim locks; separate-process revoke/import and refresh/import winner cases pass. |
| I8 | Final persistence re-reads the recipient from `TenantDirectory` and requires active status inside the transaction. |
| I9 | Catalog metadata uses a documented scalar allowlist; tags have count and length bounds. |
| I10 | Imported-image variation dispatch is registered after commit with the captured tenant envelope. |
| I11 | Create/refresh/revoke emit documented scalar `MediaCatalogGrantAudited` facts after commit. |
| I12 | `--all-tenants` uses injected `MediaTenantWorklist` under an explicit platform operation; Media assumes no host directory table. |
| I13 | Trait reads delegate ownership validation to the injected existing Media service boundary; no trait service locator remains. |
| I14 | Route, multipart/replacement, privileged-reader, platform adoption, corruption/resume, A/B native slot race, and tenant-prefixed production evidence is present and invoked. |
| M1 | Versioned owner-slot request fingerprints include the canonical tenant UUID or disabled sentinel. |

The native A/B slot gate also exposed a Foundation portability defect: inherited
polymorphic children persist heterogeneous owner IDs as strings while a parent
may use a native UUID column. The parent authorized the narrow shared fix in
`TenantBoundary`: only polymorphic identity comparison is cast to portable text.
The focused Foundation regression passes on PostgreSQL, MySQL, and MariaDB;
ordinary inherited relations are unchanged.

### Correction verification

| Gate | Result |
| --- | ---: |
| Consolidated portable Media rerun | 1,032 tests; 1,024 passed; 3,481 assertions; 2 narrow failures; 6 expected native/service skips; the overlapping risky result belonged to the failing set |
| Exact documentation closure | contract/event documentation passed in the 2-case run; its companion exposed one duplicate-output expectation |
| Exact tenant worklist closure | 1 test; 7 assertions |
| PostgreSQL Media schema/adoption/races | 10 tests; 116 assertions |
| MySQL 8.4 Media schema/adoption/races | 10 tests; 116 assertions |
| MariaDB 12.3 Media schema/adoption/races | 10 tests; 116 assertions |
| Polymorphic string/UUID Foundation regression | 1 test; 5 assertions on each of PostgreSQL, MySQL, and MariaDB |
| PostgreSQL + Redis + MinIO exact production closure | 1 test; 20 assertions |
| Strict max-level changed production-source PHPStan | 0 errors |
| Pint / syntax / diff check | passed |
| Package family | 21 distributions validated |
| Sealed standalone consumer | mirrored Media + inert Tenancy present; Auth absent; neither package symlinked |

The broad rerun's two failures were documentation for the new scalar audit event
and a console-output expectation that incorrectly required one occurrence across
two tenant iterations. Both were corrected and their exact cases passed; per the
requested cadence the 1,032-test suite was not run a third time. The production
failure collected later in the same matrix was incomplete test context around
the low-level multipart lifecycle; its exact PostgreSQL/Redis/MinIO case then
passed 1/20.

`contracts:check` remains deliberately parent-owned and reports only the combined
intentional `nvl/auth` and `nvl/media` baseline delta. The canonical package
Larastan launcher still exits silently in this shared application profile; direct
2 GiB max-level analysis of the changed Media/Foundation production sources
passed with zero errors. No service skip was accepted as native evidence.
