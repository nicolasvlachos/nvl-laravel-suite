# Consumer Readiness Program Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the suite's proven KPO integration into a bounded, auditable, low-friction platform contract for additional Laravel applications.

**Architecture:** The program ships as independently releasable package workstreams behind one suite-level consumer contract. Package Actions own authorization, bounds, persistence, and lifecycle invariants; DTOs own transport-safe projections; clean fixtures and KPO prove the public boundary.

**Tech Stack:** PHP 8.4, Laravel 12/13, Pest 4, Spatie Laravel Data, TypeScript Transformer, Composer path/package fixtures, SQLite/PostgreSQL/MySQL/MariaDB CI.

**Spec:** `docs/superpowers/specs/2026-08-28-consumer-readiness-v2-design.md`

## Global Constraints

- PHP 8.4 is the minimum runtime for this program.
- Laravel 13 is the primary framework target; the release matrix also covers the suite's declared Laravel 12 compatibility where applicable.
- All 1.x work is additive and preserves documented model APIs.
- New application-facing reads return bounded package DTOs or result objects.
- Every list, suggestion, history, and aggregate has a package-owned hard limit.
- Every management read and mutation passes through the package authorization boundary before data is returned or changed.
- Consumers may receive package models as documented identity or route-binding types in 1.x, but do not initiate package persistence queries or writes.
- Owner traits may expose declared relationships; lifecycle mutations remain in the package's Actions and services.
- Package-owned migrations remain immutable after release.
- No package or application dependency is added without separate approval.
- Each implementation task follows TDD and updates its public-contract evidence, generated TypeScript when applicable, and relevant documentation.
- KPO changes are made only after the corresponding suite API is released or available through the repository path used by KPO.

---

## How to track this program

The task IDs below are permanent. Mark a task complete only after its plan's
focused tests, static analysis, contract/type checks, and documented acceptance
gate pass. Record the implementation commit beside the task before checking it.

| ID | Done | Deliverable | Plan | Depends on | Release | Commit |
|---|---|---|---|---|---|---|
| CR-00 | [x] | Reliable root quality runner and migration-analysis topology | Configuration/Auth ergonomics plan | None | 1.1 | `e41ffab` |
| CR-01 | [x] | Consumer boundary doctrine | Guardrails plan | None | 1.1 | `ead83c6` |
| CR-02 | [x] | Consumer source auditor | Guardrails plan | CR-01 | 1.1 | `bfae7ef` |
| CR-03 | [x] | Explicit module decisions | Guardrails plan | CR-01 | 1.1 | `5e3ac9f` |
| CR-04 | [x] | Configure and upgrade-check commands | Guardrails plan | CR-03 | 1.1 | `893c293` |
| CR-05 | [x] | Auth role/permission option DTOs | Auth plan | CR-01 | 1.1 | `f6329e9` |
| CR-06 | [x] | Auth catalogs, suggestions, and group reads | Auth plan | CR-05 | 1.1 | `90e6db0` |
| CR-07 | [x] | Auth identifier/name and assignment seams | Auth plan | CR-05 | 1.1 | `85f607d` |
| CR-08 | [x] | RBAC analytics projection | Auth plan | CR-05 | 1.1 | `c70ef1c` |
| CR-09 | [x] | Activity multi-event filter | Read-seams plan | CR-01 | 1.1 | `6e22fb1` |
| CR-10 | [x] | Mail aggregates and event context | Read-seams plan | CR-01 | 1.1 | `85f4c0b` |
| CR-11 | [x] | Translation catalog statistics | Read-seams plan | CR-01 | 1.1 | `a0c8d61` |
| CR-12 | [x] | Comments/Settings/SEO seams | Read-seams plan | CR-01 | 1.1/1.2 | `f267556` |
| CR-13 | [x] | Content owner editor projection | Pages/Content plan | CR-01 | 1.2 | `aa4c689` |
| CR-14 | [x] | Content placement find/replace/reorder | Pages/Content plan | CR-13 | 1.2 | `42113d8` |
| CR-15 | [x] | Page lookup/options/public children | Pages/Content plan | CR-01 | 1.2 | `aa352a2` |
| CR-16 | [x] | Page editor/publication composition | Pages/Content plan | CR-12, CR-13, CR-15 | 1.2 | `9ffc48c` |
| CR-17 | [x] | Media slot reads and replacement | Media plan | CR-01 | 1.3 | `68e4f3e` |
| CR-18 | [x] | Media slot clear/copy/idempotency | Media plan | CR-17 | 1.3 | `ad289df`, `1c01752` |
| CR-19 | [x] | Auth proof consumer | Validation plan | CR-04, CR-08, CR-12 | 1.3 | `9ee766d` |
| CR-20 | [x] | Content proof consumer | Validation plan | CR-16, CR-18 | 1.3 | `18171c3` |
| CR-21 | [ ] | KPO bounded migration waves | Validation plan | CR-08, CR-10, CR-16, CR-18 | 1.3 | — |
| CR-22 | [ ] | Golden journeys and release matrix | Validation plan | CR-19, CR-20, CR-21 | 1.3 | — |
| CR-23 | [ ] | Missing module flags disabled | 2.0 plan | CR-03, CR-04, CR-22, CR-34 | 2.0 | — |
| CR-24 | [ ] | Compatibility-query deprecations | 2.0 plan | CR-02, CR-21, CR-22, CR-34 | 2.0 | — |
| CR-25 | [x] | Auth delivery, invitation projections, and outcomes | Auth delivery plan | CR-00, CR-01 | 1.4 | `ab5077a`, `d83b303` |
| CR-26 | [ ] | KPO Auth delivery/invitation migration | Auth delivery plan | CR-25 | 1.4 | — |
| CR-27 | [x] | Atomic list/deep-map config merge and drift diagnostics | Configuration/Auth ergonomics plan | CR-00, CR-04, dependency approval | 1.4 | `7961e03` |
| CR-28 | [x] | Runtime profiles and minimal overlays | Configuration/Auth ergonomics plan | CR-03, CR-04, CR-27 | 1.4 | `8ffb3ee` |
| CR-29 | [x] | Embedded-application Auth preset/adapter | Configuration/Auth ergonomics plan | CR-25, CR-27 | 1.4 | `ea47ab3` |
| CR-30 | [ ] | KPO configuration/Auth simplification | Configuration/Auth ergonomics plan | CR-26, CR-28, CR-29 | 1.4 | — |
| CR-31 | [x] | Comments metadata schemas/selectors/projections | Comments metadata/mentions plan | CR-12 | 1.4 | `998547e`, `488a82a` |
| CR-32 | [x] | Rich document and mention persistence | Comments metadata/mentions plan | CR-31 | 1.4 | `3d3ab7e`, `d0455aa` |
| CR-33 | [x] | Mention registry/search/resolve/events/diagnostics | Comments metadata/mentions plan | CR-32 | 1.4 | `31fded0`, `24bec45`, `77db6b1` |
| CR-34 | [ ] | KPO Comments metadata/mentions adoption | Comments metadata/mentions plan | CR-30, CR-31, CR-33 | 1.4 | — |

## Detailed plans

- [Consumer guardrails and adoption](2026-08-28-consumer-guardrails-and-adoption.md)
- [Auth consumer API](2026-08-28-auth-consumer-api.md)
- [Cross-package read seams](2026-08-28-cross-package-read-seams.md)
- [Pages and Content editor API](2026-08-28-pages-content-editor-api.md)
- [Media owner-slot workflows](2026-08-28-media-owner-slot-workflows.md)
- [Proof consumers and KPO adoption](2026-08-28-proof-consumers-and-kpo-adoption.md)
- [Auth delivery and invitation seams](2026-08-28-auth-delivery-and-invitation-seams.md)
- [Consumer configuration and Auth ergonomics](2026-08-28-consumer-configuration-and-auth-ergonomics.md)
- [Comments metadata and rich mentions](2026-08-28-comments-metadata-and-rich-mentions.md)
- [Suite 2.0 consumer defaults](2026-08-28-suite-v2-consumer-defaults.md)

## Milestone 0: baseline and branch safety

- [x] Confirm `git status --short` contains no unowned changes that overlap the first workstream.
- [x] Create an isolated worktree using `superpowers:using-git-worktrees` before implementation.
- [x] Record baseline output from `composer contracts:check`, `composer types:check`, and the focused suite diagnostics tests.
- [x] Confirm KPO's Composer source/version and save the starting KPO strict-test command in the execution log.
- [x] Execute CR-00 and use its root runner for every subsequent package gate.
- [x] Execute CR-01 through CR-04 in dependency order.

### Execution log

- 2026-08-28 — CR-00 started from suite checkpoint `57a6b63` on
  `codex/consumer-readiness` in `.worktrees/consumer-readiness`; the original
  suite checkout and KPO remained read-only.
- Baseline `composer contracts:check` and focused suite diagnostics passed.
  `composer types:check` passed in the original checkout but failed in the clean
  worktree because generated declaration freshness included checkout mtimes.
- KPO consumes `nvl/laravel-suite` `v1.0.7` at source reference
  `983ffbc8a648584a12ee0e3434e78715c6aac478`. Its starting strict backend gate
  is `composer test`, which clears configuration, runs Pint in check mode, and
  runs the Laravel test suite.
- Package-local `composer quality` failed for Auth and Comments because the
  monorepo child packages do not own an installed toolchain. Root-owned package
  quality is therefore the supported suite-development contract.
- 2026-08-28 — CR-01 defined the four canonical consumer-boundary classes and
  classified all twenty package model surfaces in `ead83c6`. Its broader
  Contract gate also exposed linked-worktree metadata entering Composer
  archives; `5caed2f` added a behavioral regression and excludes `.git` and
  `.worktrees` from release artifacts.
- 2026-08-28 — CR-02 shipped the release-safe consumer source and runtime
  auditor in `bfae7ef`. The root gate passed 142 tests with 8,861 assertions,
  PHPStan, strict Composer autoloading and validation, public-contract and
  generated-type checks, Pint, and archive membership. A read-only external
  KPO scan (`runtime_checked: false`) found 64 compatibility model queries,
  three adoption-migration references, and four forbidden Auth model writes:
  invitation delivery metadata writes plus Role and Permission seeder metadata
  writes. KPO remained unchanged; its runtime checks must be run from KPO's
  booted application after it adopts this command.
- 2026-08-28 — CR-03 added explicit `enabled`, `disabled`, and `implicit`
  module decisions in `5e3ac9f`. Omitted 1.x flags remain enabled for backward
  compatibility, while Suite Doctor and consumer audit expose stable warnings
  and fail strict mode only after the adoption switch is enabled. KPO already
  declares all twenty module flags explicitly, so it requires no compatibility
  exception for this gate.
- 2026-08-28 — CR-04 added dry-run-first canonical configuration generation
  and read-only upgrade inspection in `893c293`. Its gate passed 153 tests with
  8,943 assertions, PHPStan, public-contract and generated-type checks, strict
  Composer autoloading/validation, Pint, and archive membership. KPO's existing
  twenty boolean module decisions produce no structural upgrade findings. Its
  read-only external source audit remains unchanged at 64 compatibility model
  queries, three adoption-migration references, and four forbidden Auth model
  writes; `runtime_checked` is false outside KPO's booted application.
- 2026-08-28 — CR-05 added seven TypeScript-enabled Auth RBAC projections
  and one shared hard-limit policy in `f6329e9`. Auth package quality passed
  136 tests with 2,052 assertions plus PHPStan and Pint; root public-contract,
  generated-TypeScript/`tsc`, strict Composer autoloading, and validation gates
  passed. KPO's read-only selector review maps to the new projection fields;
  its translated system-role copy and optional permission scope remain
  application presentation concerns rather than package persistence seams.
- 2026-08-28 — CR-06 added seven authorized RBAC consumer reads in `90e6db0`:
  bounded role/permission options and suggestions, one-query permission-group
  aggregates, and DTO-only role/permission catalogs with safe filters, sorts,
  pagination, counts, and optional assignment identifiers. Independent review
  hardening in `f8336bf`, `49aa5c1`, and `6581a4d` added a hard group cap,
  optional TypeScript query additions with a legacy-shape compile fixture,
  one-pass authorization via internal readers, deterministic assignment IDs,
  and one canonical group rule across writes and legacy reads. NUL bytes are
  rejected by package write DTOs and remain distinct/exactly filterable in
  legacy SQLite rows because PostgreSQL cannot persist them and SQLite cannot
  portably replace them. Auth quality passed 144 tests with 2,207 assertions,
  PHPStan, and Pint; public contracts, generated types/`tsc`, strict Composer
  autoloading, and validation passed. A read-only KPO review confirms its
  selector, suggestion, module, and catalog queries map to the new Actions;
  enum-translated role copy and response shaping remain host presentation.
  KPO files were not modified and endpoint migration remains the separate
  post-CR-08 KPO commit defined by Milestone 1.
- 2026-08-28 — CR-07 added role-name availability, ordered mixed ID/name
  resolution, additive and replacement role-permission assignments, and atomic
  permission creation with initial roles in `85f607d`. Resolution is
  guard-scoped, rejects empty, duplicate, alias-duplicate, unknown, ambiguous,
  and over-limit inputs, preserves caller order, and performs at most one ID
  plus one name query. Assignment Actions authorize before loading RBAC state,
  use the configured Auth connection with deadlock retries, preserve system-role
  identity, clear Spatie's permission cache after commit, and emit one audit and
  one after-commit event. Independent review found and verified hardening for
  maximum-length multibyte names: audits now contain bounded IDs/counts inside
  the transaction, so an audit failure rolls back the mutation and emits no
  event. `RbacEntityLocator` retains its one-argument construction contract.
  Auth quality passed 155 tests with 2,317 assertions, PHPStan, and Pint; the
  complete root `composer test` matrix, public contracts, generated types/`tsc`,
  strict Composer autoloading, and validation passed. KPO's direct availability,
  mixed-identifier normalization, add/sync assignment, and create-with-roles
  compositions now have package-owned replacements; KPO remained read-only and
  migration stays in the separate post-CR-08 wave.
- 2026-08-28 — CR-08 added the authorized, identity-free per-role analytics
  projection in `c70ef1c`. It returns principal totals through the independently
  configured RBAC principal model and active-column mapping, canonical
  permission-group aggregates, direct-child/descendant totals with cycle-safe
  iterative traversal, and the parent name in four queries regardless of one or
  twenty-five related rows. Roles are canonicalized through the configured
  subclass and guard before analytics. Activity remains an explicit consumer
  composition through `ActivityReadService`, using only the authorized identity
  returned by `ShowRoleAction`. Independent review caught both configured-model
  boundaries; separate-table principal and cross-model/cross-guard regressions
  now cover them, and the follow-up review reported no findings. Auth quality
  passed 161 tests with 2,356 assertions, PHPStan, and Pint; the final complete
  root/package `composer test` matrix, public contracts, generated types/`tsc`,
  strict Composer autoloading/validation, and readiness contract passed. KPO
  remained read-only; endpoint replacement stays in CR-21.
- 2026-08-28 — CR-09 added backward-compatible plural Activity event filters,
  clamped exact-pair subject-reference pagination, and model-free subject
  recording in `6e22fb1`. Event input accepts bounded arrays or comma-separated
  strings while retaining the legacy single-event path. Reference reads accept
  at most 100 normalized pairs, preserve numeric-looking types as string-bound
  values, avoid cross-type ID matches, and return empty pages without queries;
  reference writes perform one insert without loading a subject or inferring
  diffs. NUL bytes fail before normalization. The generated `events` field is
  optional so legacy TypeScript literals continue compiling. A RED/GREEN
  consumer exercise hardened the shipped Activity skill and its synchronized
  suite copy. Independent review found the TypeScript, numeric-key binding, NUL,
  and public-generic issues; the follow-up review reported no findings and Ready
  Yes. Activity quality passed 194 tests with 754 assertions, PHPStan, and Pint;
  the final complete `composer test` matrix, public contracts, generated
  types/`tsc`, strict Composer autoloading/validation, skill-mirror contract,
  and readiness evidence passed. KPO remained read-only.
- 2026-08-28 — CR-10 added authorized, bounded Mail statistics dimensions and
  privacy-safe tracking correlation in `85f4c0b`. Mailer and category reads use
  one grouped query per dimension, normalize blank keys to `unknown`, sort
  deterministically, and return at most ten typed aggregates. Tracking contexts
  validate at most twenty non-sensitive scalar identifiers, preserve them
  through clone-style methods, persist the redacted map, and dispatch the same
  map directly on normal and queued-failure start events without reloading the
  package model. Read-only KPO inspection tied these seams to its direct mailer
  breakdown and reminder-occurrence listener reload; KPO migration remains
  deferred to CR-21. A RED/GREEN consumer exercise hardened the shipped Mail
  skill and synchronized suite copy. Independent review reported no findings
  and Ready Yes. Mail quality passed 567 tests with 2,156 assertions, PHPStan,
  and Pint; the final complete `composer test` matrix, public contracts,
  generated types/`tsc`, strict Composer autoloading/validation, skill-mirror
  contract, readiness evidence, and diff hygiene passed.
- 2026-08-28 — CR-11 added authorized translation catalog statistics and a
  model-free filter schema in `a0c8d61`. The statistics action applies the same
  `FilterSet` as entry listing, authorizes before its first query, and uses one
  scalar aggregate plus two canonicalized, bounded grouped queries. Its typed
  projection exposes total, missing, conflict, and durable changed counts plus
  deterministic locale and command-compatible scope maps capped at 100. The
  compatibility model trait delegates to the new schema service, and the API
  controller accepts injected schema resolution without increasing its required
  argument count. Read-only KPO inspection tied these seams to its three direct
  dashboard counts and model-instantiated filter schema; KPO migration remains
  deferred to CR-21. RED/GREEN skill simulations produced exact FQCNs,
  signatures, filter/operator limits, DTO semantics, and a model-free recipe.
  Independent review found numeric locale coercion, pre-canonical scope caps,
  and controller-call compatibility issues; regressions now cover numeric zero
  JSON-object maps, canonical duplicates across the cap boundary, and the legacy
  four-argument call shape. Final review reported no findings and Ready Yes.
  Translations quality passed 75 tests with 361 assertions, PHPStan, and Pint;
  the exact final complete `composer test` matrix, generated types/`tsc`, public
  contracts, strict Composer autoloading/validation, skill-mirror contract,
  readiness evidence, and diff hygiene passed.
- 2026-08-28 — CR-12 added a bounded latest-target Comments projection,
  value-free Settings subject identity, and authorized owner-centric SEO
  profile/revision reads in `f267556`. Read-only KPO inspection tied the seams
  to its candidacy workflow-note relation query, Setting reload for Activity,
  and raw scoped SEO profile/revision access. The Comments selector requires
  exact all-tag matches, optional status, a hard twenty-tag ceiling, active
  rows, audience projection, authorization before SQL, and deterministic
  newest ordering. Settings events now expose `nvl_setting` plus ID without a
  value or new constructor argument. SEO reads resolve registered owner
  identity, authorize before profile SQL, eager-load translations only for the
  full projection, and return revision zero when absent. RED/GREEN skill
  simulations were initially too shorthand-heavy; exact imports, signatures,
  runnable recipes, selector bounds, Activity mapping, and SEO DTO fields now
  pass with synchronized mirrors. Independent review found one stale SEO
  registry comment; after correction it reported no findings and Ready Yes.
  All six workstream package gates, the exact full `composer test` matrix,
  generated types/`tsc`, public contracts, dependency and package-family
  validation, strict Composer validation/autoloading, readiness evidence, and
  diff hygiene passed. KPO remained read-only; migration stays in CR-21.
- 2026-08-29 — CR-13 extracted the existing Content editor bootstrap into
  `GetOwnerContentEditorAction` and added the bounded, Action-only
  `ListOwnerContentPlacementSummariesAction` in `aa4c689`. Read-only KPO
  inspection showed its website controller manually eager-loading placements,
  blocks, definitions, and translations; the package projection now returns
  that editable graph through an optional nested `ContentBlockData` without
  changing compatibility placement responses. Editor DTOs expose the validated
  placement ceiling, deterministic catalogs and placement ordering, and a
  fixed five-query populated read. Bulk reads accept at most 100 entries,
  authorize all canonical owners before SQL, preserve serialization-safe
  `<owner-type>:<owner-id>` keys, enforce bounded hydration, and use the same
  five queries for one or 25 owners of one morph type. The original 22-argument
  Content constructor remains callable; the bulk seam stays dependency-injected
  to preserve package architecture. Content quality passed 118 tests with 820
  assertions; readiness/skill contracts passed 19 tests with 1,701 assertions.
  Independent review reported Ready Yes. The exact full `composer test` matrix,
  root and all-package PHPStan, generated types/`tsc`, public contracts,
  dependency and package-family validation, strict Composer validation and
  autoloading, formatting, mirrored skills, and diff hygiene passed. KPO and
  the original suite checkout remained read-only.
- 2026-08-29 — CR-14 shipped exact block/placement lookup and atomic
  replace/reorder editor Actions in `42113d8`. Review caught and the final
  implementation prevents non-UUID values from reaching PostgreSQL UUID
  predicates, keeps key matching byte-exact across all five supported database
  drivers, and preserves the existing `Content` constructor/facade as an
  Action-only additive API. Content passed 135 tests with 931 assertions; root
  tests passed 159 tests with 9,151 assertions; every package test and PHPStan
  level-max analysis passed, as did contracts, generated TypeScript,
  distribution, dependency, autoload, formatting, and strict Composer gates.
  Independent re-review reported no findings and Ready Yes. KPO and the
  original suite checkout remained read-only.
- 2026-08-29 — CR-15 added authorized exact Page-key lookup, truthful global
  key availability with site-safe conflict disclosure, bounded localized
  options, and public child projections in `aa352a2`. Read-only KPO inspection
  mapped its direct event-path, editor-option, and News child queries to these
  package seams. KPO's News cards exposed two missing contracts: public
  projections now populate an optional source-compatible `publishedAt`, and
  child reads can apply an allowlisted Page kind plus deterministic newest
  effective-publication order before their limit. Review also hardened invalid
  UTF-8/NUL search input, lowercase UUID portability, and translation loading
  after `View` authorization. Pages passed 31 tests with 320 assertions;
  readiness/skill contracts passed 20 tests with 1,755 assertions. The exact
  complete `composer test` matrix, root and all-package PHPStan, generated
  types/`tsc`, public contracts, dependency and package-family validation,
  strict Composer validation/autoloading, formatting, mirrored skills, and
  diff hygiene passed. Independent re-review reported Ready Yes. KPO and the
  original suite checkout remained read-only.
- 2026-08-29 — CR-16 completed in `9ffc48c`. Pages now exposes bounded editor
  summaries, a complete Page/Content/SEO/Metafields editor bootstrap, and an
  ID-based static publication projection. Metafields adds an owner-authorized
  read wrapper, while SEO adds a positional 100-owner bulk projection that
  authorizes every owner before its fixed two profile/translation queries.
  Independent review caught and drove removal of the original summary-level SEO
  authorization bypass plus an over-configurable page-size bound; re-review
  found no Critical or Important findings and reported Ready Yes. Pages passed
  40 tests with 588 assertions, Metafields 69/272, SEO 64/390, Content 135/931,
  Translatable 101/423, Data 52/325, and cross-package integration 19/39. The
  final complete quality gate passed 3,387 root/package tests with 29,496
  assertions and 12 environment-dependent skips, together with Pint, optimized
  autoloading, PHPStan level max, dependency/distribution validation, contracts,
  generated types, and `tsc`. KPO and the original suite checkout remained
  read-only.
- 2026-08-29 — CR-17a established Media's owner-slot operation identity in
  `05aafae` without marking the full CR-17 read/replace workflow complete.
  The additive ledger provides canonical request hashing, insert-or-lock exact
  replay, stable failure codes, nullable clear results, failed-attempt retry,
  renewable processing leases, stale-attempt UUID rotation, terminal-only
  bounded pruning, configurable isolated storage, and strict Doctor coverage.
  `MediaAbility::ManageStaging` and selected-association
  `MediaLibraryItem` projection are generated public contracts. Read-only KPO
  inspection confirmed that `HasSingleDocumentMedia` currently duplicates
  staging ownership, ambient authorization, MIME/size checks, destructive
  replacement, cleanup, and copy semantics that CR-17b/CR-18 will move into
  Media. Independent review found one permanent-processing-claim risk; lease
  recovery, heartbeat renewal, stale-claim invalidation, and explicit
  cross-connection saga guidance resolved it, and re-review reported no
  Critical or Important findings with Ready Yes. Media quality passed 959 tests
  with 2,986 assertions and one environment-dependent skip, including Pint and
  PHPStan level max; focused root readiness/integration passed 34 tests with
  1,753 assertions, and public contracts, generated types, `tsc`, mirrored
  skills, and diff hygiene passed. KPO and the original suite checkout remained
  read-only.
- 2026-08-29 — CR-17 completed in `68e4f3e`. Media now exposes actor-aware
  owner-slot reads and atomic replacement through DTO-returning Actions. The
  workflow validates registered one-to-one slots, availability, visibility,
  MIME, size, custom acceptors, exact uploader identity, public reuse, and
  `ManageStaging`; it composes the existing attach, detach, delete, lock, URL,
  and after-commit lifecycle boundaries. Same-media requests are quiet, shared
  predecessors are preserved, exclusive orphans follow deletion lifecycle, and
  immutable result snapshots keep an exact idempotent response after later
  replacement. Same-connection owner-row locks serialize both occupied and
  initially empty slots; cross-connection owners retain the required shared
  mutation-lock boundary. Independent review first exposed mutable replay and
  empty-slot serialization gaps, then an unmatched snapshot-size limit that
  could fail after commit. Regression-driven fixes resolved all three; final
  re-review reported no Critical or Important findings and Ready Yes. Media
  quality passed 973 tests with 3,077 assertions and three environment-dependent
  skips, including Pint and PHPStan level max; focused root readiness/integration
  passed 34 tests with 1,753 assertions, and contracts, generated types, `tsc`,
  and diff hygiene passed. The PostgreSQL/MySQL two-worker tests activate in the
  database matrix and cover occupied and initially empty slots. KPO and the
  original suite checkout remained read-only.
- 2026-08-29 — CR-19 completed in `9ee766d`. The Auth production consumer
  installs a sealed, non-symlinked Suite archive into fresh Laravel 13 apps and
  passes both package-owned and application-owned migration modes. It proves
  explicit Auth/Settings/Mail authorization, system-authorized RBAC bootstrap
  and assignment, every CR-05–08 read, optimistic Settings mutation plus
  Activity projection, safe Mail aggregates, denied-principal behavior, and a
  real queued tracked Mailable through the database worker. Both modes pass
  config/route cache, skill publication, generated types, strict production
  Doctor, zero-finding strict consumer audit, strict TypeScript, security audit,
  and rollback. The rehearsal found and fixed disabled-module configuration
  leakage in the consumer auditor. Independent review required durable isolated
  PHPStan/Pint coverage, then reported no remaining findings and Ready Yes. The
  final root gate passed 167 tests with 9,450 assertions, both max-level PHPStan
  scans, optimized autoloading, distributions/contracts, generated types, and
  formatting. KPO and the original suite checkout remained read-only.
- 2026-08-29 — CR-20 completed in `18171c3`, with the SQLite Pages rollback
  compatibility fix in `d4368e8`. The Content production consumer installs a
  sealed, non-symlinked Suite archive into fresh Laravel 13 apps and passes both
  package-owned and application-owned migration modes. It proves bilingual
  static/resource Pages, constant-query editor composition, Content mutations,
  localized Metafields/SEO, translation lifecycle, Media slot
  replace/copy/read/clear/idempotency/conflict, queued variations, and strict
  authorization denial. Both modes pass production caches, skills, generated
  types, strict Doctor, zero-finding consumer audit, Composer/npm audit, storage
  assertions across rollback, and complete migration rollback. The Pages guard
  keeps foreign-key enforcement active, targets only the immutable released
  migration, refuses host references, and supports prefixed, mixed-prefix, and
  case-insensitive SQLite identifiers. Independent review reported no findings
  and Ready Yes. Pages quality passed 45 tests with 610 assertions, the fixture
  contract passed 4 tests with 174 assertions, and the full matrix passed 3,449
  tests with 30,235 assertions and 14 environment-dependent skips. KPO and the
  original suite checkout remained read-only.
- 2026-08-29 — CR-27, CR-28, and CR-29 completed in `7961e03`, `8ffb3ee`,
  and `ea47ab3`. Package configuration now merges deep maps while replacing
  atomic lists, reports value-free source drift, supports minimal explicit
  module overlays, and provides an embedded Auth preset with host ownership,
  policy-backed management decisions, and fail-closed diagnostics. Review
  hardened empty host-route evidence, dry-run overwrite diffs, policy-model
  compatibility, and aggregate RBAC defaults, then reported no remaining
  findings. Auth quality passed 186 tests with 2,572 assertions, root tests
  passed 188 tests with 9,888 assertions, and formatting, max-level PHPStan,
  contracts, dependencies, distributions, optimized autoloading, and cached
  configuration inspection passed. KPO and the original suite checkout
  remained read-only.

**Gate M0:** The suite can diagnose consumer-boundary violations and implicit
adoption decisions without changing existing 1.x runtime behavior.

## Milestone 1: release 1.1 consumer reads

- [ ] Execute CR-05 through CR-08 and migrate KPO's Auth read endpoints in a separate KPO commit.
- [ ] Execute CR-09 through CR-12 and replace KPO's direct failed-mail inbox query with the existing `ListMailNotificationsAction(failedOnly: true)`.
- [ ] Run every affected package through `php tools/run-package-quality.php`.
- [ ] Run `composer contracts:check` and `composer types:check` from the suite root.
- [ ] Run KPO's Auth, Activity, Mail Notifications, Translations, Comments, Settings, and SEO feature tests.
- [ ] Add 1.1 upgrade notes that list every additive Action/DTO and the optional strict-adoption flag.

**Gate M1:** KPO contains no direct Role/Permission catalog query used only to
build suggestions, options, groups, identifier resolution, or name availability.

## Milestone 2: release 1.2 editor composition

- [x] Execute CR-13 and prove constant query count for editor and one-to-twenty-five-owner placement projections.
- [x] Execute CR-14 and prove owner locking, revision conflicts, ordering, and authorization.
- [x] Execute CR-15 and prove site, locale, publication, hierarchy, and result limits.
- [x] Execute CR-16 and regenerate the TypeScript contracts.
- [ ] Migrate KPO page/editor reads after the package-focused gate passes.
- [x] Run Content, Pages, SEO, Metafields, Translatable, Data, and integration quality gates.

**Gate M2:** A consumer can build a page editor and public child-page listing
without initiating a Pages, Content, SEO, or Metafields model query.

## Milestone 3: release 1.3 owner-slot workflows

- [x] Execute CR-17 and prove slot resolution, authorization, staging ownership, MIME, size, replacement, and rollback behavior.
- [x] Execute CR-18 and prove clear, copy, idempotency, after-commit effects, and safe projections.
- [x] Execute CR-19 and CR-20 against package-owned and application-owned migrations.
- [ ] Execute CR-21 as separate reversible KPO commits for Auth, Mail, editor reads, Media, and listener context.
- [ ] Execute CR-22 and run archive plus clean-consumer rehearsals.
- [ ] Run the full suite and KPO test suites before drafting 1.3 release notes.

**Gate M3:** KPO's package-lifecycle portion of `HasSingleDocumentMedia` is
deleted, both clean fixtures pass strict audit, and no KPO migration wave adds a
consumer suppression.

## Milestone 4: release 1.4 consumer configuration, Auth delivery, and rich Comments

- [x] Execute CR-25 and prove queued Auth delivery requires no Challenge/Invitation reload or metadata write.
- [ ] Execute CR-26 as reversible KPO listener, read, and candidacy-timing waves.
- [x] CR-27 dependency approval, atomic list overrides, and value-free drift diagnostics are complete; CR-28 preserves KPO's unchanged seventeen-module selection.
- [x] Execute CR-29; preserve host-owned Auth HTTP/UI through explicit ownership and policy mappings.
- [ ] Execute CR-30 in KPO after publishing; remove copied defaults and bridge gates without behavior drift.
- [x] Execute CR-31 through CR-33; run Comments migration upgrades, database portability, generated types, cache safety, concurrency, and reconciliation gates.
- [ ] Execute CR-34; remove KPO's Comments JSON/hash queries and prove its first policy-scoped rich resource mentions.
- [ ] Re-run both proof consumers with minimal overlays and strict package-configuration inspection.
- [ ] Run the full suite/KPO gates and previous-1.x upgrade rehearsal before drafting 1.4 release notes.

**Gate M4:** KPO delivery listeners do not reload package persistence, package
configuration contains intentional overlays rather than copied defaults, and
Comments metadata/mentions are registered, authorized, bounded, and DTO-first.

## Milestone 5: 2.0 only after adoption evidence

- [ ] Collect strict-audit output from KPO and both proof consumers on the final 1.4 release.
- [ ] Confirm all direct package model reads are either removed or explicitly documented compatibility contracts.
- [ ] Execute CR-23 and CR-24.
- [ ] Run previous-minor upgrade rehearsals from the final 1.x tag to the 2.0 candidate.
- [ ] Publish the breaking-change migration guide only after every rehearsal is green.

**Gate M5:** An omitted module key never activates a module, and all removed
compatibility paths have a tested 1.x-to-2.0 migration.

## Program-wide verification commands

Run focused commands after each task; run this full gate at release boundaries:

```bash
vendor/bin/pint --dirty --format agent
composer autoload:check
composer analyse
composer packages:analyse
composer dependencies:check
composer packages:validate
composer contracts:check
composer types:check
php -d memory_limit=1G vendor/bin/pest --compact
composer test:packages
```

For KPO, derive the exact commands from its `composer.json` and run its focused
test files after each migration wave, followed by its complete quality gate.

## Stop conditions

Stop the current implementation task and report evidence when any of these
conditions occurs:

- an additive 1.x API requires changing a documented existing signature;
- a package needs a new external dependency;
- a consumer workflow cannot be expressed without importing KPO business rules;
- a database-portable implementation requires a backend-specific semantic change;
- an audit rule cannot distinguish a supported owner-trait use from a package
  persistence query without a brittle source-text heuristic;
- KPO migration requires a temporary write through a package model or table.
