# Display State Evaluation Protocol

Run this evaluation when a module's show page has 3+ frontend hooks computing derived state from entity props.

## Quick Detection

```bash
HOOKS_DIR="resources/js/pages/admin/{module-slug}/hooks"
ls "$HOOKS_DIR"/*.ts "$HOOKS_DIR"/*.tsx 2>/dev/null | wc -l
```

If 3+ hook files exist, proceed with evaluation.

## Step 1: Inventory Hooks

For each hook file, classify:

| Hook File | Lines | Mutations | Local State | Pure Derivation |
|-----------|-------|-----------|-------------|-----------------|
| use-{module}-presentation.ts | ? | 0 | 0 | all |
| use-{module}-derived-state.ts | ? | 0 | 0 | all |
| use-{module}-show-actions.tsx | ? | N | N | few |
| use-{module}-editable-fields.ts | ? | few | few | few |

- **Mutations**: `useAction`, `execute()`, Inertia visits
- **Local State**: `useState`, `useEffect` syncing local values
- **Pure Derivation**: computed values from entity props with zero side effects

## Step 2: Classify Each Computed Value

| Category | Examples | Destination |
|----------|----------|-------------|
| **Backend-derivable** | status labels, action visibility, voucher flags, date options | `Data/Display/` DTO |
| **Client-only** | form state, modal isOpen/isLoading, breadcrumbs | Stays in frontend |
| **Duplicated** | same boolean in 2+ hooks | Move to DTO, delete all FE copies |
| **Backend enum mirror** | frontend constants like `CONFIRMABLE_STATUSES` | Eliminate — resolver owns the logic |

## Step 3: Score

| Metric | Threshold | Score |
|--------|-----------|-------|
| Focused hooks with pure derivation | 3+ hooks | +3 |
| Total derivation lines (no mutations/state) | 500+ lines | +3 |
| Duplicated values across hooks | Any | +2 |
| Frontend constants mirroring backend enums | Any | +2 |
| Prop drilling through 5+ cards | Yes | +1 |
| Action hook computes visibility inline | Yes | +1 |

- **Score 6+**: Strong candidate — recommend Display State migration
- **Score 3-5**: Moderate — present trade-offs, let user decide
- **Score 0-2**: Not a candidate — current approach is fine

## Step 4: Present Recommendation

Include:
1. Score with breakdown
2. What gets eliminated (hooks + line counts)
3. What stays (hooks with client-only state)
4. Proposed DTO concerns (flags, status, voucher, dates, actions)
5. Estimated savings (before/after line counts)

**Wait for user approval before implementing.**

## Architecture (for approved migrations)

**Backend:**
- `Data/Display/{Module}ShowStatesData.php` — root DTO grouping nested concern DTOs
- `Data/Display/{Module}FlagStates.php` etc. — one DTO per concern
- `Services/{Module}ShowStateResolver.php` — pure derivation, zero DB queries, accepts already-loaded data
- Wire into `Build{Module}ShowPayloadAction` → `'states' => $states->toArray()`

**Frontend:**
- Eliminated hooks: presentation, derived-state, voucher-state, date-options
- Orchestrator reads `data.states` instead of calling focused hooks
- Action hooks read `visible`/`disabled` from `data.states.actions.*`
- Cards consume `data.states.*` props directly

All Display DTOs require: `#[TypeScript]`, `#[TypeScriptOptional]`, `#[MapOutputName(CamelCaseMapper::class)]`.
