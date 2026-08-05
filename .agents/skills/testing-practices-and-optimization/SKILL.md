---
name: testing-practices-and-optimization
description: Design, audit, restructure, write, and optimize Pest test suites for Laravel applications and packages. Use whenever work touches test topology, Unit/Feature/HTTP/Integration boundaries, duplicated scenarios, slow or flaky tests, phpunit.xml or Pest.php organization, Composer test scripts, database/Redis/queue/concurrency testing, OpenAPI or distribution contracts, coverage configuration, parallel execution, cache isolation, profiling, performance budgets, or CI test matrices.
---

# Testing Practices and Optimization

Build a trustworthy test suite whose failures identify one clear responsibility. Preserve meaningful security and regression coverage while removing duplicated proof, accidental framework boot, brittle implementation assertions, and wasteful execution.

## Coordinate Required Skills

- Load `pest-testing` when writing or changing Pest test code.
- Load the repository's mandatory PHP/Laravel architecture skills when production PHP, migrations, models, actions, jobs, or providers also change.
- Read repository instructions, sibling tests, `composer.json`, `phpunit.xml*`, `tests/Pest.php`, and CI definitions before proposing a topology.
- Use version-specific Laravel/Pest documentation before changing framework behavior or runner options.

## Follow the Workflow

### 1. Establish the Baseline

Inspect before editing:

1. Inventory test files, suites, groups, scripts, databases, cache/queue drivers, CI jobs, and coverage engines.
2. Run the smallest representative commands without coverage and record wall time, test count, assertion count, expected skips, failures, and the slowest tests.
3. Distinguish application time from process startup, framework boot, migration, fixture, network, and coverage overhead.
4. Identify shared mutable resources: bootstrap caches, environment variables, files, database schemas, Redis prefixes, queues, ports, clocks, and static registries.
5. Record the baseline before claiming an optimization.

Do not use a full suite when a targeted test or capability suite answers the question. Do not create ad hoc verification scripts when existing tests can prove the behavior.

### 2. Assign Authoritative Layer Ownership

Give every invariant one canonical owner at the lowest layer capable of proving it reliably. Upper layers may reuse the scenario only to prove a property unique to that layer.

| Layer | Owns | Must not own |
|---|---|---|
| Pure Unit | Value objects, deterministic policies, requirement merging, transformations, presenters, isolated pipeline rules | Laravel application boot, facades, container resolution, Eloquent, factories, `RefreshDatabase`, filesystem, network |
| Framework/Foundation | Provider registration, bindings, typed configuration, registries, boot/cache compatibility | Full workflows or database behavior unless the framework seam requires it |
| Feature/Application | Actions, transactions, durable state, authorization invariants, replay, idempotency, rollback, events/outbox | HTTP middleware, status codes, headers, route wiring |
| HTTP | Request validation, authentication/authorization middleware, route gates, throttling, status/envelope, secrecy headers, neutral presentation, request-to-action mapping | Re-proving internal state machines, adapter algorithms, proof counts, replay cursors already owned by Feature tests |
| Persistence | Migrations, indexes, constraints, triggers, casts, query portability, rollback | Business workflows already covered through actions |
| Infrastructure/Integration | Real process races, locks, Redis, async workers, provider callbacks, driver reconciliation | Exhaustive business permutations already proven below |
| Browser/E2E | A few critical user journeys and browser-only behavior | API/action permutation matrices |
| Contract | OpenAPI/schema parity, generated surfaces, docs links, skill structure, and test/CI topology | Prose wording and internal implementation details |
| Distribution | Package archives, clean-consumer installation, publish tags, cache/migration rehearsal, and release provenance | Ordinary behavior permutations already proven below |

Read [layer ownership and duplication review](references/layer-ownership.md) when auditing or reorganizing an existing suite.

### 3. Prevent Duplicated Invariants

Treat two tests as duplicated when they have materially the same preconditions, invoke the same behavior, and assert the same durable outcome—even if one enters through HTTP.

For each repeated scenario:

1. Name the invariant in one sentence.
2. Select its authoritative layer.
3. Keep the strongest canonical success, denial, retry, expiry, and rollback cases there.
4. Retain upper-layer coverage only for a unique seam such as validation, middleware, envelope, no-store headers, serialization, real locking, or worker behavior.
5. Remove upper-layer database and implementation assertions after the lower layer owns them.
6. Keep concurrency and real-worker tests when they prove failure modes an in-process Feature test cannot.

Never reduce security coverage merely to lower runtime. Consolidate duplicated proof; do not weaken the invariant.

### 4. Enforce Pure Unit Boundaries

A pure Unit test must run without Laravel Testbench or application bootstrap. It must not use:

- `RefreshDatabase`, Eloquent factories, migrations, facades, `app()`, or `config()`;
- real clock, random, filesystem, process, network, queue, or cache state unless explicitly injected;
- package service providers simply to construct a deterministic object.

Construct dependencies directly. Use small fakes for ports. If the subject inherently needs Laravel, move it to Framework/Foundation or Feature rather than disguising it as Unit.

Keep pure Unit tests in a separately runnable directory or suite with a base Pest/PHPUnit case that does not boot Laravel.

### 5. Keep HTTP Tests Thin but Complete

For each HTTP operation, prove the transport contract that matters:

- route and verb;
- validation and canonical input normalization;
- authentication, authorization, feature/adapter gate, and throttling;
- stable success/failure envelope and response code;
- enumeration-neutral presentation;
- cache/referrer headers for secret-bearing responses;
- mapping of headers, capabilities, route parameters, and payload into the application seam.

Do not reproduce full action sagas or query internal tables unless no lower-level test can prove the HTTP-specific requirement. Use custom response expectations or datasets for repeated envelope, secrecy, and header contracts; keep their failure messages endpoint-specific.

### 6. Separate Expensive Test Classes

- Keep one exhaustive portable schema contract on the primary lightweight database.
- Run representative migration, constraint, index, and trigger checks on every supported real database.
- Use real Redis for atomic locks and throttling; an array cache cannot prove distributed behavior.
- Use an actual out-of-process worker for retry, failure, dead-letter, callback ordering, and payload-redaction behavior.
- Use competing processes for race conditions. An in-process loop is not a concurrency test.
- Keep fast OpenAPI, generated-inventory, documentation-link, agent-skill, and test-topology checks in a mandatory PR Contract suite.
- Keep archive installation, clean-consumer migration/cache rehearsal, and provenance in the nightly/release Distribution gate.
- Validate structure with parsers and standards validators. Avoid regex-parsing XML/YAML or asserting long prose fragments.

### 7. Profile and Budget Before Optimizing

Measure a clean baseline, implement one class of optimization, and compare the same command and environment. Profile without coverage first.

Set repository-specific budgets for:

- pure Unit loop;
- targeted capability suite;
- normal PR gate;
- database/infrastructure jobs;
- nightly/release coverage and distribution gates.

Base CI caps on repeated clean runs or historical percentiles with headroom. Do not fail individual micro-timings on noisy shared runners. Treat a sustained regression of approximately 10% as review-worthy and 20% as a default blocking threshold unless the repository documents another policy.

Prefer structural wins: remove accidental app/database boot, right-size fixtures, avoid repeated migrations, consolidate duplicate scenarios, reuse immutable setup, batch safe matrices, and isolate expensive jobs. Do not trade deterministic assertions for sleeps, retries, shared state, or broad mocks.

Read [CI, coverage, performance, and commands](references/ci-coverage-performance.md) before changing profiling, parallelism, coverage, or CI topology.

### 8. Parallelize with Bounded Isolation

Never enable unbounded or nested parallelism.

1. Calculate a process ceiling from CPU, memory per worker, database connection limits, and external-service capacity.
2. Start with two to four workers unless evidence supports more.
3. Parallelize only tests with process-isolated state.
4. Keep cache/config/route mutators, shared filesystem tests, environment mutations, and real infrastructure orchestration sequential unless explicitly isolated.
5. Give every worker unique Laravel bootstrap-cache paths, temporary directories, database/schema identifiers, Redis prefixes, queues, and ports as applicable.
6. Use a run identifier and worker token in shared-service keys, then clean them deterministically.
7. Prefer CI job sharding by measured duration over a single oversized parallel process pool.

### 9. Apply Coverage Deliberately

- Use PCOV for the normal PR line-coverage signal because it has lower overhead.
- Use Xdebug branch/path coverage for nightly and release gates where the richer signal justifies the cost.
- Do not report branch/path confidence from PCOV.
- Run timing-sensitive concurrency and worker jobs without instrumentation when coverage changes their semantics; keep them mandatory as behavior gates.
- Combine coverage only from compatible PHP versions, dependencies, source revisions, path mappings, and engines.
- Use changed-code coverage to prevent new blind spots and a stable global floor to prevent erosion. Do not chase 100% with implementation-detail tests.
- Exclude vendor and generated artifacts, not difficult production logic.

### 10. Design an Orthogonal CI Matrix

Avoid a full Cartesian product. Cover independent risks with deliberate jobs:

- PR: primary supported PHP/Laravel, lightweight database, pure Unit through HTTP, static analysis/formatting, and PCOV coverage.
- Database matrix: each supported real database for persistence and representative workflows.
- Infrastructure: Redis, queue worker, concurrency, callbacks, and reconciliation.
- Compatibility: minimum and maximum PHP/Laravel plus lowest and highest supported dependencies.
- Nightly/release: Xdebug branch/path coverage, full compatibility, distribution/archive install, config/route cache, migrations up/down, docs/OpenAPI/skill contracts, and security audit.

Do not use blanket automatic test retries. A retry may diagnose a known infrastructure boundary, but the first failure must remain visible and product-test flakiness must be fixed.

## Verify Changes

Run commands in this order, adapting paths and existing scripts rather than inventing competing entry points:

1. The exact changed test or focused filter.
2. The owning capability suite.
3. Adjacent layer suites only when the seam changed.
4. A no-coverage profile comparison.
5. The repository's required formatter and static analysis when PHP changed.
6. The normal PR-equivalent gate.
7. Infrastructure, database, coverage, and distribution gates when their configuration or behavior changed.

Report exact commands, pass/fail/skip counts, wall times, coverage engine/mode, worker count, database/cache/queue drivers, and anything not run. Never claim all-database, async, concurrency, or branch/path confidence from an SQLite/sync/PCOV-only run.

## Reject These Shortcuts

- Adding assertions to every layer “for safety.”
- Calling a Testbench + `RefreshDatabase` test a Unit test.
- Querying internal rows from HTTP tests to re-prove Feature behavior.
- Replacing real concurrency with sequential calls.
- Using `Queue::fake()` as proof of worker retry semantics.
- Using an array cache as proof of distributed atomicity.
- Hardcoding route/type counts in multiple tests and documents.
- Regex-parsing structured configuration when a real parser exists.
- Testing documentation by exact prose fragments instead of structure and links.
- Raising parallel workers without measuring memory, connections, and contention.
- Hiding flakes with sleeps, broad retries, unordered shared state, or relaxed assertions.
- Optimizing only instrumented coverage time while the developer loop remains slow.
