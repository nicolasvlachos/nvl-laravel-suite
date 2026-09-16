# Configurable tenancy — 5.6 takeover guide

Snapshot date: **2026-09-16**. This is an execution handoff, not a new design proposal.

## 1. Read this first

The user approved the entire configurable tenancy program with **“do it”**. The latest instruction is to pause expensive execution and provide enough information for a **5.6 agent** to take over. Implementation was paused for this handoff. Continue the existing work after takeover; do not restart discovery, planning, or completed tasks.

**12 of 52 tasks are complete and independently reviewed. L2 is implemented in the working tree, tested, but uncommitted and NOT independently reviewed.** Auth, Media, Metafields, and Taxonomy tenancy integrations are still future work. The suite is not ready for a tenancy release.

### Exact workspace

| Item | Value |
|---|---|
| Work only here | `/Users/nicolasvlachos/Herd/nvl-laravel-suite/.worktrees/tenancy` |
| Branch | `codex/configurable-tenancy` |
| Current committed HEAD / L2 review BASE | `a3a9387d1ed4cdd2144fdf6f0336ab8c874b6bbe` |
| Program branch base | `a8c5b97` |
| Original checkout — leave alone | `/Users/nicolasvlachos/Herd/nvl-laravel-suite` on `main`, clean when this handoff was prepared |
| Other worktree — leave alone | `.worktrees/consumer-readiness` |
| Local records | `.superpowers/sdd/2026-09-16-tenancy-*/` inside the tenancy worktree |

Use an explicit workdir for every command. A new task may default to the original checkout. Do not create another worktree or install another vendor tree. This worktree already has its own vendor clone and skills.

**Never run `git reset --hard`, `git clean`, delete this worktree, or overwrite the dirty L2 files.** Most coordination records are ignored by Git and would be lost. This handoff and the new relation files are untracked until explicitly staged. Ordinary `git diff` omits untracked files.

### First actions — in order

1. Confirm `pwd`, `git branch --show-current`, `git rev-parse HEAD`, and `git status --short` in the exact worktree. Compare with the snapshot above; reconcile differences before changing anything.
2. Read this guide, then only these current-task records:
   - [L2 brief](../../../.superpowers/sdd/2026-09-16-tenancy-translatable/task-2-brief.md)
   - [L2 report](../../../.superpowers/sdd/2026-09-16-tenancy-translatable/task-2-report.md)
   - [Translatable ledger](../../../.superpowers/sdd/2026-09-16-tenancy-translatable/progress.md)
3. Inspect the current L2 diff **and all three untracked relation files**. The report has exact commands and results. Do not rerun unchanged green suites just to reconstruct confidence.
4. Resolve the single pending eager-matching interpretation in §4, record the ruling in the Translatable ledger, and update the source plan if needed. This decision was deliberately left open, not silently approved.
5. Finish L2 with the narrowest necessary changes, any affected checks, a scoped commit, and one independent task review. L2's full review base remains `a3a9387…`, even if several commits are needed.
6. Resolve review findings, then mark L2 complete. Only then begin L3. Follow the remaining dependency order in §8.

## 2. Cost and execution rules

The user explicitly objected to excessive consumption. This is a binding constraint on the takeover, not an invitation for another architecture review.

- Use the user's selected **5.6 model**. If a tool requires an explicit model and none more specific was selected, use `gpt-5.6-sol`. **Do not automatically escalate to Astra/6 or another more expensive model.** This user preference overrides prior skill defaults that request the most capable model.
- Use high reasoning only for concrete ownership, concurrency, or native-framework questions; use normal effort for mechanical edits and bookkeeping.
- Read the current brief, relevant source, and required skills. Do not ingest all eight plans and all historical reports on every task.
- Existing task briefs are already prepared. Reuse them with the current reviewed API handoff; do not regenerate them and lose appended rulings.
- One implementation task and one runtime/static test runner at a time. Shared Testbench/bootstrap/cache state makes parallel suite runs unsafe.
- Under the existing subagent-driven workflow, use one bounded 5.6 implementer and one independent 5.6 reviewer when needed. No nested agents, speculative helpers, duplicate reviewers, or parallel full-suite runs. If old agent handles are unavailable, use the report as memory; never restart completed implementation.
- The controller coordinates and records rulings. Production fixes go through the implementer and scoped review, not an unreviewed controller patch.
- Reuse green evidence on unchanged code. Run tests covering actual changes; broader required gates once at the appropriate milestone. Reviewers inspect evidence instead of rerunning the implementer's suite.
- Batch small changes of the same shape where the plan permits. Do not invent generic frameworks or extra features to make a task feel complete.
- Give concise updates about completed milestones, actual blockers, and next actions. Do not narrate every poll or repeatedly say “final checks.” Never represent task count as percentage of remaining effort.
- No new planning/permission round is needed to implement the accepted local plan. No merge, push, release, deploy, developer-database migration, or external dependency upgrade is authorized.

### Task completion protocol

Use the existing [subagent-driven-development skill](/Users/nicolasvlachos/.codex/plugins/cache/openai-curated-remote/superpowers/6.3.0/skills/subagent-driven-development/SKILL.md), subject to the user's cost/model preference above.

1. Record task BASE. Implement, prove the specified failing behavior, run appropriate checks, self-review, commit, and write the report.
2. Generate one **full task** review package from BASE to HEAD, not `HEAD~1`:

   ```sh
   /Users/nicolasvlachos/.codex/plugins/cache/openai-curated-remote/superpowers/6.3.0/skills/subagent-driven-development/scripts/review-package docs/superpowers/plans/2026-09-16-tenancy-translatable.md a3a9387d1ed4cdd2144fdf6f0336ab8c874b6bbe HEAD
   ```

   This exact command is for L2. Later tasks use their own plan and recorded BASE.
3. Give the reviewer brief + report + generated diff paths and binding constraints. Require both **spec compliance** and **code quality** verdicts. Resolve every “Cannot verify” item with evidence or an explicit assigned gate; do not silently ignore it.
4. Fix rounds 1–3 reuse the implementer if available. Each fix gets covering tests and scoped re-review of only that fix range. The existing workflow caps at five rounds; after three failed rounds reassess the cause, never trigger an expensive-model upgrade automatically. Do not silently park a load-bearing/security failure.
5. Record deferred minor findings with a named later owner. A clean review permits `Task N: complete (commits BASE..HEAD, review clean)` in the correct ledger.
6. Final whole-branch review happens after the program, not after every small task. Use a 5.6 reviewer under the cost constraint. No cleanup of these records before their rulings and evidence have been preserved.

## 3. Sources of truth and efficient reading order

Within developer/user constraints: **approved execution contracts → design → recorded rulings resolving plan conflicts → focused plan/brief → implementation**. Earlier “planning only, do not implement” sentences refer to the former architecture-review turn; subsequent “do it” authorized execution.

| Reference | Purpose |
|---|---|
| [Execution contracts](../specs/2026-09-16-tenancy-execution-contracts.md) | Exact shared APIs, lifecycle and acceptance constraints |
| [Design](../specs/2026-09-16-configurable-tenancy-design.md) | Ownership/configuration architecture and first-release limits |
| [Program](2026-09-16-tenancy-program.md) | All packages, order, P1/P2 acceptance |
| [Program ledger](../../../.superpowers/sdd/2026-09-16-tenancy-program/progress.md) | Current state plus historical infrastructure notes |
| [Ruling index](../../../.superpowers/sdd/2026-09-16-tenancy-program/ruling-index.md) | Navigation into exact decisions and their cost if wrong; grouped by plan, not global chronology |
| [Handoff state manifest](../../../.superpowers/sdd/2026-09-16-tenancy-program/handoff-state.json) | Recorded Git state, SHA-256 fingerprints of dirty L2 files, and local evidence inventory |

Ledger milestone paragraphs are append-only history. A later completion/fix entry supersedes an earlier failure or “not started” line. Do not mistake historical notes for current status.

The ignored records exist only in this local worktree. They are essential for this takeover. Do not assume a fresh clone contains them. Before moving machines, deliberately carry these records and uncommitted files; a Git branch alone is insufficient.

Three scratch artifacts were already tracked by earlier commits: foundation `task-8-evidence.log`, foundation `task-8-report.md`, and workflows `task-1-report.md`. Most other records are ignored. Do not force-add the entire scratch tree or indiscriminately stage everything.

## 4. Exact L2 stop point

**Worker:** `/root/tenancy_l2_implementation`. The worker was asked to stop and write a report only. Old agent IDs are informational; takeover must not depend on their availability. No independent L2 review has happened.

### Current implementation

- Ownership is retained in locale preference candidates and correlated by partition + group + locale. Caller/global visibility scopes remain active.
- Related/self Store paths validate current owners and loaded child ownership. Retained objects must not survive A→B changes with stale permission.
- Native lazy, explicit, eager, and existence relationship paths are guarded; explicit OR widening was reproduced and fixed.
- Narrow package-local relations were added: `src/Relations/TranslationHasMany.php`, `TranslationHasOne.php`, and shared `GuardsTranslationRelation.php`.
- Metadata-only relation construction in Declaration/Doctor/Gatherer uses bounded `Relation::noConstraints`; actual retrieval remains guarded.
- Actual related-Connection disabled admission, mixed platform ownership, fallback choices, and query budgets are covered.
- Source-plan clarifications and mechanical contract snapshots are included. L3 write integration and L4 central catalog queries are not implemented by L2.

### Pending decision — do not lose this

The brief requires: **“Eager relation matching must use canonical owner IDs and ownership; a global UUID does not waive the child equality constraint.”**

The worker's implementation admits each persisted eager parent and correlates each child's foreign key **plus every ownership column** to an admitted canonical owner subquery. SQL restricts candidate children to the admitted partition. Laravel then performs its ordinary ID-keyed dictionary matching. Loaded-property/package reads validate child identity again in memory.

The worker asks whether that proof satisfies the requirement, or whether matching itself must use a composite partition + owner dictionary. No ruling was made. Inspect the relation implementation, canonical key/partition schema, native matching behavior and existing tests. Decide against the contract, not against time already spent. If a real ambiguity or gap remains, make the smallest bounded fix and a targeted regression. If the current SQL proof is sufficient, record why, its assumptions, and cost if wrong, then put that reasoning before the independent reviewer without telling them to ignore the issue.

### Test evidence already reported — preserve, do not sum overlapping scopes

| Gate | Last reported result |
|---|---|
| Canonical Translatable package quality wrapper | Pint passed; maximum-level PHPStan **0 errors**; **189 tests / 778 assertions** |
| Named four-file regression gate | **118 tests / 448 assertions** |
| Affected root quality/migration contracts | **36 tests / 1,085 assertions** |
| Public contracts, package family, dependency audit | All **21** packages passed |

The final report supplies exact commands, outputs and RED/GREEN history. An early 35-failure behavioral matrix became 50/50 green; later native OR and compatibility cases extend that evidence. Do not add test counts from overlapping gates.

The report was read in full when preparing this guide. The worker confirmed no active test/static process remains. It also identified a small PHPDoc correction for resume: the relation concern constructor says it admits storage immediately, whereas admission occurs in scope/eager/existence hooks. Correct the wording without treating that documentation edit as a reason to repeat unrelated suites. The report's request to receive a controller ruling refers to the new 5.6 controller deciding the technical question; it is not a requirement to ask the user to redesign it.

The worker reported a contract delta of 83 added / 3 removed lines. `getRelationValue` inherited from the trait appears in nine consuming model snapshots across Content, Forms, Media, Metafields, Pages, SEO, Taxonomy and Templates. This is reported as a mechanical signature/PHPDoc delta, not proof those packages gained tenant support. Inspect the delta normally in review; it was not pre-approved wholesale.

### Dirty files to preserve

- `docs/superpowers/plans/2026-09-16-tenancy-translatable.md`
- `packages/nvl/translatable/src/SelfTranslatable.php`
- `packages/nvl/translatable/src/Translatable.php`
- `packages/nvl/translatable/src/TranslationResourceDefinition.php`
- `packages/nvl/translatable/src/Services/{RelatedTranslationStore,SelfTranslationStore,TranslationDoctor,TranslationResourceGatherer}.php`
- The three new relation files listed above
- `packages/nvl/translatable/tests/Feature/TranslatableTest.php`
- `packages/nvl/translatable/tests/Support/{TenantTranslationFixtureAdoptionAdapter,TenantTranslationScenario}.php`
- `packages/nvl/translatable/tests/Tenancy/Feature/TranslationTenancyTest.php`
- `tools/package-contracts.json`

This guide is an additional documentation artifact. Stage intentionally when finishing the task; do not confuse it with runtime behavior tested by the existing L2 results.

## 5. Completed work — never redispatch these tasks

| Tasks | Reviewed final commit | Result / evidence owner |
|---|---|---|
| F1 | `417bdb0` | Inert Tenancy package/config/adapters |
| F2 | `b78a2ee` | Scoped context, admission, transaction/deferred-dispatch fences |
| F3 | `84fd213` | Explicit core schema/provisioning |
| F4 | `05a5be9` | Registry, persisted ownership and actual-Connection guards |
| F5 | `4a32b1f` | Resumable adoption, native locks, immutable reviewed input |
| F6 | `58a09a0` | Configuration/dependency diagnostics; supported SQL coordinator/schema proof |
| F7 | `c713b5b` | Queue pre-deserialization admission, wrappers, batch/worker lifecycle |
| U1 | `bedfbf0` | Platform-only Settings bootstrap |
| U2 | `061e0c0` | Filter/DTO boundary proof, real Media filter regression |
| F8 | `75c8eff` | Foundation release, independent archive consumer, disabled compatibility |
| W1 | `93aba799e4cc0c2fabcd39422836606e14ba1d78` | Activity recording/read boundary and native relations |
| L1 | `a3a9387d1ed4cdd2144fdf6f0336ab8c874b6bbe` | Explicit Translatable ownership, canonical helper API/fixtures |

Reports and reviews are in the corresponding `.superpowers/sdd/2026-09-16-tenancy-<plan>/task-N-{report,review}.md`; fixes have separate review files. Prefix-to-plan mapping is in §8.

Important review resolutions:

- **F7:** null/default ModelIdentifier connections now match actual canonical Connection before native restoration; unsupported identifier subclasses are rejected; disabled batch reads validate adoption/envelopes before deserializing callbacks.
- **F8:** earlier F6 host-binding-identity Minor was fixed. Do not reopen it from the old ledger line. Archive profiles use an independent Composer loader and selected allowed package ZIPs; no claim of a remote/minimum-dependency matrix.
- **W1:** initial recorder/loader protection was insufficient for native morph relations. Fixes added `ActivityMorphTo`, canonical causer class/table/Connection/key reload, and relation trust bound to current context + exact Activity identity. Combined eager loads preserve admitted sibling relations. See `task-1-fix-1-review.md` and `task-1-fix-2-review.md`.
- **W1:** the exact final SHA is `93aba799e4cc0c2fabcd39422836606e14ba1d78`; one worker message gave an incorrect expanded hash beginning `93aba796…`. Trust Git and this verified SHA.
- **L1:** `lockOwner()` now checks inherited parent FK and morph type for dirty and stale persisted identity. True 5-case RED→GREEN, covering **48/223**, Pint/static/contracts passed. See `task-1-fix-1-review.md`.
- **U1:** positive authorized Platform runtime-override coverage remains a deferred Minor assigned to **U3** and final review.

W1 is not full Activity release readiness: real Settings emission is **U3**, nonempty legacy adoption and tenant retention/purge are **W6**. L1 is not full Translatable readiness: reads/writes/catalog/process/distribution complete only after L2–L5.

## 6. Architecture invariants — preserve exactly

### Ownership and configuration

- First release is **shared database**. No database switching, cross-tenant transfer, nested tenants, billing, quotas, live writable sharing, or automatic PostgreSQL RLS.
- Integrated packages require inert `Nvl\Tenancy`, not Auth. Support/Data/Filterable/Primitives remain neutral. There are **21 distributions** after adding Tenancy; do not use the earlier 19-package count.
- One application ownership profile with family overrides and explicit host adapters; separate migration enablement. Cached config contains no active tenant or closure.
- Disabled compatibility applies only to unadopted storage. Toggling tenancy off cannot expose adopted rows. Schema/connection errors propagate; they are not interpreted as “legacy.”
- Tenant context missing in enabled mode fails closed. Platform, tenant, maintenance, and explicit central-identity operations are different capabilities.
- Ownership belongs on resource roots. Inherited rows enforce parent/child equality. Mixed ownership uses non-null `ownership_key`: exactly `platform` or `tenant:<canonical UUID>`.
- Auth has a membership pivot. Media, Metafield definitions, and Templates have concrete grants. **No universal polymorphic tenant-resource pivot.** Grants allow inspection and copy-on-import; copies become independent. Revocation denies future/pending imports without deleting completed copies.
- Passed models, original/current keys, loaded relations, aliases, raw builders, callbacks, and global scopes are not authority. Validate canonical persisted class/table/**actual Connection object** and declared identity before access. A matching connection name alone is insufficient.
- Scoped container bindings do not reset between same-app `TenantRunner::run(A)` and `run(B)`. No context-free `trusted=true` caches, static retained runtime services, or preloaded-model shortcuts.
- `TenantDirectory` is find-only. All-tenant operational work requires an explicitly authorized bounded scalar worklist or host enumeration capability. No implicit tenant scan.

### Transactions, adoption, and queues

- Context changes cannot outlive open transactions, except balanced identical-context reentry. Leaked transactions are rolled back/invalidated; original exceptions retain priority.
- Adoption runs through the real coordinator and package-owned adapters. No hand-written active markers, dummy registrars, provider-boot adoption, or skipped migrations recorded as applied.
- Released migrations are immutable. Optional schema sets use distinct explicit paths. Existing scanner supports recursive `database/migrations`, `database/tenancy-migrations`, and phased `database/tenancy` as required by owning packages.
- Adoption mappings/fingerprints are immutable once prepared. Repair source/schema consistent with the same map and resume; changed interrupted assignments require backup restore and a new prepare. Active re-adoption is schema/config evolution, never tenant transfer.
- Directory status and maintenance lease are rechecked at admission. Locks use the actual native connection and span DDL. PostgreSQL/MySQL/MariaDB proof exists for foundation; it does not automatically prove later adapters.
- Tenant jobs carry explicit immutable envelopes captured at production time. Context is restored before both normal and failure-command native deserialization. No Platform queue envelope or ambient reassignment.
- Native mail/notification/listener wrappers need explicit nested carriers. No generic wrapper allowlist. Serialized relations/custom collections and unsupported identifier subclasses remain rejected. Signed closures/chains retain native signature bytes.
- Batches have one capture, no mixed/uncaptured/foreign jobs/callbacks; batch afterResponse remains unsupported. Disabled absent metadata is allowed only after actual storage admission. Malformed metadata is denied.

### Translatable-specific API handoff

- `TranslationOwnership` supplies `query`, `assertOwner`, `lockOwner`, `childAttributes`, `partitionColumns`, `partitionKey`. In addition to boundary/context/registry it injects existing `TenantInstallationState` and `TenantOwnershipConfiguration`.
- `partitionColumns()` is metadata, not authorization. Undeclared disabled metadata can return `[]`; every actual row/SQL path must first admit the operation's actual Connection. Declared helpers resolve registered storage.
- Heterogeneous inherited/polymorphic root partition schemas are rejected with `TenantConfigurationInvalid`; no guessing from nullable attributes.
- `lockOwner()` requires an existing transaction on the effective Connection, reloads canonical persisted identity, and rejects dirty/stale ownership/group/inherited-parent identity. L3 still must wire it into all mutations.
- `childAttributes()` derives persisted ownership. Only locale fields are client translation payload. `tenant_id`/`ownership_key` are structural, never arbitrary shared/translated fields.
- The package owns no production translations schema/adopter. Host/package resources own schema; Doctor diagnoses it. Do not introduce a generic translations table.
- Trait seams may resolve current scoped services; Services use constructor injection. Only existing explicit trait exceptions in the family validator are allowed.
- Related-model Connection B requires its own disabled adoption admission; owner Connection A being unadopted is not evidence for B.
- Bounded `Relation::noConstraints` is approved only for exact metadata construction + `getRelated` in Definition/Doctor/Gatherer. No retrieval/count/mutation may enter those wrappers.
- Cold installation-marker probes are measured separately from data-query budgets. Warm/assert admission explicitly before measuring the existing two-query eager-load budget; never bypass the probe.

## 7. Known future traps and assigned owners

| Owner | Required handoff |
|---|---|
| L3 | Wire identity-safe writes/restore/deletion/locking; ownership attributes applied last. Keep self group + partition in all predicates. Use named tests from its brief. L5 owns genuine independent-process race proof; sequential SQLite is not concurrency evidence. |
| L4 | `TranslationResourceLocator::applyQueryScope` currently captures original model **after** executing the configured callback. Validate against independently canonical model/table/actual Connection/base query storage, then enforce ownership after callback, including in-place mutation and a fresh wider builder. L2 does not claim central-catalog safety. |
| L5 | Extend existing `tests/Fixtures/TenancyArchiveConsumer.php` and `tests/Contract/TenancyConsumerWorkflowTest.php` with selected Translatable/Tenancy/Data/Support ZIPs, independent Composer loader, actual discovery/order, cached boot and absent Auth/Suite. Copied native worker with root loader/explicit providers does not prove this. Reuse harness/cleanup, not a new framework. |
| L5 | Real PostgreSQL/Redis barrier/process proof and named SQL matrix. Minimal Redis service/extension/env may be added to the existing PostgreSQL CI job; no duplicate workflow and no silent skip. |
| A2 → A3 | A2 schema fixture uses deny-default membership until A3 installs real access; no temporary allow-all production adapter. |
| A3 → A4/A9 | Move minimal `AuthEventContext`/`AuthAuditWriter`, list-own central identity boundary, and canonical role validation forward where membership writes need them. A4/A9 complete their full inventories later. |
| A5/A6/A8 | Central identity audit persistence must precede enabling identity writes. Intermediate Auth remains fail-closed. Installed Spatie Permission is v8; inspect that version. |
| R1/R6 | Create minimal concrete grant model declarations with the first complete schema/registry if needed; R4/R7 extend their behavior. Never register missing model classes. |
| R9 | Interrupted assignment maps cannot be edited in place. Follow immutable adoption recovery described above. |
| C4/C5 | Minimal real SEO registrar/schema/empty adopter can move to C4 if its site fixture requires them. C5 extends the same implementation. No fake active marker. |
| C4+ | Use one verified tenant/site context; non-HTTP host adapter must verify it. Do not invent a public origin. |
| W3 | Content copy port / Media persistence port avoid reverse package dependencies. Import complete version/content/media graph. |
| U3 | Real adopted Settings platform reader uses `assertUsable(settings.values)` and `ownership_key=platform`; file-backed reboot + real SettingChanged→Activity integration and deferred U1 Platform coverage. |
| U3/R tasks | For callback-after-context stress use a narrowly controlled test-only context with real commit, plus separate native Runner leaked-transaction rejection. Do not weaken the production lifecycle. |
| U3 → W5 | U3 must precede W5's real tenant Settings/SMTP factory fixture. |
| W6 | Activity tenant retention, bounded dispatch, locks and nonempty historical adoption; W1 deliberately does not supply these. |
| P1/P2 | Reuse one sealed archive harness; distinct full and Media-only consumers, exact public actions, genuine worker/bytes/payload evidence. P2 extends P1 after focused gates. |

These points are already recorded in per-plan ledgers and briefs. Read their exact task contracts before implementation; this table is a trap index, not a substitute specification.

## 8. Remaining task order and references

Finish **L2 → L3 → L4 → L5**, then **A1–A10 → R1–R5 → P1 → R6–R9 → C1–C6 → late W/U tasks → P2**. L and A are architecturally independent, but keep implementation and shared tests serial to control cost/conflicts. In the late group, **U3 must run before W5**.

| Prefix | Plan | Local records directory | Status |
|---|---|---|---|
| F | [Foundation](2026-09-16-tenancy-foundation.md) | `.superpowers/sdd/2026-09-16-tenancy-foundation/` | F1–F8 complete |
| L | [Translatable](2026-09-16-tenancy-translatable.md) | `.superpowers/sdd/2026-09-16-tenancy-translatable/` | L1 complete; L2 paused; L3–L5 pending |
| A | [Auth](2026-09-16-tenancy-auth.md) | `.superpowers/sdd/2026-09-16-tenancy-auth/` | A1–A10 pending |
| R | [Resources](2026-09-16-tenancy-resources.md) | `.superpowers/sdd/2026-09-16-tenancy-resources/` | R1–R9 pending |
| C | [Content/sites](2026-09-16-tenancy-content-sites.md) | `.superpowers/sdd/2026-09-16-tenancy-content-sites/` | C1–C6 pending |
| W | [Workflows](2026-09-16-tenancy-workflows.md) | `.superpowers/sdd/2026-09-16-tenancy-workflows/` | W1 complete; W2–W6 pending |
| U | [Settings/tools](2026-09-16-tenancy-settings-tools.md) | `.superpowers/sdd/2026-09-16-tenancy-settings-tools/` | U1–U2 complete; U3–U6 pending |
| P | [Program](2026-09-16-tenancy-program.md) | `.superpowers/sdd/2026-09-16-tenancy-program/` | P1–P2 pending |

Every directory has `progress.md` and prepared `task-N-brief.md`. Prepared briefs do **not** mean implementation exists. Foundation and program source headings use F/P prefixes; their prepared task files use numeric N. Resource/content raw extracts may require shared fixture/schema/registration sections from their plan, as their ledgers explain.

## 9. Tools, checks, and environment

- PHP **8.4**, Laravel **13**, Pest **4**, PHPUnit **12**, Sanctum **4**, Spatie Permission **8**. Root installed PHP was 8.4.14 and Laravel 13.23 at implementation time; inspect lock/vendor if exact patch matters.
- Follow the worktree `AGENTS.md`, architecture/domain skills and applicable test skills. Before PHP edits use Boost `search-docs` with scoped packages. Discover callable tool names if not visible. Do not blindly rely on Laravel examples from a different version.
- Prefer existing package quality tools and the exact commands in each report. Use `vendor/bin/pint --dirty --format agent` after PHP changes, with intentional review of its dirty-file effects.
- Typical isolated tests use SQLite memory, array cache and sync queue, but actual worker/concurrency/storage tasks explicitly require real services. No `RefreshDatabase` outer transaction for real adoption fixtures.
- Installed Testbench requires explicit core schema load in `defineDatabaseMigrationsAfterDatabaseRefreshed`; its earlier hook is dropped by `migrate:fresh`. Reuse the existing dedicated TenancyTestCase/scenario. Setup failures are not behavioral RED.
- Composer cache: `/tmp/nvl-composer-cache`. No dependency updates or network installs merely to avoid a test failure.
- A canonical static wrapper sometimes exits 1 without diagnostics inside the sandbox. An exact generated child passing is diagnostic only. The unchanged full wrapper passed when run with needed escalation in F8/W1/L1; preserve that distinction and never rewrite the runner or add suppressions to make it green.
- Existing archive consumer tests reuse an offline external baseline with no network, plugins/scripts/audit or development packages as prescribed. This proves installed package closure/discovery, not all remote version combinations.
- Never run migrations against the developer application's database. Never start another application server; Herd already serves the original project.
- Do not apply `/private/tmp/nvl-remaining-hardening/commonmark-security.patch`; it was not applied and is unrelated to this current task.

## 10. Private infrastructure — transfer cleanup responsibility

Five task-owned services were provisioned earlier. **They were left in place for takeover; this handoff did not rerun health checks or package tests.** They may have stopped after a restart. Inspect exact process/socket state before use.

Authoritative files:

- [Infrastructure metadata](../../../.superpowers/sdd/2026-09-16-tenancy-program/infrastructure.json)
- [Exact cleanup procedure](../../../.superpowers/sdd/2026-09-16-tenancy-program/infrastructure-cleanup.md)

All disposable data, private binaries/compiler caches and credentials live under:

`/private/tmp/nvl-tenancy-infra-m9fqdc8l`

| Service | Version / address | Evidence already obtained |
|---|---|---|
| PostgreSQL | 17.6; Unix socket `…/socket`, port 15479, TCP disabled | F5 lock and F6 coordinator/schema proof |
| MySQL | 8.4.7; `…/mysql84.sock`, TCP/MySQL X disabled | F5/F6 proof |
| MariaDB | 12.3.3; `…/mariadb.sock`, TCP disabled | F5/F6 proof with actual `mariadb` driver |
| Redis | 8.10.0; `…/redis.sock`, port 0, persistence off | Historical PING only; package proof pending |
| MinIO | pinned release/module; `127.0.0.1:55196`, console 55197 | Historical readiness 200 only; S3 package proof pending |

SQL test database/user: `nvl_tenancy_test`. Exact binary/data/socket paths are in the metadata. Socket access may require escalation.

MinIO credentials are in `…/minio-credentials.json` mode 0600. **Read only when needed by a test; never print, copy into docs, commit, or include in tool output.** Release module is `github.com/minio/minio v0.0.0-20251015172955-9e49d5e7a648`; the locally built binary's DEVELOPMENT.GOGET label is expected. Provenance/checksums are in the program ledger.

On completion or cancellation, the succeeding controller must verify each actual private process, stop all five with the documented exact commands, wait for shutdown, and remove only that exact task-owned root. Never signal stale PIDs or default sockets. **Docker Desktop was already running/broken and is not task-owned: do not stop, reset or repair it.** Do not touch host databases/services.

This model handoff is a pause, not cancellation; retaining these isolated services avoids reprovisioning. If the user cancels the program, perform the cleanup rather than abandoning them.

## 11. Strict acceptance and stopping rules

- Do not say “done” because code exists, tests pass, or a provider boots. Distinguish implemented, tested, reviewed, distributed, adopted, and released.
- Disabled behavior and tenant A/B denial must both be proven. System actors and preloaded models do not bypass ownership. Installed unfinished packages must deny tenant activation/use, not fall back globally.
- A health endpoint is not package evidence; a sequential test is not a race; Testbench registration is not standalone Composer discovery; a copied worker with the root loader is not an independent archive consumer.
- Update affected package docs/changelogs/config/Doctor/contracts/skills only with behavior actually delivered. User authorized those updates; no need to ask again.
- Keep exact commands/results, commits, rulings and deferred issues in the appropriate ledger/report after each task. The final ruling summary must preserve each decision and its cost if wrong. The index is grouped by plan, not an invented global chronology.
- No merge/push/deploy unless explicitly requested later. Finish locally with evidence and a clear integration status.
- If a real external permission or destructive action is needed, first complete the safe, reviewable work and explain the concrete block. Routine reversible implementation is already authorized.

## 12. Paste-ready takeover instruction

> Continue the approved configurable-tenancy program using 5.6. First read `/Users/nicolasvlachos/Herd/nvl-laravel-suite/.worktrees/tenancy/docs/superpowers/plans/2026-09-16-tenancy-5-6-handoff.md`. Work only in that tenancy worktree on `codex/configurable-tenancy`. Preserve its uncommitted L2 implementation and ignored evidence. Twelve tasks are reviewed complete; resume L2's pending eager-matching decision, commit and independent review, then follow the remaining plan. Use existing evidence and focused checks; do not repeat completed work or automatically escalate models. No merge, push or deployment. Take responsibility for the documented private-service cleanup at completion/cancellation.
