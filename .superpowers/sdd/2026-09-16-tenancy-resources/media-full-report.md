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
