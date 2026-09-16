# Task 1 report: Activity tenant partitioning

## Status

Complete. W1 is ready for independent review from its scoped commit.

## Evidence provenance

- Original pre-interruption RED/GREEN evidence is preserved in `task-1-interrupted-handoff.md`.
- Recovery work began from the inherited uncommitted diff on base `75c8eff3cb7d2e18cd490209469370a4646afa26`; no reset or reconstruction was performed.
- The inherited behavioral RED was reproduced before recovery edits: 5 tests, 4 passed, 13 assertions, expected subject `A` but hydrated preloaded foreign subject `B secret`.

## Boost documentation consulted

- Laravel 13 container scoped binding lifecycle.
- Laravel 13 queued event listeners and queue worker lifecycle.
- Laravel 13 schema/index inspection APIs.
- Laravel 13 Eloquent relationship query behavior and Pest 4 focused test guidance.

## Focused recovery passes

### Canonical hydration, pre-admission observer safety, and deletion capture

Command:

```sh
vendor/bin/pest --test-directory=packages/nvl/activity/tests --configuration=packages/nvl/activity/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/activity/tests/Tenancy/ActivityTenantTest.php
```

Current RED after adding deletion coverage: 7 tests, 6 passed, 16 assertions, 1 error. Automatic deletion was denied by `TenantBoundary` after its canonical subject row had already been removed.

GREEN after implementation: 7 tests, 7 passed, 18 assertions, 0 failures, 586 ms. SQLite `:memory:`, sync queue, no coverage, one serial process.

Changes in this pass:

- Reload canonical Activity attributes before relation hydration and discard caller-preloaded subject/causer relations in enabled mode.
- Validate supplied Activity rows against their persisted ownership before any relation type is considered.
- Resolve relation models from registered ownership or the one configured global presenter before class construction or schema access.
- Apply tenant predicates to relation queries and restrict causer hydration to fixed safe columns.
- Replace `newFromBuilder()` subject admission with a side-effect-free canonical model identity so `retrieved` observers cannot run before ownership denial.
- Capture canonical subject ownership in a scoped weak map before hard deletion, allowing only the matching synchronous Spatie `deleted` fact to retain ownership.

### Causer, timeline, queued-listener, lifetime, and batching boundaries

Command:

```sh
vendor/bin/pest --test-directory=packages/nvl/activity/tests --configuration=packages/nvl/activity/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/activity/tests/Tenancy/ActivityTenantTest.php
```

RED: 10 tests, 8 passed, 27 assertions. The configured credential field was searchable through causer suggestions and the timeline resolver returned tenant B's host while tenant A was active.

GREEN: 16 tests, 16 passed, 45 assertions, 0 failures, 1148 ms. SQLite `:memory:`, native sync queued-listener serialization, no coverage, one serial process.

Changes in this pass:

- Apply registered tenant ownership to timeline host resolution before hydration.
- Treat the configured global causer as a fixed presenter with only key/name/email/first/last projection; configured secret fields cannot be selected or searched.
- Keep a registered tenant model tenant-owned even when configuration names it as the global presenter.
- Prove a package-local, value-free `nvl_setting` event carries an immutable tenant envelope and records through Laravel's native queued-listener serialization after the ambient context changes.
- Reject platform envelope capture for the deferred setting fixture; platform facts remain synchronous.
- Prove immutable Activity ownership, unknown stored subject types never instantiate/query their models, and the retained static timeline bridge resolves fresh scoped services after lifecycle reset.
- Replace per-row canonical Activity validation with one partitioned batch reload. A 12-row timeline performs one Activity data query and one subject data query; fixed key-schema metadata inspection remains bounded rather than scaling per row.

This native sync queue proof exercises Laravel payload serialization/deserialization in-process. It is distinct from the pending child-process absent-Auth archive consumer proof.

### Selected migration inventory

Command:

```sh
vendor/bin/pest --compact tests/Contract/PackageQualityWorkflowTest.php --filter='every selected schema set'
```

RED: 1 test, 0 passed, 4 assertions. The mutable PHPStan inventory omitted the nested Tenancy core migration because it only scanned immediate files under `database/migrations`.

GREEN: 1 test, 1 passed, 6 assertions, 24 ms.

Changes in this pass:

- Discover PHP migrations recursively across `database/migrations`, `database/tenancy-migrations`, and `database/tenancy` while preserving their distinct package-relative paths.
- Include the same selected schema sets in the released-contract snapshot.
- Add Activity's adoption migration directory to package analysis and its tenant integration test to migration behavior gates.
- Register the package's Tenancy test suite in PHPUnit configuration.

Covering command:

```sh
vendor/bin/pest --compact tests/Contract/PackageMigrationQualityTest.php tests/Contract/PackageQualityWorkflowTest.php
```

Result: 36 tests, 36 passed, 1084 assertions, 1447 ms. The updated released contract locks the distinct Activity ownership migration path and the quality descriptor names both its analysis path and behavior test.

### Empty-store adoption boundary

Command:

```sh
vendor/bin/pest --test-directory=packages/nvl/activity/tests --configuration=packages/nvl/activity/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/activity/tests/Tenancy/ActivityTenantTest.php --filter='selected adoption'
```

GREEN: 1 test, 1 passed, 7 assertions, 253 ms.

The dedicated Testbench fixture executes the real coordinator against an empty Activity store. The assertion verifies the normal Activity migration default remains enabled, `tenant_id` and non-null `ownership_key` exist, and `activity_ownership_created_idx` is present. Once a fact exists, the package adapter explicitly refuses another prepare step because historical ownership cannot be inferred.

### Standalone archive consumer without Auth

Command:

```sh
vendor/bin/pest --compact tests/Contract/TenancyConsumerWorkflowTest.php --filter='Activity facts'
```

RED: 1 test, 0 passed, 0 assertions. The archive consumer had no Activity profile entry point.

GREEN: 1 test, 1 passed, 14 assertions, 2821 ms.

The test creates ZIP archives for Activity, Tenancy, Data, and Support; installs them into a temporary application from offline path repositories; boots cached configuration through the consumer's own Composer loader; confirms `Nvl\\Auth` and the suite provider are absent; executes the real core and Activity migrations plus coordinator adoption; and records the same external subject reference under tenants A and B. Tenant A reads only its own immutable ownership row.

This is the actual child-process/standalone distribution proof. The queued-listener test above separately proves native Laravel queued-listener serialization and producer-context restoration in the package test process.

### Activity regression coverage

Command:

```sh
vendor/bin/pest --test-directory=packages/nvl/activity/tests --configuration=packages/nvl/activity/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/activity/tests/Tenancy/ActivityTenantTest.php packages/nvl/activity/tests/Feature/ActivityRecorderTest.php packages/nvl/activity/tests/Feature/ActivityModelCaptureTest.php packages/nvl/activity/tests/Feature/ActivityTimelineReadTest.php packages/nvl/activity/tests/Feature/ActivityApiTest.php packages/nvl/activity/tests/Feature/ActivitySafetyTest.php packages/nvl/activity/tests/Feature/ActivityCauserSuggestionTest.php
```

First covering RED: 108 tests, 107 passed, 360 assertions. Disabled mode added the required bounded installation/schema compatibility probe to the model-free recorder query budget. A temporary attempt to skip that probe made the old budgets green but violated the frozen adoption contract and was reverted. The legacy query-budget tests now warm and measure the one bounded probe separately; adopted storage must deny access when configuration is later disabled.

GREEN before the adoption-contract correction: 108 tests, 108 passed, 361 assertions, 3849 ms. This result is retained as diagnostic evidence and is superseded by the final covering gates below.

Focused correction command:

```sh
vendor/bin/pest --test-directory=packages/nvl/activity/tests --configuration=packages/nvl/activity/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/activity/tests/Tenancy/ActivityTenantTest.php packages/nvl/activity/tests/Feature/ActivityRecorderTest.php packages/nvl/activity/tests/Feature/ActivityTimelineReadTest.php --filter='supplied subjects|adopted Activity|model-free subject references|query count is independent'
```

Result: 4 tests, 4 passed, 24 assertions, 387 ms. The boundary now rejects supplied models with forged tables, actual connection instances, or dirty persisted primary keys without firing retrieved observers. Recorder, query, and direct hydration paths all deny an adopted store after `tenancy.enabled` is switched off.

### Activity package quality

The first full package-quality run passed formatting, reported the four actionable PHPStan errors in Activity hydration, and stopped before tests. The errors were fixed with runtime scalar-identifier validation and an exact-class helper that retains the required runtime defense. A later package run reached 225 tests and found one existing empty-input query assertion: the reader performed the compatibility probe before local oversized/empty input handling. The reader now validates and returns empty input before constructing a scoped Activity query; every nonempty read remains globally partitioned.

Final direct gates:

```sh
vendor/bin/phpstan analyse --configuration=storage/framework/cache/package-quality/activity/phpstan.neon --no-progress --error-format=table --memory-limit=3G packages/nvl/activity/src packages/nvl/activity/database/factories packages/nvl/activity/database/seeders packages/nvl/activity/database/tenancy-migrations packages/nvl/activity/tests/Stubs/TestActivitySubjectWithHasModelActivity.php packages/nvl/activity/tests/Stubs/TestActivityTimelineHost.php
vendor/bin/pest --test-directory=packages/nvl/activity/tests --configuration=packages/nvl/activity/phpunit.xml.dist --bootstrap=vendor/autoload.php --cache-directory=storage/framework/cache/package-quality/activity/phpunit --compact packages/nvl/activity/tests
```

Results: PHPStan passed with 0 errors; the complete Activity package passed 225 tests and 829 assertions in 6226 ms. `php tools/run-package-quality.php activity` separately confirmed formatting, but its PHPStan child later exited 1 without diagnostics in the documented sandbox-sensitive path; the exact generated-configuration command above is diagnostic evidence.

The unchanged canonical wrapper was then rerun outside the sandbox, matching the established F8 workaround. It passed formatting, PHPStan with 0 errors, and all 225 tests/829 assertions; total wrapper duration was 7865 ms.

### Root ownership gates

Commands and results:

```sh
php tools/check-package-contracts.php
# Public contracts for 21 NVL packages are unchanged.

php tools/validate-package-family.php
# Validated 21 NVL package distributions.

php package-dependency-audit.php
# No composer issues found for all 21 NVL packages.

vendor/bin/pest --compact tests/Contract/TenancyConsumerWorkflowTest.php tests/Contract/PackageMigrationQualityTest.php tests/Contract/PackageQualityWorkflowTest.php
# 40 tests, 40 passed, 1163 assertions, 9561 ms.
```

Adjacent Suite composition coverage initially expected Activity to expose no tenant resource. Its expectation now includes the registered `activity.events` resource while the incomplete application remains unresolved. The covering command passed 45 tests and 2046 assertions in 2319 ms.

### Deferred retention boundary

Command:

```sh
vendor/bin/pest --test-directory=packages/nvl/activity/tests --configuration=packages/nvl/activity/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/activity/tests/Tenancy/ActivityTenantTest.php --filter='tenant retention dispatch'
```

Result: 1 test, 1 passed, 2 assertions, 337 ms. The existing purge Action reaches Laravel's native dispatcher and is denied because its job has no tenant envelope; the tenant fact remains present. W6 owns the bounded per-tenant retention dispatcher, locks, and purge behavior. W1 does not add a platform envelope, global-job exception, or implicit tenant enumeration.

## TDD evidence

- Initial recorder RED and shared-reference GREEN are original evidence in `task-1-interrupted-handoff.md`.
- Recovery hydration RED was freshly reproduced before the loader implementation: 5 tests, 4 passed, 13 assertions; expected `A`, received `B secret`.
- Deletion RED was freshly observed before deletion capture: 7 tests, 6 passed, 16 assertions; `TenantBoundaryViolation` because the subject row no longer existed.
- Both behaviors are green in the latest focused run above.

## Final verification after self-review

```sh
vendor/bin/pint --dirty --format agent
# passed; one test import order fixed

vendor/bin/pest --test-directory=packages/nvl/activity/tests --configuration=packages/nvl/activity/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/activity/tests/Tenancy/ActivityTenantTest.php
# 20 tests, 20 passed, 63 assertions, 1623 ms

vendor/bin/phpstan analyse --configuration=storage/framework/cache/package-quality/activity/phpstan.neon --no-progress --error-format=table --memory-limit=3G packages/nvl/activity/src packages/nvl/activity/database/factories packages/nvl/activity/database/seeders packages/nvl/activity/database/tenancy-migrations packages/nvl/activity/tests/Stubs/TestActivitySubjectWithHasModelActivity.php packages/nvl/activity/tests/Stubs/TestActivityTimelineHost.php
# passed, 0 errors

php tools/check-package-contracts.php
# Public contracts for 21 NVL packages are unchanged.
```

## Limitations

- W1 supports empty-store adoption only. Nonempty Activity history is explicitly denied until a separate reviewed historical ownership workflow exists.
- Tenant retention dispatch remains fail-closed until W6 supplies its explicit bounded dispatcher.
- The Settings test proves only the package-local value-free protocol and native queued-listener serialization; U3 owns the real Settings repository integration.
