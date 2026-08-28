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
| CR-05 | [ ] | Auth role/permission option DTOs | Auth plan | CR-01 | 1.1 | — |
| CR-06 | [ ] | Auth catalogs, suggestions, and group reads | Auth plan | CR-05 | 1.1 | — |
| CR-07 | [ ] | Auth identifier/name and assignment seams | Auth plan | CR-05 | 1.1 | — |
| CR-08 | [ ] | RBAC analytics projection | Auth plan | CR-05 | 1.1 | — |
| CR-09 | [ ] | Activity multi-event filter | Read-seams plan | CR-01 | 1.1 | — |
| CR-10 | [ ] | Mail aggregates and event context | Read-seams plan | CR-01 | 1.1 | — |
| CR-11 | [ ] | Translation catalog statistics | Read-seams plan | CR-01 | 1.1 | — |
| CR-12 | [ ] | Comments/Settings/SEO seams | Read-seams plan | CR-01 | 1.1/1.2 | — |
| CR-13 | [ ] | Content owner editor projection | Pages/Content plan | CR-01 | 1.2 | — |
| CR-14 | [ ] | Content placement find/replace/reorder | Pages/Content plan | CR-13 | 1.2 | — |
| CR-15 | [ ] | Page lookup/options/public children | Pages/Content plan | CR-01 | 1.2 | — |
| CR-16 | [ ] | Page editor/publication composition | Pages/Content plan | CR-12, CR-13, CR-15 | 1.2 | — |
| CR-17 | [ ] | Media slot reads and replacement | Media plan | CR-01 | 1.3 | — |
| CR-18 | [ ] | Media slot clear/copy/idempotency | Media plan | CR-17 | 1.3 | — |
| CR-19 | [ ] | Auth proof consumer | Validation plan | CR-04, CR-08, CR-12 | 1.3 | — |
| CR-20 | [ ] | Content proof consumer | Validation plan | CR-16, CR-18 | 1.3 | — |
| CR-21 | [ ] | KPO bounded migration waves | Validation plan | CR-08, CR-10, CR-16, CR-18 | 1.3 | — |
| CR-22 | [ ] | Golden journeys and release matrix | Validation plan | CR-19, CR-20, CR-21 | 1.3 | — |
| CR-23 | [ ] | Missing module flags disabled | 2.0 plan | CR-03, CR-04, CR-22, CR-34 | 2.0 | — |
| CR-24 | [ ] | Compatibility-query deprecations | 2.0 plan | CR-02, CR-21, CR-22, CR-34 | 2.0 | — |
| CR-25 | [ ] | Auth delivery, invitation projections, and outcomes | Auth delivery plan | CR-00, CR-01 | 1.4 | — |
| CR-26 | [ ] | KPO Auth delivery/invitation migration | Auth delivery plan | CR-25 | 1.4 | — |
| CR-27 | [ ] | Atomic list/deep-map config merge and drift diagnostics | Configuration/Auth ergonomics plan | CR-00, CR-04, dependency approval | 1.4 | — |
| CR-28 | [ ] | Runtime profiles and minimal overlays | Configuration/Auth ergonomics plan | CR-03, CR-04, CR-27 | 1.4 | — |
| CR-29 | [ ] | Embedded-application Auth preset/adapter | Configuration/Auth ergonomics plan | CR-25, CR-27 | 1.4 | — |
| CR-30 | [ ] | KPO configuration/Auth simplification | Configuration/Auth ergonomics plan | CR-26, CR-28, CR-29 | 1.4 | — |
| CR-31 | [ ] | Comments metadata schemas/selectors/projections | Comments metadata/mentions plan | CR-12 | 1.4 | — |
| CR-32 | [ ] | Rich document and mention persistence | Comments metadata/mentions plan | CR-31 | 1.4 | — |
| CR-33 | [ ] | Mention registry/search/resolve/events/diagnostics | Comments metadata/mentions plan | CR-32 | 1.4 | — |
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

- [ ] Execute CR-13 and prove constant query count for one and twenty-five placements.
- [ ] Execute CR-14 and prove owner locking, revision conflicts, ordering, and authorization.
- [ ] Execute CR-15 and prove site, locale, publication, hierarchy, and result limits.
- [ ] Execute CR-16 and regenerate the TypeScript contracts.
- [ ] Migrate KPO page/editor reads after the package-focused gate passes.
- [ ] Run Content, Pages, SEO, Metafields, Translatable, Data, and integration quality gates.

**Gate M2:** A consumer can build a page editor and public child-page listing
without initiating a Pages, Content, SEO, or Metafields model query.

## Milestone 3: release 1.3 owner-slot workflows

- [ ] Execute CR-17 and prove slot resolution, authorization, staging ownership, MIME, size, replacement, and rollback behavior.
- [ ] Execute CR-18 and prove clear, copy, idempotency, after-commit effects, and safe projections.
- [ ] Execute CR-19 and CR-20 against package-owned and application-owned migrations.
- [ ] Execute CR-21 as separate reversible KPO commits for Auth, Mail, editor reads, Media, and listener context.
- [ ] Execute CR-22 and run archive plus clean-consumer rehearsals.
- [ ] Run the full suite and KPO test suites before drafting 1.3 release notes.

**Gate M3:** KPO's package-lifecycle portion of `HasSingleDocumentMedia` is
deleted, both clean fixtures pass strict audit, and no KPO migration wave adds a
consumer suppression.

## Milestone 4: release 1.4 consumer configuration, Auth delivery, and rich Comments

- [ ] Execute CR-25 and prove queued Auth delivery requires no Challenge/Invitation reload or metadata write.
- [ ] Execute CR-26 as reversible KPO listener, read, and candidacy-timing waves.
- [ ] Obtain CR-27a's internal dependency approval, execute CR-27 and CR-28, and prove atomic list overrides, value-free drift output, and KPO's unchanged seventeen-module selection.
- [ ] Execute CR-29 and CR-30; preserve KPO's host-owned Auth HTTP/UI while removing copied defaults and bridge gates.
- [ ] Execute CR-31 through CR-33; run Comments migration upgrades, database portability, generated types, cache safety, concurrency, and reconciliation gates.
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
