# P2 implementation report

Date: 2026-09-17

Base: `da888beb64f04d67b0fbb132ebd5ea0db1d2a4cf`

Status: **implementation present; consolidated verification pending**

No runtime test, consumer, matrix, independent review, PHPStan, Pint, contract regeneration/check, package validation, migration, worker, or broad verification command was run during this implementation turn. `tools/package-contracts.json` was not edited.

## Implemented checklist

- [x] Extended the P1 sealed full consumer to enable all 21 modules and publish two tenant-local graphs with identical Page keys/sites/paths.
- [x] Added translated Page creation, Content definition/block/placement/publication snapshot, private Media reference, string/reference Metafields, Taxonomy term attachment, SEO/sitemap output, Form entry, stored Template version/render, Comment with tenant-filtered principal mention, Activity, scheduled Mail, and tenant-path CSV export through package-owned public Actions/services.
- [x] Added assertions for distinct canonical IDs, snapshots, rendered content/checksums, CSV paths/checksums, scoped sitemap output, signed URLs, queued mail IDs, and tenant-owned rows.
- [x] Added `TenancyConsumerLifecycleCommand` with `backup|adopt|suspend|cleanup|restore|verify` phases, immutable approved-ID manifest, mapping/configuration hashes, conservation values, explicit retention decisions, `TenantMaintenanceRunner`, synchronous package delete Actions, ordered checkpoints, idempotent cleanup retry behavior, and separate disposable restore state.
- [x] Preserved the absence of a generic tenant delete-all API, raw cross-package cleanup, disabled rollback, and permissive production adapters.
- [x] Added fresh-process configuration profiles for disabled, unresolved, full, host UUID/custom principals, standalone Media/no Auth, standalone Taxonomy/no Auth, conflicting platform family, sharing none/copy, invalid classes/families/custom tables/connection aliases, valid custom table/connection aliases, cached/reused process, and unsafe adopted downgrade.
- [x] Added standalone Taxonomy consumer ownership/adoption fixture with no NVL Auth or Media import.
- [x] Added legacy fixture declarations for vendor/copied migrations, role fanout, shared binaries, platform history, self-translation groups, ambiguous owners, and revoked tokens; included mapping/configuration hashes, interruption/resumption, conservation declarations, and explicit ambiguous-activation blocking.
- [x] Added separate-process contenders for last-owner, grant/revoke/import, slug/handle creation, Media slot completion, and form submission idempotency.
- [x] Added tenant-leading query-plan capture with fixed budgets for Page key, Form handle, Taxonomy slug, Media ID, and scheduled-mail status lookups.
- [x] Added 21-package release metadata and contract assertions covering archive closure, discovery, module/config closure, source skill sync, TypeScript/public-contract inputs, Composer validation, and dependency audit.
- [x] Added dedicated tenancy adoption CI contracts, native PostgreSQL restore/taxonomy databases, and release-workflow sealed adoption/lifecycle gating.
- [x] Updated root and all package README/UPGRADING/CHANGELOG/SECURITY surfaces, adoption/readiness/operator documentation, and package plus suite skill sources with the verification-pending status.
- [x] Preserved package independence and opt-in disabled compatibility without dependency changes.

## Deferred consolidated verification — UNRUN

Run only in the parent’s final consolidated verification phase, then fix failures as one batch and run the same matrix once more:

1. `vendor/bin/pest --compact tests/Contract`
2. Focused root integration and every package suite from `tools/package-family.php`
3. PostgreSQL 17, MySQL 8.4, MariaDB 12.3, and SQLite migration/adoption matrices
4. Redis-backed concurrency/lock/queue workers and the five separate-process race fixtures
5. S3-compatible Media lifecycle, signed URL, cleanup, and restore proof
6. `RUN_TENANCY_PRODUCTION_CONSUMER=1 bash tools/run-tenancy-production-consumer.sh`
7. Full archive/discovery/module/config/skill/type/public-contract proof consumers
8. `composer validate --strict` plus every package Composer manifest
9. `composer dependencies:check`
10. `composer packages:validate`
11. `composer contracts:update` once, review the single expected `tools/package-contracts.json` refresh, then `composer contracts:check`
12. `composer analyse && composer packages:analyse`
13. `vendor/bin/pint --format agent` followed by the final affected suite
14. Consolidated independent review after the verification matrix is green

Every item above is **UNRUN** in this report. No release-ready claim is made.
