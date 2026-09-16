# Configurable Tenancy Execution Program

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add configurable tenant isolation across all 20 existing packages, with a new Tenancy foundation, preserved disabled behavior and a rehearsed adoption path.

**Architecture:** A shared database and global accounts with multiple tenant memberships are the planning assumptions. Roots carry direct ownership, children inherit canonical ownership, and grant pivots permit controlled catalog copies. Tenancy owns context/contracts; each package owns its schema and enforcement.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4/Testbench, existing database/cache/queue/storage integrations. No new external dependency is required.

**Spec:** [Revised design](../specs/2026-09-16-configurable-tenancy-design.md) and [frozen execution contracts](../specs/2026-09-16-tenancy-execution-contracts.md). Read both before changing runtime code.

## Global Constraints

- PHP 8.4 and Laravel 13 are the execution baseline.
- No new external Composer dependency is required.
- All tenancy types and runtime logic live in `Nvl\Tenancy`, including the disabled implementation. Support remains free of tenant domain logic.
- Each package owns its new schema, query predicates, grants, adoption adapter, audit facts, and lifecycle. Tenancy never writes another package's tables.
- Missing context in enabled mode fails closed. Platform access, central identity use cases, and tenant operations are explicit and separate.
- Released migrations are immutable. Optional migration sets use distinct paths and explicit registration.
- This is a prepared execution plan, not evidence that tenancy exists or its future tests pass.

## 1. Decisions settled by the second architecture review

| Concern | Execution decision |
|---|---|
| Independent packages | Integrated packages require the inert Tenancy library, but do not require Auth. Support/Data/Filterable/Primitives keep their dependency direction. |
| Configuration | One application ownership profile, family overrides, explicit adapters, separate migration enablement; cached config contains no current tenant or closure. |
| Tenant/resource pivots | Membership pivot in Auth; concrete Media, Metafield-definition and Template grants. Ownership stays on roots, without a universal polymorphic tenant-resources pivot. |
| Shared resources | Grants allow inspection and copy-on-import. Copies are independent; revocation blocks future/pending imports, without deleting completed copies. |
| Permissions | Global identity, tenant membership/RBAC, and explicit platform capability are separate. Null-team roles cannot leak into tenant role resolution. |
| Scope safety | Canonical persisted ownership is checked even for passed models, loaded relations, system actors, raw queries and locale preference subqueries. |
| Package composition | Settings bootstrap and Activity partitioning precede emitters; Content's `global` scope stays local to a tenant; public packages share one verified tenant/site context. |
| Queues | Context is restored before both command and failure-command deserialization; package work uses scalar IDs and immutable envelopes. |
| Existing installations | Explicit mappings, package split ledgers, staged constraints, maintenance/drain/restart and persisted state guards. Disabling a flag cannot expose adopted data. |

### Limits of this first release

Shared database only; no database switching, cross-tenant transfer, nested tenant
hierarchy, billing, quotas, live writable sharing or automatic PostgreSQL RLS.
SQL credentials and arbitrary trusted host code remain outside the package's
application isolation boundary. Public files and issued presigned URLs retain
their documented visibility/expiry semantics. These are explicit scope limits,
not missing implementation tasks.

## 2. Plan index and task order

Prefixes below are program references. Numbers refer to the named document's
task numbers; the Foundation uses its literal F1–F8 headings.

| Prefix | Focused plan | Tasks |
|---|---|---|
| F | [Foundation](2026-09-16-tenancy-foundation.md) | F1–F8 |
| U | [Settings and tools](2026-09-16-tenancy-settings-tools.md) | 1–6 |
| W | [Workflows](2026-09-16-tenancy-workflows.md) | 1–6 |
| L | [Translatable](2026-09-16-tenancy-translatable.md) | 1–5 |
| A | [Auth](2026-09-16-tenancy-auth.md) | 1–10 |
| R | [Media, Metafields and Taxonomy](2026-09-16-tenancy-resources.md) | 1–9 |
| C | [Content, Pages and SEO](2026-09-16-tenancy-content-sites.md) | 1–6 |
| P | This execution program | P1 vertical proof; P2 final release/adoption proof |

Execute in this order; tests using shared Testbench state remain serial:

1. **F1–F7:** establish package/config/context/directory/registry/adoption/queue contracts.
2. **U1–U2, then F8:** make Settings bootstrap platform-only, prove filter/DTO boundaries, and finish foundation distribution. F8 consumes U2's proof instead of implementing a duplicate filter suite.
3. **W1 (Activity):** recording/read partitioning and captured tenant are ready before tenant events are emitted. W's deferred integrations can follow their dependencies.
4. **L1–L5 and A1–A10:** translation primitives and Auth can be developed independently after those prerequisites. A9's deferred delivery tests use the foundation queue adapter; full Mail package scheduling stays unavailable until W's Mail task passes.
5. **R1–R5, then P1:** Media supports ownership, storage, import grants and jobs; prove shared user/two tenants before extending the content platform. Standalone Media also proves host admission without Auth.
6. **R6–R9:** Metafields/Taxonomy and composed ownership/adoption evidence.
7. **C1–C6:** publish tenant Content/Pages/SEO through the verified site resolver.
8. **W2–W6 and U3–U6:** complete Forms/Templates/Comments/Mail plus Settings/Translations/CSV. Each W task states its narrower prerequisite; independent tasks can be reviewed separately, but shared file edits and tests must be coordinated.
9. **P2:** complete the assembled package graph, production infrastructure and adoption rehearsals before release.

Do not activate a development subset against an installed incompatible full
suite. The early consumer fixtures select only the integrated providers/features.
Where a later integration is installed but unfinished, diagnostics reject tenant
activation or its package bridge denies tenant use; it never falls back to global.

## 3. Coverage of every existing package

| Package | Implementation/proof owner | Particular risk covered |
|---|---|---|
| Auth | A1–A10 | Multi-memberships, last-owner race, roles, tokens, invitations, central identity |
| Media | R1–R5/R9 | Binary identity, dedup, owner slots, direct delivery, jobs, copy grants |
| Metafields | R6–R7/R9 | Definition/value ownership, reference defaults, handle collisions, copies |
| Taxonomy | R8–R9 | Sibling uniqueness, move/merge/prune, raw attachment writers |
| Translatable | L1–L5 | Scope-removing preference queries, group identity, related rows, retained services |
| Content | C1–C2/C6 | Code catalogs, placements, revisions, snapshot graph and external references |
| Pages | C3–C4/C6 | Site/key/tree identity, public binding and resource handlers |
| SEO | C4–C6 | Canonical origins, redirect graphs, sitemap artifacts and caches |
| Activity | W1/W6 | Model-free events, subject/causer OR queries, hydration and retention |
| Forms | W2/W6 | Verified site, signed token binding, receipts, callbacks, exports |
| Templates | W3/W6 | Complete version/content/media import graph, rendering and failure callbacks |
| Comments | W4/W6 | Target/parent equality, mentions, actor projections, idempotency |
| Mail Notifications | W5/W6 | Scheduling, captured factories, provider callbacks, tracking, global Auth mail |
| Settings | U1/U3/U6 | Global config overrides, tenant values and captured invalidation keys |
| Translations | U4/U6 | Platform source catalog, separate overrides, export namespace |
| CSV | U5/U6 | Serialized closures, models, batches, temporary files and downloads |
| Filterable | U2/F8/P2 | Preserve caller-owned predicates through OR, relations and custom handlers |
| Data | U2/F8/P2 | Server-owned ownership fields, stable mutation/display contracts |
| Primitives | F8/P2 | Unchanged dependency/API behavior; no tenancy state introduced |
| Support | F1/F8/P2 | Neutral infrastructure, no tenancy-domain dependency cycle |

## 4. Shared definition of done for each task

- [ ] Read its exact source files, applicable architecture/domain/testing skills and version-specific Boost docs before implementation.
- [ ] Add the concrete failing behavior test specified by the plan, then implement the smallest coherent boundary change. Validate fixture/provider failures separately from the intended red assertion.
- [ ] Test disabled/unadopted compatibility and tenant A/B denial, including passed/preloaded models; complete the task's migration/worker/concurrency proof where named.
- [ ] Run appropriate Pint, static analysis and narrow tests. Do not run concurrent Testbench processes against shared bootstrap/cache/storage.
- [ ] Update affected package config/Doctor/table definitions, Composer dependency/catalog closure, public contract manifest, types, README/UPGRADING/CHANGELOG and canonical skills/mirrors as applicable. No documentation may claim readiness before evidence exists.
- [ ] Review the diff and commit the task. Record commands/results alongside its checkboxes before moving to the dependent task.

## P1: Prove the first real tenant journey

**Prerequisites:** F1–F8, U1–U2, W1, L1–L5, A1–A10 and R1–R5.

**Files:**

- Create: `tools/fixtures/tenancy-production-consumer/app/Providers/TenancyConsumerServiceProvider.php`.
- Create: `tools/fixtures/tenancy-production-consumer/app/Console/Commands/TenancyConsumerSmokeCommand.php`, `app/Models/TenantArticle.php`, `app/Jobs/TenantProbeJob.php` under that fixture.
- Create: that fixture's `config/tenancy.php`, `config/nvl-suite.php`, `database/migrations/2026_09_16_190001_create_tenant_probe_tables.php` and `bootstrap/providers.php`.
- Create: `tools/run-tenancy-production-consumer.sh`, `tests/Contract/TenancyProductionConsumerWorkflowTest.php`.
- Modify: `.github/workflows/package-release.yml` (existing `proof-consumers` matrix) and `.github/workflows/package-quality.yml` (database/infrastructure jobs).

**Interfaces:** New fixture CLI `tenancy-consumer:smoke {--phase=seed : seed|verify} {--format=json}`.
Seed writes a bounded report/work-item IDs under the temporary consumer storage;
verify exits nonzero unless all assertions hold and emits
`{passed:bool, checks:array<string,bool>}`. This CLI belongs only to the fixture.

- [ ] Write a failing contract test that verifies the shell entrypoint runs a sealed archive consumer, config/route cache, real queue worker and both phases. It must check actual outcomes in the consumer, not only search script strings.
- [ ] Adapt the existing `tools/run-auth-production-consumer.sh` and `tools/run-content-production-consumer.sh` conventions to a temporary Laravel 13 consumer. Install a copied sealed archive (no live symlink); isolate bootstrap/cache/storage/database; select only the integrated packages and early bridges. Use the same candidate archive environment variables and cleanup traps as those scripts.
- [ ] Implement `seed` with real public APIs: provision A/B; create one global user and explicitly provision membership owners using Auth's system-authorized provisioning; create distinct roles/assignments; register TenantArticle ownership; upload equal bytes through Media to A and B; dispatch scalar TenantProbeJobs for A/B plus a failing A job. Never seed tenant records with direct raw inserts except fixture probe observations. The source data may use factories, but membership/Media behavior under test must use their Actions.
- [ ] Implement `verify`: compare membership and role projections per tenant; foreign IDs and loaded model instances fail; uploaded asset IDs and persisted object paths differ; A's canonical binary and associations remain unchanged after B attempts mutation; probe rows show correct context in both handle and failed; a missing-envelope job produced no tenant write; global context ends unresolved. Repeat in a Media-only standalone archive with a host membership adapter and no NVL Auth dependency.
- [ ] Run the real worker between phases, with bounded timeouts:

```bash
php artisan tenancy-consumer:smoke --phase=seed --format=json
php artisan queue:work --stop-when-empty --tries=1 --timeout=60
php artisan tenancy-consumer:smoke --phase=verify --format=json
```

- [ ] Add one known legacy fixture before adoption, prepare its explicit mapping, interrupt/resume a backfill, activate, restart the consumer process, and verify A/B isolation. Attempt disabled configuration after adoption in another process; assert startup/resource-use rejects it and returns no rows.
- [ ] Run `bash tools/run-tenancy-production-consumer.sh` with SQLite for smoke and PostgreSQL/Redis/S3-compatible services for actual lock/binary/worker proof. Expected: every JSON check true and exit 0; unavailable infrastructure is recorded as incomplete evidence, never a pass. Commit `test(tenancy): prove auth and media consumer isolation`.

## P2: Complete adoption, lifecycle, configuration and release proof

**Prerequisites:** All focused plans, P1, and their package-level gates green.

**Files:**

- Extend: P1 consumer fixture, runner, workflow and contract test.
- Create: `tools/fixtures/tenancy-production-consumer/app/Console/Commands/TenancyConsumerLifecycleCommand.php`.
- Modify: `docs/adoption-matrix.md`, `docs/consumer-readiness.md`, root/package README, UPGRADING, CHANGELOG and SECURITY; relevant canonical package skills, `tools/package-family.php`, `tools/package-contracts.json` and release archive manifests.

**Interfaces:** New fixture CLI `tenancy-consumer:lifecycle {--phase=backup : backup|adopt|suspend|cleanup|restore|verify}`.
The fixture orchestrates each package's explicit lifecycle/adoption API. Cleanup
does not add a generic production “delete all tenant rows” command; the host's
retention policy and package APIs own each destructive step.

- [ ] Extend the fixture with a complete tenant publication: translated Page → Content placements/snapshot → Media → Metafields/reference → Taxonomy, plus Forms entry, Template render, Comment/mention, Activity, queued mail and tenant CSV export. Create identical handles/sites/paths in B. Compare public responses, sitemaps, signed URLs, rendered documents and export bytes, not only database counts.
- [ ] Exercise each supported configuration row below in a fresh process. Seed fake authorization only in test fixture classes; no permissive adapter ships as a production default.

| Matrix row | Required result |
|---|---|
| Disabled, fresh/current non-tenant schema | Existing APIs/migrations/cache keys work; bounded compatibility probe only |
| Enabled but unresolved | No tenant read/write; narrow central identity workflow remains usable |
| Full application profile, package directory/Auth | Complete A/B journey and strict owner registration |
| Host UUID directory/custom principals | Same admission/ownership proof; normalized connection compatibility |
| Media or Taxonomy standalone without Auth | Host admission works; no circular dependency or hidden Auth import |
| Family platform override conflicting with tenant dependency | Config/Doctor fails before serving tenant data |
| Catalog sharing none/copy | None denies grants/import; copy validates grant/source revision and independent graph |
| Wrong class, unknown family, custom tables/default connection alias | Useful config diagnostics; supported aliases/tables work |
| Config cached, reused request/job process | No captive tenant, locale, settings, permission or factory state |
| Adopted + feature/provider omitted or mode changed | Persisted state rejects unsafe downgrade |

- [ ] Rehearse legacy adoption with both vendor-owned and consumer-copied migrations. Include role fan-out, shared old binaries, platform historical records, self-translation groups, ambiguous owners and revoked old tokens. Record mapping/config hashes; interrupt each phase; prove resumption and count/checksum conservation. Ambiguous rows block activation. Add constraints only after verified backfill and keep all released migrations unchanged.
- [ ] Implement lifecycle fixture phases with a manifest of approved resource IDs, retention decisions and checkpoints. Suspend A and verify new HTTP/queued work stops; allow explicit audited recovery/cleanup through package APIs inside `TenantMaintenanceRunner::run(tenant, operation, callback)` during actual maintenance mode. Drain A's work before deleting its graph, clean dependent records/files in dependency order, leave B intact, and keep legal/audit retention projections explicit. Run cleanup synchronously with package-owned checkpoint records; the maintenance lease cannot dispatch ordinary tenant jobs. Verify retries are idempotent. Restore the pre-adoption database/object backup in a separate disposable consumer and compare checksums. Turning the feature off is never the rollback procedure.
- [ ] Run real competing operations on separate connections/processes: last-owner changes, grant revocation/import, slug/handle creation, Media slot completion and submission idempotency. Assert a single valid linearized outcome and preserved constraints. SQLite-only sequential tests are insufficient for these cases.
- [ ] Run all affected package tests and suite contracts serially, then supported PostgreSQL/MySQL/MariaDB infrastructure jobs, Redis locks/cache, S3-compatible binaries, real workers and repeated-request state tests. Verify tenant-leading query plans and retain existing action query budgets, counting the one-time state probe separately.
- [ ] Complete distribution: root/standalone archives, package discovery, module/config closure, source skill sync, types, public contracts, Composer validation/autoload/dependency audit and new 21-package assertions. Use the existing release checks; do not regenerate unrelated baselines to hide changes.
- [ ] Document operator commands, adapter examples, mode compatibility, concrete grant pivots, key/path semantics, backup/adoption/drain/restart, suspension/cleanup and forward recovery. Mark package readiness only after its evidence is recorded. Commit `test(tenancy): verify full suite adoption and release compatibility`.

## Execution starting point

Begin at **Foundation F1**. Implementation should run in an isolated worktree
using the repository's established workflow. The planning assumptions are stated
above; revisit them only if the product requirements change. No extra design
round is required to begin the inert foundation.
