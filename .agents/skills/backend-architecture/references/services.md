# Services — Full Doctrine

You are a backend service architect. This reference deepens Tier 2.3 of `backend-architecture/SKILL.md`. Tier 2.3 rules are binding; everything below adds enforcement detail. **Nothing here relaxes the Tier 1 baseline, the cross-layer red lines, or the action-vs-service ownership rule.** Load this reference whenever the touched scope includes `Modules/**/app/Services/**`, or when an action is becoming a god-method and needs decomposition.

## Doctrine

A Service is a **standalone, focused, strictly typed domain capability**. It owns one cohesive piece of behaviour — resolving, syncing, writing, validating, guarding — and nothing else. It is **stateless**, **constructor-injected**, and **HTTP-blind**. It does not know about controllers, requests, redirects, flash messages, or UI translations.

The repository's target is *not* abstract architecture for its own sake. The target is smaller, clearer domain capabilities with visible ownership and minimal indirection. KISS, DRY (when the repetition is the same behaviour), and SOC are mandatory. Splitting for aesthetics is forbidden.

## Binding Rules

### Ownership Contract

- **Controllers** own HTTP orchestration only.
- **Actions** own use-case orchestration and the transaction boundary.
- **Services** own focused, reusable domain capabilities.
- **Models** own persistence shape (relations, casts, scopes, traits, light helpers). Models MUST NOT own workflows.

If a service is parsing requests, building flash copy, or embedding HTTP concerns — ownership has drifted. If an action is mixing multiple domain capabilities in one body — ownership has drifted. Fix the drift before adding code.

### Action vs Service Decision

Apply this decision rule before splitting anything:

1. **Keep one workflow in one action** when the use-case is readable, cohesive, and small.
2. **Split into focused services** when (a) the workflow contains two or more meaningful, separable domain capabilities, AND (b) each capability is meaningful on its own — reusable, testable, or invariant-enforcing.
3. **Do not split** when the result would be a Controller → Action → Service → Helper → Utility forwarding chain. If each layer only forwards arguments, the split is wrong.
4. **Service-level orchestration** (a thin internal façade that coordinates focused services) is allowed only when keeping orchestration in the action would require coordinating **more than 4 services**, OR a stable internal capability family genuinely needs a single façade. The façade MUST have a domain reason to exist, not a structural one.

**Stop signs:**
- "It feels cleaner to extract" — feelings are not invariants. Show the second meaningful capability.
- "We might reuse it later" — speculative reuse is not justification. Wait for the second caller.
- "The class is getting long" — long ≠ wrong. Mixed responsibilities ≠ long.

### Transaction Ownership

- **Actions own transactions by default.** The transaction boundary lives at the use-case entrypoint where it is visible to readers.
- **Focused capability services MUST stay transaction-agnostic** — they perform writes as part of a larger transaction owned by the action.
- **Service-owned transactions** are a rare, documented exception. Allowed only when the capability itself is the stable reusable write boundary AND the service cannot function correctly without owning that transaction. The class PHPDoc MUST document this exception explicitly.

Never hide a write-boundary policy deep inside a small reusable service by default.

### Service Design

- Services MUST be **stateless**. No mutable instance state set during one call and read during another. Every public method is self-contained given its parameters.
- Services MUST be **constructor-injected** — `private readonly` promoted properties. No `app(...)`, `App::make(...)`, `App::makeWith(...)`, `Container::resolve(...)`, `resolve(...)`. The Tier 1 ban list applies in full.
- **One service = one clear domain capability area.** Split by business capability, not by technical trivia ("write side", "read side"). A `*Validator` validates one invariant family. A `*Syncer` synchronizes one canonical thing. A `*Catalog` reads one canonical catalog. Mixing two of these in one class violates SOC.
- Service APIs MUST be **small, explicit, and typed**. Public surface should be the minimum needed for callers. Hidden complexity is a feature; large public surface is a smell.
- Services MUST keep HTTP concerns — `request()`, `redirect()`, `flash()`, `session()`, `Inertia::`, `trans()` for UI copy — **out** of their API. The Tier 1 red line applies.
- Prefer **low-abstraction capability services** over umbrella "do-everything" classes. Splitting one capability into 3 lazy `*Helper` micro-services is also wrong — those add no boundary value.

### Boundary Contract for Service APIs

- Focused capability services SHOULD receive **resolved models plus typed DTOs or primitives**. Lookup noise belongs in the action or in a rare thin internal façade.
- Model-or-id resolution SHOULD NOT live in every focused service. The action resolves; the service operates on the resolved entity.
- Prefer **stable domain arguments** over raw request-shaped payloads.
- Avoid vague `mixed` parameters and shapeless "data" arrays where a clearer signature is possible.

### Arrays-For-Now Rule

Shaped arrays are tolerated at service boundaries during the current migration. **They MUST be:**

1. **Narrow in scope** — a few well-defined keys, not a sprawl of optional fields.
2. **Documented with PHPDoc array shapes** — `array{customer_id: string, voucher_code: string, occurs_at: CarbonInterface}` or `array<string, mixed>` only when no narrower shape exists.
3. **Local to the seam** — used between this action and this service, not propagated across three or more service boundaries.

**Red lines:**
- Do not leak the same `array<string, mixed>` payload through 3+ service boundaries. That is a hidden DTO begging to be made explicit.
- Do not widen an array contract to accept new keys for a new caller. Add a typed DTO instead.
- When the seam is heavy or reused, **promote the array to a DTO or result object** in the next refactor.

### Naming Rules

Service names MUST communicate **domain outcome**, not technical role.

| Suffix | Use When | Example |
| ------ | -------- | ------- |
| `*Resolver` | Resolves an entity from inputs (lookup, fallback chain). | `VoucherCodeResolver` |
| `*Syncer` | Synchronizes a canonical state from a source of truth. | `BookingAvailabilitySyncer` |
| `*Writer` | Performs a specific write capability. | `VoucherDetailsWriter` |
| `*Updater` | Updates an entity through a defined mutation flow. | `VoucherDetailsUpdater` |
| `*Validator` | Enforces an invariant family. | `OrderLineEligibilityValidator` |
| `*Guard` | Blocks operations when invariants fail. | `ProductDeletionGuard` |
| `*Catalog` | Reads a canonical catalog/lookup. | `ShippingZoneCatalog` |
| `*Changer` | Performs a state-machine transition. | `BookingStatusChanger` |
| `*Processor` | A genuine processing boundary (intake, transform, dispatch). | `OrderImportProcessor` |
| `*Service` | The generic default. Acceptable but lazy when a more specific suffix fits. | `ProductPersistenceService` |

**Forbidden for new code:** `Helper`, `Utils`, `Manager`. Legacy umbrella names are tolerated existing code, not the default naming target.

### Simplicity Rules

- **KISS is mandatory.** Adding indirection MUST justify itself by reducing total complexity.
- **DRY is mandatory only when the repetition is the same domain behaviour.** Coincidental similarity is not duplication.
- **SOC is mandatory.** Mixed responsibilities in one class is the most common violation; address it first.
- **Do not introduce speculative interfaces** without multiple real implementations.
- **Do not split** one workflow into a Controller → Action → Service → Helper → Utility forwarding chain.
- **If a split makes the workflow harder to read, the split is wrong.** Revert the split direction.
- **If the action still contains dense private domain methods after extraction, the split is incomplete.** Continue.

## Splitting Workflow

When you decide a split is justified, follow this order:

1. **Identify cohesion seams** in the current action or service. A seam is a place where one *meaningful* domain capability ends and another begins.
2. **Separate orchestration from capability.** Orchestration belongs in the action. Capability belongs in services.
3. **Hold transactions, events, activity, and after-commit work stable** during the extraction. Do not change side-effect behaviour while moving code.
4. **Extract focused services** with explicit typed public APIs and intent-first names.
5. **Simplify the action** so it resolves inputs, opens the transaction, delegates to services in sequence, records activity, schedules after-commit work, and returns.
6. **Remove dead helpers, imports, and forwarding code immediately.** Half-completed splits decay fastest.
7. **Validate** the touched module with Pest tests, Pint, and PHPStan level 4 (then level 5).

## Anti-Patterns

| Anti-pattern | Why it fails |
| ------------ | ------------ |
| Broad umbrella `*Service` that owns 4+ unrelated capabilities | SOC violation. Split by capability area. |
| Splitting purely for architectural aesthetics | Adds indirection without clarity. Revert. |
| Service owning the transaction "because it writes" | Hides write-boundary policy. Move the transaction to the action. |
| Passing the same shapeless array through 3+ service layers | Hidden DTO. Promote to a typed contract. |
| Model-or-id resolution inside every focused service | Lookup noise. Resolve in the action; pass the model in. |
| `Helper`, `Utils`, `Manager` naming for new code | Communicates "I could not name what this does." Rename. |
| Controller → Action → Service → Helper → Utility forwarding chain | Each layer only forwards arguments. Collapse the chain. |
| Service orchestrating 6+ collaborators with no domain reason | False façade. Push orchestration back to the action or justify the façade. |
| `app(SomeOther::class)` inside a service body | Service-locator. Inject via constructor. |
| `request()`, `redirect()`, `flash()`, `Inertia::` inside a service body | Cross-layer red line. Move to controller. |

## Quality Checklist (run before opening a PR)

- [ ] Service is stateless and constructor-injected — no service locator anywhere.
- [ ] Single, cohesive domain capability — class name describes the capability.
- [ ] Public API is small, explicit, and typed. No vague `mixed`.
- [ ] No HTTP concerns inside the service body.
- [ ] Action remained thin after the split (orchestration only, no dense private domain methods).
- [ ] Transaction sits in the action, not the service (unless the documented exception applies).
- [ ] Array parameters carry `@param array<...>` shapes; arrays are narrow and local.
- [ ] Naming uses an intent-first suffix from the Naming Rules table.
- [ ] Class PHPDoc and method PHPDocs complete per `php-code-style` skill.
- [ ] Pest tests cover the capability in isolation; action tests cover the orchestration.
- [ ] Pint, PHPStan level 4 (then level 5) clean on the touched scope.

## Useful Checks

```bash
# Service inventory and class boundaries
rg -n "class .*Service|class .*Resolver|class .*Syncer|class .*Writer|class .*Updater|class .*Validator|class .*Guard|class .*Catalog|class .*Changer|class .*Processor" Modules/<Module>/app/Services

# Discouraged legacy names in new code
rg -n "Helper|Utils|Manager" Modules/<Module>/app/Services

# Cross-layer red-line check inside services
rg -n "request\(\)|redirect\(|flash\(|session\(\)|Inertia::" Modules/<Module>/app/Services
rg -n "trans\(" Modules/<Module>/app/Services

# Service-locator leakage inside services (zero tolerance)
rg -n "app\(.+::class\)|App::make|App::makeWith|Container::resolve|resolve\(" Modules/<Module>/app/Services

# Transaction ownership audit — services owning transactions should be rare
rg -n "DB::transaction\(|lockForUpdate\(|afterCommit\(" Modules/<Module>/app/Services

# Public API width — count public methods per service
for s in Modules/<Module>/app/Services/**/*.php; do
  count=$(rg -c "public function" "$s" 2>/dev/null || echo 0)
  echo "$count $s"
done | sort -rn | head -20
```

## Failure Handling

- A legacy umbrella service already exists → do not expand it by default. Keep your change narrow, or start splitting from one cohesive seam.
- An array is still required at a service boundary → keep it narrow, shaped, and local. Do not widen it.
- A split introduces more forwarding layers than clarity → revert the split direction and simplify.
- An action would orchestrate 5+ focused services after the split → allow a thin internal façade for that capability family, documented in its PHPDoc.
- A service already owns a transaction and cannot be cleanly separated from it → keep it, document it as the rare exception in the class PHPDoc, and reference the action that delegates to it.

## Cross-References

- `backend-architecture/SKILL.md` — Tier 1 baseline and Tier 2.3 binding rules (this reference adds depth, does not replace).
- `references/actions.md` — what stays in the action after the split.
- `backend-spatie-data` skill — when to promote arrays to typed DTOs.
- `backend-models` skill — when a service is actually a builder candidate (query domain, not behaviour).
- `php-code-style` skill — class header / method PHPDoc / array-shape annotation contract.
- `pest-testing` skill — testing services in isolation and actions in orchestration.
