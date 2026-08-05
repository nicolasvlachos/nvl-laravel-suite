# CI, Coverage, Performance, and Verification

Use this reference when profiling a suite, defining budgets, enabling parallelism, selecting coverage engines, or changing CI topology.

## Contents

1. Baseline record
2. Profiling and budgets
3. Bounded parallelism
4. Cache and shared-service isolation
5. Coverage policy
6. CI matrix
7. Verification commands
8. Reporting template

## Baseline Record

Record enough context to make before/after comparisons honest:

- source revision and dependency lock state;
- PHP, Laravel, Pest, PHPUnit, and coverage-engine versions;
- machine/runner class and allocated CPU/memory;
- command, suite/group/filter, random seed, and worker count;
- database, cache, queue, session, and mail drivers;
- coverage enabled/disabled and coverage mode;
- tests, assertions, expected skips, failures, wall time, and peak memory;
- slowest 10–20 tests or files.

Warm and cold framework caches can differ. Compare like with like and never combine coverage and non-coverage timings.

## Profiling and Budgets

Start with the repository's existing scripts. Typical package commands are:

```bash
vendor/bin/pest --compact tests/Feature/ExampleTest.php
vendor/bin/pest --compact --testsuite=authentication
vendor/bin/pest --profile --order-by=duration --exclude-testsuite=infrastructure
```

Typical Laravel application equivalents are:

```bash
php artisan test --compact tests/Feature/ExampleTest.php
php artisan test --compact --filter='descriptive test name'
php artisan test --compact --testsuite=Feature
```

Use only options supported by the installed Pest/PHPUnit versions.

Define budgets at workflow boundaries, not arbitrary assertion counts:

| Budget | Purpose | Default starting objective when none exists |
|---|---|---|
| Pure Unit | Tight edit loop | 5 seconds or less |
| Targeted capability | Normal behavior iteration | 30 seconds or less |
| PR non-coverage gate | Merge feedback | 10 minutes or less |
| Infrastructure/database job | Real boundary confidence | Explicit per-job cap from measured p95 |
| Nightly/release | Complete compatibility and rich coverage | Explicit scheduled cap |

These are starting objectives, not universal promises. Establish realistic caps from at least three comparable clean runs or CI history. Allow normal variance. Review a sustained ~10% regression; block around ~20% unless an intentional, documented confidence gain justifies it.

Investigate structural causes before micro-optimizing:

1. Accidental Laravel/database boot in Unit tests.
2. Repeated migration and provider boot.
3. Duplicate invariants across layers.
4. Oversized fixtures and factories.
5. N+1 queries or unnecessary event/listener work.
6. Repeated cryptographic cost that can be safely lowered only in the test environment without changing the code path.
7. Network/process startup inside ordinary Feature tests.
8. Coverage instrumentation included in developer-loop measurements.

Do not make tests order-dependent or weaken password/crypto behavior outside the test environment for speed.

## Bounded Parallelism

Calculate a safe worker count:

```text
workers = min(
  available CPU,
  floor(available memory / measured peak worker memory),
  safe database connections,
  safe Redis/queue/provider capacity
)
```

Reserve capacity for the operating system and services. Begin with 2–4 workers. Increase only after a measured improvement with stable memory and contention.

Never combine CI sharding and high in-process parallelism without a total concurrency budget. Prefer a few duration-balanced jobs with bounded workers.

Keep these sequential unless fully isolated:

- config/route/event/package cache mutation;
- global environment and process-state mutation;
- shared filesystem locations;
- singleton external emulators;
- fixed ports;
- migration-cycle tests sharing one database;
- queue-worker orchestration;
- tests designed to arbitrate one shared resource.

## Cache and Shared-Service Isolation

Every process must have unique mutable paths and namespaces.

Laravel bootstrap cache variables commonly include:

```text
APP_CONFIG_CACHE
APP_EVENTS_CACHE
APP_PACKAGES_CACHE
APP_ROUTES_CACHE
APP_SERVICES_CACHE
```

Derive paths from a run ID plus worker token. Apply the same rule to:

- temporary directories and generated artifacts;
- SQLite files or real-database schemas/databases;
- Redis key prefixes and lock names;
- queue names and failed-job storage;
- mail/provider callback fixtures;
- ports and Unix sockets.

Register cleanup that runs on success and failure. Do not recursively delete unresolved or broad paths. Assert that cache-mutating tests are assigned to an isolated or sequential group.

## Coverage Policy

### Pull requests: PCOV line coverage

- Use PCOV for the routine PR signal.
- Measure relevant Unit, Framework, Feature, and HTTP suites.
- Enforce changed-code coverage and a stable global floor.
- Publish a machine-readable report and useful changed-line annotations.
- Do not describe PCOV output as branch or path coverage.

Example shape; adapt flags to installed tooling:

```bash
php -d pcov.enabled=1 vendor/bin/pest --coverage-clover=build/coverage/pcov.xml
```

### Nightly/release: Xdebug branch/path coverage

- Pin a compatible Xdebug version and set `XDEBUG_MODE=coverage`.
- Enable branch/path reporting through the installed PHPUnit/Pest configuration.
- Run on one canonical compatibility job unless another version has a distinct coverage risk.
- Retain reports for trend and release evidence.

Example shape:

```bash
XDEBUG_MODE=coverage vendor/bin/pest --coverage-clover=build/coverage/xdebug.xml
```

Do not merge PCOV line data and Xdebug branch/path data into one claimed metric. Do not merge reports from different revisions, incompatible path mappings, or materially different dependency sets.

Exclude timing-sensitive process races and worker orchestration from instrumentation if coverage changes behavior. Run them uninstrumented as mandatory gates. Cover their deterministic collaborators in lower layers.

Coverage policy must not reward implementation-detail tests. New code requires tests at the authoritative layer; unreachable defensive branches require explicit review rather than fabricated calls.

## CI Matrix

Use orthogonal jobs rather than every combination.

### Pull request

1. Formatting and static analysis.
2. Pure Unit and Foundation.
3. Capability Feature suites, duration-balanced and bounded.
4. HTTP contract/behavior.
5. Primary lightweight-database persistence.
6. PCOV coverage on the canonical PHP/Laravel job.
7. Focused infrastructure smoke when related code changes; keep a complete mandatory infrastructure gate where security requires it.

### Database and infrastructure

- SQLite: exhaustive portable baseline and fast Feature coverage.
- MySQL/PostgreSQL: fresh migrate/rollback, representative constraints/triggers/indexes, and database-sensitive workflows.
- Redis: real rate limiting, locks, claims, expiry, and multi-node behavior.
- Queue: actual separate worker, retry/backoff, terminal failure, callback order, dead letters, and payload redaction.
- Concurrency: competing processes with deterministic barriers, unique namespaces, and terminal-state assertions.

### Compatibility

Cover boundaries without a Cartesian explosion:

- minimum PHP + minimum Laravel + lowest dependencies;
- canonical PHP/Laravel + locked dependencies;
- maximum PHP + maximum Laravel + highest dependencies;
- optional adapters absent;
- each advertised adapter in one focused job.

### Nightly/release

- full database and infrastructure matrices;
- Xdebug branch/path coverage;
- config and route cache;
- archive install and clean consumer;
- migrations up/down;
- OpenAPI, generated contracts, documentation links, and agent-skill validation;
- Composer validation/audit and release provenance.

## Verification Commands

Prefer repository Composer scripts when present. Otherwise use the installed runner directly.

```bash
# Focused behavior
vendor/bin/pest --compact tests/Feature/Capability/SpecificTest.php
vendor/bin/pest --compact --filter='specific behavior'

# Capability and layer
vendor/bin/pest --compact --testsuite=authentication
vendor/bin/pest --compact --group=http

# Profile without coverage
vendor/bin/pest --profile --order-by=duration --exclude-testsuite=infrastructure

# Bounded parallel run after isolation is proven
vendor/bin/pest --compact --parallel --processes=4

# PHP quality gates after PHP changes
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse

# Package metadata when distribution changes
composer validate --strict
```

Run database, Redis, queue, and coverage commands through existing repository scripts because connection names, worker lifecycle, and report flags are project-specific. Never guess credentials or start destructive schema operations against an unresolved database.

## Reporting Template

Report the outcome with evidence:

```text
Changed topology:
- Invariant/layer ownership changes
- Suites/groups/scripts added or removed

Verification:
- Command: ...
  Environment: PHP ..., DB ..., cache ..., queue ..., workers ..., coverage ...
  Result: ... tests, ... assertions, ... expected skips, ... failures, ... seconds

Performance:
- Before: ...
- After: ...
- Delta: ...

Not run:
- MySQL/PostgreSQL/Redis/worker/Xdebug/etc., with reason
```

Never convert an unrun matrix leg into a confidence claim.
