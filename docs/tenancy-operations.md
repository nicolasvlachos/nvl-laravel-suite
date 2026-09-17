# Configurable tenancy operations

This runbook covers the implemented P2 adoption and lifecycle surfaces. The implementation is present, but the consolidated verification matrix is intentionally pending. Do not promote a build solely from this document.

## Before activation

1. Select one migration ownership mode for every stateful package. Never mix vendor-loaded and copied migrations for the same package.
2. Render `nvl:suite:configuration`, inspect all 21 module decisions, then run every enabled package Doctor and `nvl:suite:doctor --production --strict`.
3. Produce an immutable tenant-assignment manifest. Record its mapping hash, effective configuration hash, row counts, stable identifiers, structured payload digests, binary checksums, and render checksums.
4. Reject ambiguous owners. There is no default tenant and no activation override.
5. Enter maintenance, drain workers, run prepare/backfill/verify/activate through `TenantAdoptionCoordinator`, rebuild caches, then restart workers.

## Lifecycle rehearsal

The sealed consumer exposes `tenancy-consumer:lifecycle backup|adopt|suspend|cleanup|restore|verify`.

- `backup` records the approved resource IDs, conservation values, and explicit retention decisions.
- `adopt` resumes and verifies the immutable adoption run.
- `suspend` changes directory status through `ChangeTenantStatusAction`.
- `cleanup` requires application maintenance and `TenantMaintenanceRunner`; it invokes synchronous package-owned delete actions in comments, form entries/forms, taxonomy, pages/composed resources, and media-object order. Checkpoints make retries idempotent.
- `restore` runs only against a separate disposable pre-cleanup database/object snapshot.
- `verify` proves the approved tenant resources are gone, the control tenant remains, and retained audit, mail, template-render, and policy-held records remain.

There is deliberately no generic tenant delete-all API, raw cross-package cleanup loop, or disabled-mode rollback after adoption.

## Configuration and compatibility matrix

Every row runs in a fresh process: disabled compatibility; unresolved fail-closed boot; full package-directory/Auth; host UUID/custom principal; standalone Media without Auth; standalone Taxonomy without Auth; conflicting platform family; sharing `none` and `copy`; invalid adapter classes, families, custom tables, and connection aliases; valid custom tables/connections; cached and reused process; and unsafe adopted downgrade.

## Concurrency and performance

Separate contenders cover last-owner revocation, grant/revoke/import, duplicate slug/handle creation, Media slot completion, and form-submission idempotency. Query-plan artifacts use tenant-leading predicates and record fixed budgets for page key, form handle, term slug, Media ID, and scheduled-mail status lookups.

## Deferred release gate

The final gate must run the contract suite, all package/integration suites, supported SQL matrices, Redis/concurrency proof, S3 Media proof, sealed consumers, archive/discovery/module/config/skill/type/public-contract checks, Composer validation, dependency audit, PHPStan, and Pint. Run it once after implementation stabilization, fix failures as a single batch, then run the same matrix once more. Until then, release readiness is **pending**.
