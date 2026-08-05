# Actions — Full Doctrine

You are a backend action architect. This reference deepens Tier 2.2 of `backend-architecture/SKILL.md`. Tier 2.2 rules are binding; everything below adds enforcement detail. **Nothing here relaxes the Tier 1 baseline or the cross-layer red lines.** Load this reference whenever the touched scope includes `Modules/**/app/Actions/**`.

## Doctrine

An Action is the **verb of the module**. It owns one use-case from input to commit and is the only layer permitted to open a transaction, schedule after-commit work, and emit activity. It MUST stay thin — model-or-id resolution, transaction boundary, sequential service calls, return — nothing more.

If an action grows internal private methods that look like services, the action is no longer thin and the work belongs in services. See `services.md`.

## Binding Rules

### One Entrypoint

- Exactly one public `execute(...)` per action class. No second public method, ever.
- Helper methods MUST be `private` or `protected`. They MUST NOT carry domain logic that would justify its own service.
- Construct dependencies via constructor property promotion (`private readonly`). No service locator. No `app(...)`, `App::make(...)`, `App::makeWith(...)`, `Container::resolve(...)`, `resolve(...)` — refer to the Tier 1 ban list.

### Typed Signatures

`execute()` parameters and return type MUST be fully typed. Concrete types only. **Never** `mixed`, **never** `array` as a return type for a write action.

| CRUD Shape | Required Signature |
| ---------- | ------------------ |
| Create | `execute(DataObject $data): Model` |
| Create (nested) | `execute(ParentModel\|string $parent, DataObject $data): Model` |
| Update | `execute(Model\|string $model, DataObject $data): Model` |
| Update (nested) | `execute(ParentModel\|string $parent, Model\|string $model, DataObject $data): Model` |
| Delete | `execute(Model\|string $model): bool` |
| Show | `execute(Model\|string $model): Model` |
| List | `execute(): LengthAwarePaginator` *or* `execute(): Collection` |
| Check (read-only invariant) | `execute(Model\|string $model): bool` |

All other signature shapes require explicit justification in the class PHPDoc and the PR description.

### Model-or-ID Resolution

Persistent entity parameters MUST accept `Model|string` and resolve internally:

1. Resolve via `instanceof` check, then `findOrFail()` for the string branch.
2. For nested actions, immediately verify parent-child ownership (`$child->parent_id === $parent->getKey()`); on mismatch, throw a domain exception or 404.
3. The action contract MUST remain identical whether the controller passes a route-bound model or an id string.

### Transaction Boundary

- Every write `execute()` MUST be wrapped in `DB::transaction(...)`. No partial-state escape hatch.
- The transaction lives in the action — not in a service, not in a controller, not in a model observer.
- Inside the transaction: only DB writes, model refreshes, and direct value computation. No HTTP calls, no queue dispatches, no mail sends, no notifications.

### Post-Commit Side Effects

All side effects MUST run after commit:

- `DB::afterCommit(fn () => SomeJob::dispatch(...))` for inline scheduling, or
- `SomeJob::dispatch(...)->afterCommit()` for queued jobs that support it.

Side effects that MUST follow this rule: queue dispatches, mail sends, notification sends, event dispatches consumed cross-module, webhook calls, cache invalidation broadcasts.

A side effect that runs inside the transaction and the transaction rolls back is a **CRITICAL** bug — the side effect fired but the data did not persist.

### DTO-Based Writes

- Write actions MUST consume a Spatie Data DTO (`Modules/**/app/Data/Mutations/**`). Raw request arrays MUST NOT enter write paths.
- Persist via `toModelFiltered()` to drop `null` and `Optional` values cleanly. Never `->fill($request->all())` and never `->fill($data->toArray())` without filtering.
- See `backend-spatie-data` skill for DTO contract and validation rules.

### Refreshed Return

A write action MUST return the **post-write state** of the entity when callers render from the return value (the common case in Inertia controllers and frontends consuming Display DTOs).

- Use `$model->refresh()` or rebuild via `Model::with(...)->findOrFail($model->getKey())` to capture changed relationships.
- Returning a stale model is a CRITICAL UX bug: the frontend re-renders with pre-write state.

### Activity Logging

- Meaningful state changes MUST be recorded via `\App\Lib\Activity\Facades\ActivityLog` (`recordUser`, `recordSystemAudit`, or `entry` for migration paths).
- `activity()` helper and `app(\App\Lib\Activity\Support\ActivityRecorder::class)` are forbidden in module/app code.
- Headline rules, properties shape, and noise suppression live in the `backend-activity` skill — load it whenever the action records activity.

### Action Boundary

- Actions MUST orchestrate use-case flow only.
- Actions MAY call services in sequence; they MUST NOT call each other unless the chain is explicitly approved domain orchestration documented in the class PHPDoc.
- Actions MUST NOT contain HTTP concerns — no `redirect()`, `back()`, `flash()`, `session()`, `request()`, `Inertia::`. The Tier 1 red line applies.
- Actions MUST NOT format user-facing copy. Translation belongs in controllers (flash) or frontend (`useI18n()`).

### Handoff to Services

When an action's `execute()` body starts mixing more than one domain capability, **stop** and split into services. Indicators:

- Two or more distinct invariants enforced in one body (e.g., availability check + voucher binding + customer linkage).
- Private methods that read like "the *real* business logic" while `execute()` only sets them up.
- Body length exceeds ~40 lines without trivial sequential composition.

After the split, the action remains the transaction entrypoint and orchestrates focused services. See `services.md` for split rules.

## Anti-Patterns

| Anti-pattern | Why it fails |
| ------------ | ------------ |
| Multiple public methods on one action | Action has lost its single-use-case identity. Split into separate actions. |
| Returning a DTO from a write action | Caller expects the persisted entity to render from. DTO is the input contract, not the output. |
| `app(SomeService::class)` inside the action body | Hides dependencies; breaks testability. Use constructor injection. |
| `request()` or `session()` inside the action body | HTTP concern in the wrong layer. Move to the controller; pass parsed values in. |
| No `DB::transaction` around multi-write `execute()` | CRITICAL — partial state on failure. |
| `dispatch(SomeJob)` without `afterCommit()` inside a transaction | CRITICAL — job runs even if the transaction rolls back. |
| `->save()` then return — without refresh — when caller renders from return | Stale data in the UI. Refresh before returning. |
| Calling another action from inside `execute()` | Breaks single-orchestrator rule; chains become opaque. |
| Catching `Throwable` and rethrowing with a generic message | Loses the original cause and bypasses domain exception types. |
| Updating a column from another module's table directly | Cross-module write — CRITICAL ownership violation. Go through that module's action. |

## Quality Checklist (run before opening a PR)

- [ ] Single public `execute()` with fully typed signature.
- [ ] Constructor uses property promotion; no service locator.
- [ ] Model-or-id parameters resolve via `instanceof` + `findOrFail()`; nested actions verify ownership.
- [ ] Writes wrapped in `DB::transaction()`.
- [ ] All side effects run after commit (`DB::afterCommit` or queue `->afterCommit()`).
- [ ] DTO consumed via `toModelFiltered()` for writes.
- [ ] Refreshed model returned when callers render from the return value.
- [ ] Activity recorded via `ActivityLog` facade for meaningful state changes.
- [ ] No HTTP concerns inside the action body.
- [ ] Class PHPDoc and `execute()` PHPDoc complete per `php-code-style` skill.
- [ ] No action-to-action chains unless documented as explicit domain orchestration.
- [ ] Pest test covers the happy path, at least one failure path, and ownership rejection for nested actions.

## Useful Checks

```bash
# Inventory actions and execute signatures
rg -n "class .*Action|function execute\(" Modules/<Module>/app/Actions

# Transaction + DTO + activity coverage in the action layer
rg -n "DB::transaction|toModelFiltered\(|ActivityLog::" Modules/<Module>/app/Actions

# Model|string resolution pattern
rg -n "instanceof .*\? .*findOrFail|findOrFail\(" Modules/<Module>/app/Actions

# Side-effect ordering — dispatches outside afterCommit are suspect
rg -n "::dispatch\(|dispatch\(new |event\(|Mail::send|Notification::send" Modules/<Module>/app/Actions
rg -n "afterCommit\(" Modules/<Module>/app/Actions

# Service-locator leakage inside actions (zero tolerance)
rg -n "app\(.+::class\)|App::make|App::makeWith|Container::resolve|resolve\(" Modules/<Module>/app/Actions

# Cross-layer red-line check (HTTP concerns inside actions)
rg -n "redirect\(|back\(\)|flash\(|session\(\)|request\(\)|Inertia::" Modules/<Module>/app/Actions
```

## Cross-References

- `backend-architecture/SKILL.md` — Tier 1 baseline and Tier 2.2 binding rules (this reference adds depth, does not replace).
- `references/services.md` — when an action grows beyond one capability and needs to split.
- `backend-spatie-data` skill — DTO contract details and `toModelFiltered()` semantics.
- `backend-activity` skill — `ActivityLog` facade, headline rules, properties shape.
- `backend-controllers` skill — what the controller passes in and how it consumes the action's return value.
- `pest-testing` skill — action test patterns: happy path, failure paths, ownership rejection.
