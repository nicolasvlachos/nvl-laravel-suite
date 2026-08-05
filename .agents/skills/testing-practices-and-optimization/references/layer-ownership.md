# Layer Ownership and Duplication Review

Use this reference when auditing an existing Laravel/Pest suite or deciding where a new invariant belongs.

## Contents

1. Invariant register
2. Layer decision rules
3. Duplication test
4. Consolidation workflow
5. Assertion design
6. Common smells

## Invariant Register

Create a temporary review table. It belongs in the audit output unless the repository already maintains one; do not add permanent process documents without authorization.

| Invariant | Canonical test | Owner layer | Unique upper seams | Expensive resources | Decision |
|---|---|---|---|---|---|
| Example: recovery code is consumed once | Feature action test | Feature | HTTP capability mapping; process race | DB, real process | Keep canonical + race, thin HTTP |

Write invariants as externally meaningful guarantees, not method names. “A replayed challenge cannot create a second proof” is useful. “Recorder method is called once” is usually an implementation detail.

## Layer Decision Rules

Choose the lowest layer that can falsify the invariant faithfully:

1. Can plain PHP objects prove it? Use Pure Unit.
2. Does it concern Laravel binding, provider boot, configuration merge, or cache safety? Use Framework/Foundation.
3. Does it require a transaction, Eloquent state, authorization policy, event/outbox row, replay cursor, or rollback? Use Feature/Application.
4. Does it concern parsing, middleware, throttling, status, envelope, headers, or serialization? Use HTTP.
5. Does it concern DDL, an index, constraint, trigger, cast, or database portability? Use Persistence.
6. Does it require multiple processes, Redis atomicity, an external adapter, or a real worker? Use Infrastructure/Integration.
7. Does it concern a browser runtime or user interaction unavailable below? Use Browser/E2E.
8. Does it concern a fast public/generated contract? Use Contract on pull requests.
9. Does it require installing or exercising a shipped artifact? Use Distribution on nightly/release gates.

Security invariants still need the lowest faithful owner. “Security” is not a reason to repeat every internal assertion at HTTP and E2E layers.

## Duplication Test

Two tests likely duplicate one invariant when all of these match:

- equivalent principal/data setup;
- equivalent policy or feature state;
- the same application action, directly or through a controller;
- the same success or failure transition;
- the same durable records, counters, statuses, or events;
- no unique transport, database-engine, process, queue, or browser property.

Duplication is justified when the second test changes the failure model. Examples:

- an in-process replay test plus a competing-process uniqueness test;
- a Feature rollback test plus a real-database trigger test;
- an action test plus an HTTP test proving middleware or no-store headers;
- a queued job unit test plus an out-of-process worker retry test.

## Consolidation Workflow

1. Group candidate tests by invariant, not filename.
2. Compare setup, invocation, and assertions.
3. Select the strongest canonical test at the authoritative layer.
4. Merge missing denial, retry, rollback, expiry, and idempotency assertions into that canonical group only when they express distinct behavior.
5. Reduce higher layers to their unique seam.
6. Preserve process, database-driver, queue, and browser cases that change the failure model.
7. Split giant files by mechanism or workflow for diagnosis; do not split by arbitrary line count.
8. Run both old and proposed scoped suites before deleting or moving any test.
9. Re-profile and report coverage changes.

Do not create a single mega-test that hides which invariant failed. Prefer one behavior per test with shared fixture builders that expose security-relevant inputs.

## Assertion Design

Assert public or durable effects:

- returned result and stable error code;
- state transition and version/counter change;
- one-time consumption or idempotent replay;
- authoritative ledger/outbox presence;
- absence of forbidden disclosure;
- adapter or pipeline boundary where it is itself the contract.

Avoid implementation coupling:

- private helper names;
- exact SQL text;
- arbitrary internal call counts;
- incidental model query order;
- exhaustive concrete-class inventories unless intentionally frozen public API;
- exact prose, whitespace, or documentation sentence fragments;
- repeated literal route/type counts in multiple places.

Use datasets for true input/output matrices. Do not place an entire workflow matrix in one dataset when failures require different setup or express different invariants.

Use custom Pest expectations for cross-cutting response contracts such as no-store headers or secret-field absence. Keep endpoint and expected property in the failure message. A helper must reduce repetition without hiding what is being proven.

## Common Smells

- Unit directories use Testbench and `RefreshDatabase` globally.
- HTTP tests query several internal tables after every request.
- The same idempotency or rollback case appears in Unit, Feature, HTTP, and Integration.
- Large tests differ only by a factory attribute or enum scalar.
- Config tests repeat every default already owned by a typed manifest.
- OpenAPI tests recursively assert the same `$ref` for every occurrence instead of resolving unique references once.
- Topology tests regex-parse XML, YAML, JSON, or PHP source.
- Documentation tests assert long strings instead of schema, links, headings, and generated inventory parity.
- Real integration tests are removed because a fake-backed Feature test “already covers” the scenario.
- Broad snapshots make intentional additive API changes unreadable.
