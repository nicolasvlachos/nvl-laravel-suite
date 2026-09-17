# P1 implementation report — first real tenant journey

Date: 2026-09-17

Base: `7e413b622120b22c277186e90082a9b5ed9808de`

Scope: implementation only; verification explicitly deferred by the user cadence override.

## Implemented checklist

- [x] Added a Laravel 13 production-consumer fixture with the exact `tenancy-consumer:smoke --phase=seed|verify --format=json` contract and persistent bounded phase report.
- [x] Added a copied sealed Suite-archive Auth+Media consumer with explicit selected modules, configuration, providers, package migrations, cache isolation, route/config caching, and cleanup trap.
- [x] Added a physically separate standalone Media consumer assembled only from copied Support/Data/Filterable/Tenancy/Translatable/Media archives. Its copied application source and configuration contain no NVL Auth import or dependency and bind host tenant directory, membership, platform authorization, adoption, and workflow adapters explicitly.
- [x] Implemented tenant A/B provisioning, one global Auth principal, system-authorized owner provisioning, distinct tenant-local role creation/assignment, and membership/role projection checks through public Auth Actions.
- [x] Implemented fixture-owned `TenantArticle` registration and equal-byte A/B upload/attachment through public Media Actions. Verification compares distinct IDs/tenant-prefixed paths, persisted bytes, canonical digest, associations, and metadata after denied foreign-ID, loaded-Media, and preloaded-owner relation attempts.
- [x] Added scalar `TenantProbeJob` dispatch for A, B, intentional failure, and deliberately corrupted missing-envelope payload. Only fixture observation rows use direct raw inserts; membership and Media behavior use public Actions.
- [x] Added one legacy article, explicit immutable mapping, bounded interrupted/resumed backfill, activation, separate-process restart, and A/B isolation verification.
- [x] Added cached disabled-after-adoption process proof that requires nonzero startup/resource-use failure and rejects any emitted resource-check payload.
- [x] Added a sealed runner with candidate archive reuse, copied archive repositories with `symlink:false`, isolated full and Media-only consumers, seed → real bounded database worker → verify sequencing, and combined actual-outcome JSON evidence.
- [x] Added contract coverage for fixture topology/public API boundaries, sealed runner requirements, workflow integration, and an explicitly enabled actual process assertion over every returned check.
- [x] Added tenancy to the release proof-consumer matrix and added PostgreSQL/Redis/MinIO-backed tenancy consumer execution to the stateful quality workflow.
- [x] Added the fixture to root formatting and PHPStan command surfaces without refreshing package contract baselines.

## Deferred verification — explicitly unrun

The following were intentionally not run in this turn:

```bash
vendor/bin/pest --compact tests/Contract/TenancyProductionConsumerWorkflowTest.php
RUN_TENANCY_PRODUCTION_CONSUMER=1 vendor/bin/pest --compact tests/Contract/TenancyProductionConsumerWorkflowTest.php
bash tools/run-tenancy-production-consumer.sh
TENANCY_CONSUMER_DB_CONNECTION=pgsql \
TENANCY_CONSUMER_FULL_DATABASE=<isolated-full-db> \
TENANCY_CONSUMER_MEDIA_DATABASE=<isolated-media-db> \
TENANCY_CONSUMER_CACHE_STORE=redis \
TENANCY_CONSUMER_MEDIA_DRIVER=s3 \
AWS_ACCESS_KEY_ID=<private-test-key> \
AWS_SECRET_ACCESS_KEY=<private-test-secret> \
AWS_BUCKET=<isolated-test-bucket> \
AWS_ENDPOINT=<private-minio-endpoint> \
bash tools/run-tenancy-production-consumer.sh
composer format:test
composer analyse
composer contracts:check
composer packages:validate
```

No runtime, test, package-validation, static-analysis, formatting, matrix, or independent-review result is claimed by this report.
