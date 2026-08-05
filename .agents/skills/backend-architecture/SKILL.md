---
name: backend-architecture
description: 'MANDATORY entry point for every PHP task in Modules/** or app/**. Use whenever writing, editing, refactoring, splitting, or auditing backend code. Establishes binding rules on strict types, import hygiene, class metadata, PHPDoc completeness, PSR-12 formatting, layer ownership (Controller / Action / Service / Model / DTO), action contracts, service decomposition, and module-level architectural integrity. Always load before touching any .php file under Modules/** or app/**. References load on demand for surface-specific depth.'
metadata:
    author: giftcometrue
    version: '1.0'
    supersedes:
        - backend-code-baseline
        - backend-actions
        - backend-services-guide
        - module-audit
---

# Backend Architecture — The Binding Doctrine

You are a senior backend architect responsible for upholding this repository's PHP 8.4 / Laravel 12 standards without compromise. This skill is the canonical entry point for **every** backend task in `Modules/**` or `app/**`. It is not a guide. It is the doctrine. It supersedes the four legacy skills `backend-code-baseline`, `backend-actions`, `backend-services-guide`, and `module-audit` — their content has been merged here and their descriptions point back to this skill.

## The Iron Law

**Every backend change in this repository MUST pass through this doctrine.** There is no shortcut, no "small fix" exemption, no "I already know the rules" exemption. The doctrine is the floor — surface-specific references (loaded on demand below) raise the ceiling.

**Violating the letter of these rules is violating the spirit of these rules.** Loophole-hunting is a violation in itself.

## When This Skill MUST Load

- Writing or editing **any** `.php` file in `Modules/**/app/**`, `Modules/**/database/**`, `app/**`, or `tests/**` (for backend tests).
- Refactoring or splitting an existing PHP class.
- Auditing a module holistically (architecture + intent + compliance + quality).
- Reviewing a pull request that touches backend code.
- Fixing PHPStan, Pint, or Pest failures on backend scope.
- Resolving any "ownership", "boundary", or "this method is too big" review comment.

## When You MUST NOT Skip It

| Excuse | Reality |
| ------ | ------- |
| "It is just a one-liner" | One-liners drift from the doctrine fastest. Load anyway. |
| "I loaded a surface skill already" | The doctrine evolves and surface skills only refine it. Load anyway. |
| "The user asked for X, not the doctrine" | Users expect X done correctly. The doctrine defines correctly. |
| "I remember the rules" | Memory drifts. Read the current version. |
| "The file is already broken, my fix will not make it worse" | Broken files are where the doctrine matters most. Load anyway. |
| "It is a private/internal helper, no one will see it" | Future maintainers read every file. Load anyway. |

---

## Mode Router — Load the Right Reference

Identify your mode and open the corresponding reference *in addition* to the inline baseline below. Never skip the baseline — references add depth, they do not replace foundation.

| You Are About To … | Load Reference | What It Binds You To |
| ------------------ | -------------- | -------------------- |
| Write or edit an **Action** class in `app/Actions/**` | `references/actions.md` | Typed `execute()` contracts, transaction ownership, `Model\|string` resolution, post-commit side-effect rules, DTO-based writes. |
| Write, split, or audit a **Service** class in `app/Services/**` | `references/services.md` | Ownership contract, action-vs-service decision rule, transaction-ownership rule, naming rules, KISS/DRY/SOC simplicity rules, splitting workflow. |
| Design, add, refactor, or audit a module **Access** boundary in `Modules/**/app/Access/**` or a cross-module public module surface | `backend-module-access` | Access class naming, dependency-pressure search, composition rules, DI usage, facade-vs-static-facade boundary, and validation. |
| Audit a module holistically | `references/audit.md` (the strategic protocol) — which itself loads `skill-engagement-map.md`, `pattern-compliance.md`, and `report-template.md` as needed | Phase 0–6 audit protocol: business context, strategic analysis, pattern compliance, parity, quality gates, fix planning. |
| Decide whether to migrate show-page hooks to Display State | `references/display-state-evaluation.md` | Evaluation protocol, scoring matrix, Display DTO architecture. |
| Run scan scripts during an audit | `scripts/run_module_audit.sh`, `scripts/scan_backend_module.sh`, `scripts/scan_frontend_module.sh`, `scripts/scan_cross_stack_parity.sh` | Mechanical pattern scanning to feed strategic analysis. |

For surface-specific skills not absorbed here (`backend-module-access`, `backend-models`, `backend-controllers`, `backend-migrations`, `backend-spatie-data`, `backend-api-filters`, `backend-activity`, `backend-quality-assurance`, `backend-mailing`, `backend-media`, `backend-metrics`, `backend-suggestions-api`, `backend-auth-stack`, `php-code-style`), defer to those skills in addition to this one.

---

## Tier 1 — Non-Negotiable Baseline (apply to every PHP file you touch)

These rules apply **before** any surface-specific reference. They are the floor.

### 1.1 File and Class Hygiene

1. Every file MUST begin with:
   ```php
   <?php

   declare(strict_types=1);
   ```
2. Every class, trait, interface, and enum MUST carry a **class-level PHPDoc** describing what it is and what it stands for in the module's domain. No exceptions.
3. Every Eloquent model MUST list **all persisted attributes** as `@property` lines in its class PHPDoc — typed and briefly described.
4. Every class body MUST follow the canonical declaration order — `use` traits → constants → static properties → instance properties → constructor → public methods → protected methods → private methods. The body is the file's table of contents.
5. Every public method MUST carry a PHPDoc with one declarative sentence describing what it does, plus `@param` / `@return` / `@throws` as applicable. No `@example` blocks. No usage samples. PHPDocs describe contract, not tutorial.
6. Every `array` parameter and return type MUST carry an array-shape annotation (`array<string, mixed>`, `array<int, string>`, `list<string>`, `array{key: string}`) sufficient for PHPStan level 4 and level 5.
7. PSR-12 formatting at all times. Run `vendor/bin/pint --dirty --format agent` before committing.
8. The full PHPDoc / PSR-12 / class metadata contract lives in the `php-code-style` skill — load it whenever any of the above is in question.

### 1.2 Import Hygiene

1. Every external class reference MUST be imported via `use` at the top of the file. **Never inline FQCNs** in code or PHPDoc.
2. Imports MUST be alphabetized and grouped per PSR-12. No grouped `use { … }` blocks.
3. Unused imports MUST be removed in the same change that orphans them.

### 1.3 Layer Ownership (the Architectural Chain of Command)

The repository enforces a strict hierarchy. **Cross-layer leakage is a CRITICAL failure.**

| Layer | Role | Identity |
| ----- | ---- | -------- |
| **Controller** | HTTP orchestration only. Calls Actions. Builds Inertia pages. | The "Router" — receives, delegates, responds. |
| **Action** | The **verb** of the module. Use-case orchestration. | Combines Services and Builders. Receives/returns Spatie Data objects. Single typed `execute()`. |
| **Service** | **Standalone, focused, strictly typed.** One cohesive domain capability. | Stateless. Constructor-injected. Does one thing perfectly. |
| **Model** | **Lightweight persistence shape.** Relations, casts, scopes, traits, light helpers. | Zero workflows. Zero business logic. |
| **Builder** | Query composition, typed and composable. | Extracted from models when query complexity grows. Specialized builders for distinct query domains. |
| **DTO (Spatie Data)** | Validation, mapping, TS contract generation. | `Data/Mutations/` for inbound, `Data/Display/` for outbound, root `Data/` for shared. |

**Reusable capability traits win over new dependency layers.** When a module owns a reusable opt-in capability through a documented model trait and contract, host modules MUST integrate through that trait/contract and the owner module's existing actions, routes, policies, and DTOs before inventing new services. Examples include Comments via `HasComments` + `AcceptsComments`, Media via model media traits, and similar module-owned relationship capability traits. The host model may expose relations, casts, scopes, light permission predicates, and timeline/source composition required by the trait; it MUST NOT recreate the owner module's write workflow in a local service.

Before implementing reusable cross-module functionality, inspect at least one existing consumer module that already uses the capability and mirror its integration surface unless there is a concrete business reason not to. For example, if Bookings posts comments by importing `HasComments`, implementing `AcceptsComments`, and using the shared Comments action/route flow, Vendors or Vouchers should follow that pattern instead of introducing a separate Comments service dependency.

When introducing or changing public cross-module module surfaces, load `backend-module-access` and prefer `Modules/{Module}/app/Access/{ModuleName}.php` classes for approved owner-module behavior while the public surface is small. When one owner module exposes distinct caller-specific capability families, split to focused classes such as `Modules\Vouchers\Access\Bookings` or `Modules\Vouchers\Access\Protocols` so each caller imports only its approved public surface. Caller-scoped Access imports MUST use owner+caller aliases such as `VouchersBookings` or `VouchersProtocols`, with matching lower-camel injected names such as `$vouchersBookings`; never alias a caller-scoped Access class to only the owner name. These classes are normal DI-resolved classes, not Laravel static facades, not singleton defaults, and not public-property registries. Access may return the owner module's existing shaped arrays, Spatie Data objects, enums, models, value objects, and snapshots when those are already stable business payloads; do not move Data into Access just for architectural purity.

**Cross-layer red lines (each is a CRITICAL violation):**

- Controllers MUST NOT contain `DB::`, `->save()`, `::create()`, `->forceDelete()`, or any direct persistence call.
- Models MUST NOT contain `DB::transaction()`, `dispatch()`, `event()`, `Mail::`, or `Notification::`.
- Actions MUST NOT contain `redirect()`, `back()`, `->with()`, `flash()`, `session()`, `request()`, or `Inertia::`.
- Services MUST NOT contain `request()`, `redirect()`, `flash()`, `session()`, `Inertia::`, or `trans()` for user-facing UI copy.
- Service-locator usage for dependency resolution is forbidden inside any module/app layer. This includes — without exception — `app(SomeClass::class)`, `App::make(SomeClass::class)`, `App::makeWith(...)`, `Container::resolve(...)`, and `resolve(SomeClass::class)`. Dependencies MUST be declared in the constructor with property promotion and resolved by the container at construction time. Environment / config helpers belong on their proper facades (`App::environment()`, `Config::get()`), not on `app()`.
- Module A MUST NOT write to Module B's tables — neither via Module B's models nor via raw `DB::table()`. Reads through relationships are allowed; cross-module behavior and writes go through Module B's Access boundary, which delegates to Module B's actions/services.

### 1.4 Translations, Copy, and Authenticated Actor

1. Backend `trans()` is reserved for **server-rendered/server-only** copy — emails, PDFs, validation messages, backend activity headlines.
2. React/Inertia UI copy belongs in `locales/{en,bg}/*.json` and `useI18n()` on the frontend. **Never** hardcode UI strings in PHP responses.
3. Authenticated actor access MUST go through `auth()->id()` and `auth()->user()`. Do not import `Auth` facades for ad-hoc lookups; do not pull the user out of the request manually.

### 1.5 Activity Logging

1. For manual backend activity logging, use `\App\Lib\Activity\Facades\ActivityLog` (`recordUser`, `recordSystemAudit`, or `entry` during migration only). Load the `backend-activity` skill for headline rules and properties structure.
2. Service-locator usage like `app(\App\Lib\Activity\Support\ActivityRecorder::class)` is forbidden in module/app code.

### 1.6 Dead Code and Catch-All Handling

1. No commented-out code blocks. Delete or implement.
2. No broad `catch (Throwable $e) { /* ignore */ }` blocks. Catches MUST be specific and acted upon.
3. No dead methods, dead imports, or dead helpers left after a refactor.

---

## Tier 2 — Surface Quick Rules

These are the binding summary rules per surface. For deep doctrine, open the matching reference. The references add depth — they never relax these rules.

### 2.1 Controllers — `Modules/**/app/Http/Controllers/**`

1. Extend `app/Lib/Inertia/InertiaController.php`.
2. Implement `pageOptions()` declaring the module key and translation namespace.
3. Use `registerPaginatedPage()` for paginator payloads; `registerDataPage()` for keyed resource payloads.
4. Wrap every mutation endpoint in `$this->attempt(...)`.
5. Redirect via `$this->redirect(...)` or `$this->redirectBack(...)` helpers — never raw `redirect()` calls.
6. Flash messages MUST use module translation keys; never hardcoded English.
7. INDEX and DETAIL page composition include the `messages` translation subset by default. Maintain this unless intentionally changing the base controller.

Full doctrine: `backend-controllers` skill.

### 2.2 Actions — `Modules/**/app/Actions/**` (full doctrine: `references/actions.md`)

1. **One action = one use-case entrypoint.** A single typed `execute(...)` method, period.
2. `execute()` signatures are **fully typed**; concrete return types only — `Model`, `LengthAwarePaginator`, `Collection`, `bool`. **Never** `mixed`, **never** ad-hoc arrays as return values.
3. Mutations MUST be wrapped in `DB::transaction(...)`.
4. Side effects (jobs, mail, events, notifications) MUST run **after commit** — `DB::afterCommit(...)` or queue `->afterCommit()`.
5. Write actions MUST consume DTOs and persist via `toModelFiltered()`. No raw request arrays into write paths.
6. Persistent entity parameters MUST accept `Model|string` and resolve internally (`instanceof` + `findOrFail()`); nested actions MUST verify parent-child ownership and fail fast on mismatch.
7. Write actions MUST return the refreshed model (or paginator/collection) when callers render data from the return value. Stale return = downstream rendering bug.
8. Action-to-action chains are forbidden unless explicitly approved orchestration.
9. Activity logging MUST cover meaningful state changes — see `backend-activity`.

### 2.3 Services — `Modules/**/app/Services/**` (full doctrine: `references/services.md`)

1. Services MUST be stateless and constructor-injected.
2. One service = one clear domain capability area. Split by business capability, not technical trivia.
3. Service APIs MUST be small, explicit, and typed. No vague `mixed` payloads.
4. Services MUST keep HTTP concerns, redirects, flash messages, and UI translation copy out of their API.
5. **Actions own transactions by default.** Service-owned transactions are a rare, documented exception — allowed only when the capability itself is the stable reusable write boundary.
6. **Decomposition decision rule:** keep one workflow in a single action when it is readable, cohesive, and small. Split into focused services only when (a) the workflow contains 2+ meaningful domain capabilities, or (b) keeping orchestration in the action would require coordinating **more than 4 services**, or (c) a stable internal capability family genuinely needs one thin façade. **Do not split for pattern aesthetics.**
7. **Arrays-For-Now Rule.** Shaped arrays are tolerated at service boundaries during the current migration. Where used, they MUST be (a) narrow in scope, (b) documented with PHPDoc `array<...>` shapes, and (c) local to the seam, not propagated across many service boundaries. Prefer DTOs or result objects whenever the boundary is heavy or reused.
8. Naming MUST communicate domain outcome — prefer `*Resolver`, `*Syncer`, `*Writer`, `*Validator`, `*Guard`, `*Catalog`, `*Updater`, `*Changer`. `*Service` is the generic default; `*Processor` is allowed when it truly describes a processing boundary. **Never** `Helper`, `Utils`, `Manager` for new code.
9. KISS, DRY (when the repetition is the same domain behavior), and SOC are mandatory. If a split makes the workflow harder to read, the split is wrong.

---

## Tier 3 — Module Audit Quick Trigger

When you are auditing a module holistically (not building a single feature), open these in order:

1. `references/audit.md` — the strategic protocol (Phases 0–6).
2. `references/skill-engagement-map.md` — to know which surface skill to load when reading each directory.
3. `references/pattern-compliance.md` — for mechanical scan interpretation.
4. `references/report-template.md` — for finding format and report shape.
5. `references/display-state-evaluation.md` — only if the module has a show page with 3+ derived-state hooks.

Run `scripts/run_module_audit.sh {ModuleName} {module-slug}` to produce the scan inputs. **Scan output is a signal, not a verdict** — see `references/audit.md` for interpretation rules.

---

## Execution Workflow (any backend task)

1. **Identify the target module and surfaces** — `Actions`, `Controllers`, `Data`, `Models`, `Services`, `Migrations`, `Traits`, etc.
2. **Read sibling files** in the touched directory to confirm in-tree patterns and any module-specific deviations.
3. **Load the matching reference(s)** from the mode router above.
4. **Apply Tier 1 baseline first** (file hygiene, imports, layer ownership) — before any functional edit.
5. **Apply the reference's surface-specific rules** to the change.
6. **Implement minimal behavior drift** — do not bundle refactors with feature work unless explicitly scoped.
7. **Format, analyze, test** — `vendor/bin/pint --dirty --format agent`, then PHPStan level 4 on the touched module, then level 5, then module-scoped Pest.
8. **Record task progress immediately** after each completed subtask. Never batch.

## Red Flags — STOP and Reset

If you catch yourself doing any of the following, stop, reload this skill, and start the task again:

- Editing a `.php` file before reading the class PHPDoc and method PHPDocs that surround your edit.
- Adding business logic to a controller "just to ship faster".
- Adding `dispatch()` or `event()` directly inside a model.
- Catching `Throwable` and swallowing it.
- Calling `app(SomeClass::class)` to bypass injection.
- Returning a raw array from a write action.
- Writing an `array` parameter without a `@param array<...>` shape annotation.
- Skipping the class PHPDoc because "the class name is obvious".
- Putting HTTP concerns (`redirect`, `flash`, `request`) inside a service.
- Splitting one workflow into Controller → Action → Service → Helper → Utility forwarding chains where each layer only forwards arguments.
- Writing to another module's table from inside this module's actions, services, or models — even "just one column" is a CRITICAL ownership violation.
- Propagating the same untyped `array<string, mixed>` payload across three or more service boundaries. The Arrays-For-Now Rule allows local shaped arrays — not module-wide pipelines.
- Pulling the authenticated actor out of the request manually instead of using `auth()->id()` / `auth()->user()`.

**All of these mean: revert and re-enter the doctrine.**

## Completion Gate (every backend change)

- [ ] Every touched file starts with `<?php\n\ndeclare(strict_types=1);`.
- [ ] Every touched class has a class-level PHPDoc describing what it is and what it stands for.
- [ ] Every touched method has a complete PHPDoc with single-sentence intent line, `@param`, `@return`, `@throws` as applicable, and no `@example`.
- [ ] Every `array` slot has an array-shape annotation.
- [ ] Layer ownership is intact — no cross-layer leakage detected by the scans in `references/pattern-compliance.md`.
- [ ] DTO contract is stable; if changed, `php artisan typescript:transform` ran and the frontend consumes the new shape.
- [ ] Transactions sit at the correct boundary (action) and side effects run after commit.
- [ ] Pint is clean on touched scope.
- [ ] PHPStan level 4 is clean on touched scope; level 5 is clean or deferral is explicitly documented.
- [ ] Module-scoped Pest tests pass; new behavior has new tests.
- [ ] No commented-out code, no broad catches, no dead imports.

## Useful Commands

```bash
# Inventory the touched module
rg --files Modules/<Module>/app | rg '/Actions/|/Data/|/Http/Controllers/|/Models/|/Services/|/Traits/'

# Confirm strict types and imports across the touched scope
rg -n "declare\(strict_types=1\)|^use " Modules/<Module>/app

# Detect activity-logger service-locator abuse
rg -n "app\(\\\App\\\Lib\\\Activity\\\Support\\\ActivityRecorder::class\)|activity\(" Modules/<Module>/app app

# Cross-layer leakage scans
rg -n "DB::|->save\(\)|::create\(|->forceDelete\(" Modules/<Module>/app/Http/Controllers/
rg -n "DB::transaction|dispatch\(|event\(|Mail::|Notification::" Modules/<Module>/app/Models/
rg -n "redirect\(|back\(\)|flash\(|session\(\)|request\(\)" Modules/<Module>/app/Actions/
rg -n "request\(\)|redirect\(|flash\(|session\(\)|Inertia::" Modules/<Module>/app/Services/

# Format, analyze, test
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse Modules/<Module> -c phpstan.neon --no-progress
vendor/bin/phpstan analyse Modules/<Module> -c phpstan.neon --level=5 --no-progress
php artisan test Modules/<Module>/tests/Feature --compact
```

## Cross-References

- `php-code-style` — PHPDoc / class metadata / PSR-12 contract referenced throughout Tier 1.
- `backend-module-access` — module Access boundary convention for cross-module public capabilities, dependency search, composition, usage, and validation.
- `backend-models`, `backend-controllers`, `backend-migrations`, `backend-spatie-data`, `backend-api-filters`, `backend-activity`, `backend-quality-assurance`, `backend-mailing`, `backend-media`, `backend-metrics`, `backend-suggestions-api`, `backend-auth-stack` — surface-specific skills not absorbed here. Load alongside this skill when touching that surface.
- `pest-testing` — Pest-specific test patterns. Examples belong here, not in source PHPDocs.
- `translations`, `frontend-i18n-migration` — translation runtime architecture.
- `fullstack-money`, `fullstack-state-display` — cross-stack contracts referenced by Phase 3 of the audit.
