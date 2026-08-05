# Module Audit — The Senior Architect Protocol

You are a Senior Full-Stack Principal Engineer & Architect. This reference deepens Tier 3 of `backend-architecture/SKILL.md`. Tier 1 baseline rules and Tier 2 surface rules remain binding throughout the audit — they define what "compliant" means while you read the code. Load this reference whenever you are auditing a module holistically: architecture + business intent + pattern compliance + cross-stack parity + quality gates.

## Doctrine

A module audit is **not** a pattern scan. It is a holistic, high-integrity review that asks:

1. Does this module fulfil its business purpose, or has it drifted into technical debt?
2. Are layer ownership and side-effect chains predictable to a maintainer reading any one file?
3. Are cross-module boundaries stable, typed, and intentional?
4. Do the patterns conform to the doctrine — and where they don't, is the deviation deliberate?

**Before analyzing syntax, analyze reasoning.** Pattern compliance without intent analysis misses the most dangerous issues. A scan script can flag a missing transaction; only judgment can tell you whether that transaction would have been correct for this domain.

## Core Doctrine

1. **Understand the business before judging the code.** A Giftcards module has different invariants than a Settings module. Before scanning anything, understand what this module does, who uses it, and what workflows it supports. Code that looks "wrong" in isolation may be correct for the domain.
2. **Analyze reasoning before syntax.** Intent Drift is more dangerous than a missing type annotation.
3. **Evidence-first.** Every finding has a `file:line` reference. No guessing.
4. **No assumptions — ask first.** If a pattern looks non-standard, **do not flag it as a violation.** Flag it as `Needs clarification` and ask for business context. It may be a deliberate requirement, a temporary workaround with a tracked ticket, or a domain constraint that supersedes the general pattern.
5. **Read tests before judging actions.** Tests encode intended behaviour. If a test deliberately sets up a scenario without a transaction, without a status check, or with a non-standard flow — that is signal the pattern is intentional, not a bug.
6. **Functional parity is sacred.** "Perfect code" that breaks existing functionality is a failure.
7. **Git-aware.** Recent commits inform intent — read them before judging patterns. A pattern committed two days ago was likely deliberate.
8. **Pragmatism over perfection.** Audit for stability, not theoretical purity.
9. **Load skills on demand.** See `skill-engagement-map.md` for the full decision tree of surface skills to load when reading each directory.

## When to Use

- Auditing a module holistically (architecture + intent + compliance + quality).
- Evaluating whether a module has drifted from its business purpose.
- Reviewing a module before a major refactor to map its true state.
- Checking cross-module boundary integrity and contract stability.
- Assessing orchestration ownership and side-effect predictability.

## When NOT to Use

- Quick single-file pattern checks — run individual scan scripts directly.
- Implementing features — this protocol audits, it does not build. Build with `actions.md` / `services.md`.
- Isolated DTO or translation fixes — use the domain-specific skill.

---

## Phase 0 — Context Gathering (Mandatory)

Before analyzing anything, **understand the business first.**

### 0.1 Business Context Interview

Answer these from docs/code/git before running a single scan. If you cannot answer them from those sources alone, **ask the user.**

1. **What does this module do for the business?** (e.g. "Manages gift card lifecycle from creation through redemption and refund")
2. **Who are the primary users?** (admin staff, vendors, customers, system/automated?)
3. **What are the critical workflows?** (the 2-3 operations where failure = business value loss)
4. **Are there known intentional deviations?** (e.g. "We skip transactions on read-only actions intentionally", "The observer chain is complex because vendor onboarding requires it")
5. **What changed recently and why?** (read `git log` + commit messages for this)

Sources in priority order:

1. `Modules/{Module}/docs/` — module documentation
2. `git log --oneline -10 -- Modules/{Module}/` — recent commit messages explain intent
3. `Modules/{Module}/tests/` — test names and setups encode intended behaviour
4. **Ask the user** — if the above don't answer the question

**This context shapes every judgment you make.** A finding in a high-criticality payment module is different from the same finding in an internal admin settings module.

### 0.2 Module Inventory

Map the module's full surface area:

```bash
MODULE=Modules/{ModuleName}
ls ${MODULE}/app/{Models,Actions,Data,Http/Controllers,Services,Builders,Observers,Events,Providers}/ 2>/dev/null
ls ${MODULE}/database/migrations/
ls ${MODULE}/routes/
ls ${MODULE}/lang/{en,bg}/
ls ${MODULE}/tests/
ls resources/js/pages/admin/{module-slug}/
ls ${MODULE}/docs/ 2>/dev/null
```

### 0.3 Git Context

```bash
git log --oneline -10 -- "Modules/{ModuleName}/" "resources/js/pages/admin/{module-slug}/"
```

Read the full diff of the 2-3 most recent commits. This prevents flagging recently-deliberate patterns as violations.

### 0.4 Module Documentation

If `Modules/{Module}/docs/` exists, read everything. These docs define what "correct" means for this specific module. If no docs exist, that is itself a finding (MODERATE — undocumented module).

### 0.5 Read Test Names

```bash
rg -n "it\(|test\(" Modules/{Module}/tests/ --no-filename | head -40
```

Test names reveal **intended behaviour**. A test named `it('creates giftcard without transaction for single writes')` tells you the missing transaction is deliberate. A test named `it('allows direct status jump for admin override')` tells you the unrestricted transition is a feature, not a bug. Read test names before judging action implementations.

---

## Phase 1 — Strategic Layer

This is what separates a module audit from a pattern scan. Identify whether the module is fulfilling its business purpose or drifting into technical debt.

### 1.1 Business Logic Stress Test

For every write action (`Create*`, `Update*`, `Delete*`), apply a **two-step judgment:**

**Step A — Does this concern apply to this action?** Not every action needs every safeguard. A simple single-field update doesn't need `DB::transaction()`. A status field that legitimately allows any-to-any transitions doesn't need validation. Use the business context from Phase 0.1 to judge applicability.

**Step B — If it applies, is it implemented?** Only flag as a finding if Step A is "yes" and Step B is "no."

| Concern | When It Applies | Failure Mode If Missing |
| ------- | --------------- | ----------------------- |
| `DB::transaction()` wrapping | Action performs 2+ related writes that must succeed or fail together | **Data Corruption** — partial state persisted |
| Status transition validation | Entity has a state machine where not all transitions are legal | **Impossible State** — entity reaches invalid state |
| Multi-step workflow guards | Business process has required sequence (e.g. create → approve → fulfil) | **Bypass Risk** — steps can be skipped |
| Nested resource ownership | Action operates on child resource (e.g. variant of product) | **Authorization Gap** — child accessed without parent check |
| Side-effects outside transaction | Action dispatches jobs/mail/events AND wraps writes in transaction | **Side-Effect Leak** — job runs, transaction rolls back |
| Refreshed model return | Downstream code (controller/frontend) renders data from the returned model | **Stale Data** — frontend shows pre-write state |

**When a concern doesn't apply:** Document it as "N/A — {reason}" in your analysis, not as a finding. Example: *"Transaction: N/A — single atomic `update()` call, no multi-write risk."*

**When you're unsure if it applies:** Flag as `QUESTION` with your reasoning. Do not assume it's a violation.

### 1.2 Intent Drift Detection

Compare what the module docs say vs what the code does:

1. Read each action's `execute()` for actual behaviour.
2. Read `Modules/{Module}/docs/*.md` for stated behaviour.
3. Flag divergence as **"Intent Drift"**.

**Intent Drift markers:**

- Action names suggest one purpose, implementation does another.
- Dead code paths never removed after a pivot.
- Comments describing behaviour that no longer matches.
- Code does X but docs/tests say Y.

If drift is detected: **flag it, do not auto-fix.** Ask for business context.

### 1.3 Architectural Chain of Command

The Tier 1 layer hierarchy from `backend-architecture/SKILL.md` is the law. Any cross-layer leakage is a critical failure.

| Layer | Role | Identity |
| ----- | ---- | -------- |
| **Controller** | Strictly HTTP orchestration. Calls Actions. | The "Router" — receives, delegates, responds. |
| **Action** | The **verb** of the module. Use-case orchestration. | Combines Services and Builders. Receives/returns Spatie Data objects. Single `execute()` method. |
| **Service** | **Standalone, focused, strictly typed.** | One cohesive domain capability. Stateless. Does one thing perfectly. |
| **Model** | **Lightweight persistence shape.** | Relations, casts, scopes, traits. Zero business logic. Zero workflows. |
| **Builder** | Query composition, typed, composable, model-specific. | Extracted from models when query complexity grows. Specialized builders for distinct query domains. |

**Cross-layer leakage detection:**

```bash
# Controllers doing data work (CRITICAL)
rg -n "DB::|->save\(\)|::create\(|->forceDelete\(" Modules/{Module}/app/Http/Controllers/

# Models containing business logic (CRITICAL)
rg -n "DB::transaction|dispatch\(|event\(|Mail::|Notification::" Modules/{Module}/app/Models/

# Actions doing HTTP work (HIGH)
rg -n "redirect\(|back\(\)|->with\(|flash\(|session\(\)|request\(\)" Modules/{Module}/app/Actions/

# Services with HTTP concerns (HIGH)
rg -n "request\(\)|redirect\(|flash\(|session\(\)|Inertia::" Modules/{Module}/app/Services/

# Service-locator leakage anywhere in the module (HIGH)
rg -n "app\(.+::class\)|App::make|App::makeWith|Container::resolve|resolve\(" Modules/{Module}/app/
```

### 1.4 Models & Specialized Query Builders

Models MUST remain lightweight Data Maps. All query logic MUST be extracted into dedicated Builder classes.

**Model Integrity Checks:**

```bash
# Models missing TABLE constant
rg -n "class.*extends Model" Modules/{Module}/app/Models/
rg -n "const TABLE" Modules/{Module}/app/Models/

# Models with too many scopes (builder extraction candidate)
for model in Modules/{Module}/app/Models/*.php; do
  count=$(rg -c "#\[Scope\]|function scope[A-Z]" "$model" 2>/dev/null || echo 0)
  has_builder=$(rg -c "newEloquentBuilder" "$model" 2>/dev/null || echo 0)
  if [ "$count" -ge 5 ] && [ "$has_builder" -eq 0 ]; then
    echo "BUILDER CANDIDATE: $model ($count scopes, no custom builder)"
  fi
done
```

**Specialized Builders:** When a model serves multiple query domains (e.g. reporting vs filtering vs analytics), extract domain-specific builders (`ReportingBuilder`, `AnalyticsBuilder`). Identify logic "leaking" into general builders that belongs in a specialized one.

### 1.5 Orchestration & Ownership

**Deep Nesting** — the anti-pattern where Service calls Service calls Service — destroys traceability. Use an Action as the glue to maintain clear ownership.

**Side-Effect Audit — identify hidden logic:**

```bash
# Observers (hidden side effects triggered by model events)
rg -n "class.*Observer" Modules/{Module}/app/Observers/

# Events emitted from this module
rg -n "event\(|Event::dispatch|dispatch\(new" Modules/{Module}/app/

# Listeners registered in this module's providers
rg -n "Event::listen|\$listen" Modules/{Module}/app/Providers/
```

For each observer/event/listener, map the chain:

```
Source → Trigger → Side Effect → Predictable? → Cross-Module?
```

Flag any chain where an Action's execution triggers **unpredictable cross-module reactions**. An engineer reading an action should be able to predict everything that happens.

### 1.6 Cross-Module Boundary Integrity

Is this module taking on responsibilities that belong elsewhere?

```bash
# What this module imports from other modules
rg -n "use Modules\\\\" Modules/{Module}/app/ | grep -v "use Modules\\\\{Module}\\\\"

# What other modules import from this module (reverse dependencies)
rg -n "use Modules\\\\{Module}\\\\" Modules/ --glob="!Modules/{Module}/*"
```

| Dependency Type | Risk | Example |
| --------------- | ---- | ------- |
| Model read (relation) | LOW | `use Modules\Vendors\Models\Vendor` for `belongsTo` |
| Model write | **CRITICAL** — ownership violation | Writing to another module's table |
| Action/Service call | MEDIUM | Using another module's action |
| Event consumption | LOW — loose coupling | Listening to another module's event |
| Direct `DB::table()` | **CRITICAL** — bypasses module API | Raw query on foreign table |

**Parity Problems:** Check if Module A expects data that Module B no longer provides. Are cross-module calls typed and stable? Has the called class's signature changed in recent commits?

---

## Phase 2 — Pattern Compliance

Delegate mechanical scanning to the built-in scan scripts:

```bash
MODULE_NAME="{ModuleName}"
MODULE_SLUG="{module-slug}"

scripts/scan_backend_module.sh "$MODULE_NAME"
scripts/scan_frontend_module.sh "$MODULE_SLUG"
scripts/scan_cross_stack_parity.sh "$MODULE_NAME" "$MODULE_SLUG"
```

Each scan match is a **potential** violation, not a confirmed one. Before flagging:

1. **Read the surrounding code** — a `DB::` in a controller might be a read-only query for a dropdown, not a write violation.
2. **Check if tests cover the behaviour** — if a test deliberately sets up this pattern, it's likely intentional.
3. **Check git blame** — if it was introduced in a recent audit remediation commit, it was deliberate.
4. **Consider the domain** — an observer chain in a Vendors module (complex onboarding) is different from one in a Settings module.

See `pattern-compliance.md` for the full scan catalog and what each scan validates.

---

## Phase 3 — Spatie Data & Full-Stack Parity

### 3.1 DTO Contextual Separation

DTOs MUST be split by context. No God Objects.

| Namespace | Direction | Purpose |
| --------- | --------- | ------- |
| `Data/Mutations/` | FE → BE (input) | Create/Update payloads with validation |
| `Data/Display/` | BE → FE (output) | Pre-computed display state, flags, labels |
| `Data/` (root) | Shared/internal | Resource DTOs, query DTOs |

### 3.2 Type Parity

Absolute parity between PHP Data objects and Frontend TypeScript interfaces:

```bash
# Find DTOs with TypeScript decorator
rg -n "#\[TypeScript\]" Modules/{Module}/app/Data/

# Verify generated types exist
rg -n "namespace.*{Module}" resources/js/types/generated.types.d.ts
```

Every `#[TypeScript]` DTO MUST have a matching TS interface. Every property MUST match in name, type, and nullability.

### 3.3 Display State Evaluation (conditional)

If the module has a show page with 3+ frontend hooks computing derived state, evaluate for Display State migration. See `display-state-evaluation.md`.

---

## Phase 4 — Quality Gates

```bash
# PHPStan — level 4 first (required gate), level 5 after level 4 is clean
./vendor/bin/phpstan analyse Modules/{Module}/app --level=4
./vendor/bin/phpstan analyse Modules/{Module}/app --level=5

# Module tests
php artisan test Modules/{Module}/tests --compact

# Frontend types
npx tsc --noEmit 2>&1 | grep "pages/admin/{module-slug}"

# Formatting
vendor/bin/pint --dirty --format agent
```

**Test Coverage Gap Check:** For each action, verify a corresponding test exists. More actions than test files = potential gap.

---

## Phase 5 — Produce Audit Report

See `report-template.md` for the full template.

### Finding Format

```
ID: MA-{NNN}
Phase: STRATEGIC | COMPLIANCE | PARITY | QUALITY
Title: {concise description}
Severity: CRITICAL | HIGH | MODERATE | LOW | QUESTION
Category: Intent Drift | Impossible State | Layer Violation | Boundary Leak |
          Side-Effect Chain | Parity Problem | Deep Nesting | Dead Code |
          Pattern Violation | Missing Test | God Object | Leaky Abstraction |
          Deliberate Deviation?
File: {path}:{line}
What: {exact violation with evidence}
Should be: {correct pattern — or "Unknown — depends on business intent"}
Fix: {specific instruction — or "Needs clarification: {why this might be intentional}"}
```

**The `QUESTION` severity and `Deliberate Deviation?` category exist for a reason.** Use them when:

- A pattern breaks the standard but tests cover it intentionally.
- A recent commit introduced it with a clear commit message.
- The module docs describe this behaviour as intended.
- The business domain might justify the deviation (e.g. admin override workflows).

QUESTION-severity findings are presented to the user for judgment, not counted as violations in the health assessment.

### Severity Scale

| Level | Definition | Examples |
| ----- | ---------- | -------- |
| CRITICAL | Runtime crash, data corruption, security hole, impossible state | Missing transaction on multi-write, missing auth check, foreign table write |
| HIGH | User-visible defect or architectural violation with cascading risk | Layer leakage, Parity Problem, unstable cross-module contract |
| MODERATE | Pattern deviation without immediate user impact | Missing builder, service naming, missing TypeScript decorator |
| LOW | Cosmetic or forward-looking | Minor naming, dead code without harm |
| QUESTION | Looks non-standard but might be intentional — needs human judgment | Single-write without transaction, unrestricted status transition, observer chain for complex workflow |

**QUESTION is not a cop-out.** It means: "I found evidence this might be deliberate (test coverage, recent commit, domain logic) but cannot confirm without business context." Always include your reasoning.

---

## Phase 6 — Fix Plan

### Execution Order

1. **CRITICAL** — Data integrity, security, impossible states.
2. **HIGH / STRATEGIC** — Layer violations, boundary leaks, Parity Problems.
3. **HIGH / COMPLIANCE** — Translation gaps, DTO violations.
4. **MODERATE** — Builder extraction, service cohesion, naming.
5. **LOW** — Dead code, cosmetic cleanup.

### Grouping Rules

- **Architectural fixes** — related layer violations fixed together.
- **Translation fixes** — EN PHP + BG PHP + TS types + frontend `t()` calls (atomic unit).
- **DTO fixes** — PHP DTO + `artisan typescript:transform` + update frontend consumption.
- **Cross-module fixes** — coordinate with the other module before changing contracts.
- **Never mix** structural rewrites with cosmetic fixes in the same pass.

### Verification Checklist

- [ ] `./vendor/bin/phpstan analyse Modules/{Module}/app --level=4` — 0 errors.
- [ ] `php artisan test Modules/{Module}/tests` — all pass.
- [ ] EN/BG translation key counts match per file.
- [ ] `vendor/bin/pint --dirty --format agent` — clean.
- [ ] `npx tsc --noEmit` — 0 new errors in module.
- [ ] `php artisan typescript:transform` — if DTOs changed.
- [ ] No new cross-layer violations introduced.
- [ ] No new cross-module dependencies without typed contracts.
- [ ] Side-effect chains documented and predictable.

---

## Anti-Patterns

1. **Don't treat scan output as truth.** A grep match is a signal, not a verdict. Read the code, understand the domain, check the tests.
2. **Don't auto-fix non-standard patterns.** Ask first. It may be a deliberate vendor/staff workflow, a temporary workaround with a tracked ticket, or a domain constraint.
3. **Don't skip Phase 0.1 (Business Context).** Without understanding what the module does and who uses it, you will misjudge every finding.
4. **Don't skip Phase 1.** Pattern compliance without strategic analysis is half an audit.
5. **Don't ignore git context.** A recently-committed pattern was likely deliberate.
6. **Don't ignore tests.** Tests cover patterns intentionally; that is evidence of deliberate design.
7. **Don't audit outside the target module.** Document cross-module issues but fix within scope.
8. **Don't suppress PHPStan errors.** Fix the types.
9. **Don't mix fix categories** in the same implementation pass.
10. **Don't create new abstraction layers** as part of an audit fix.

## Quick Start

```bash
scripts/run_module_audit.sh {ModuleName} {module-slug}
```

Then follow Phases 0-6, using the script output as input for analysis.

## Cross-References

- `backend-architecture/SKILL.md` — Tier 1 baseline + Tier 2 surface rules; every "should be" answer in your findings comes from there.
- `skill-engagement-map.md` — surface-to-skill routing while reading each directory during the audit.
- `pattern-compliance.md` — full scan catalog and interpretation rules for Phase 2.
- `report-template.md` — finding format and report shape for Phase 5.
- `display-state-evaluation.md` — Phase 3.3 conditional evaluation protocol.
- `actions.md`, `services.md` — what "compliant" looks like for the touched surface.
