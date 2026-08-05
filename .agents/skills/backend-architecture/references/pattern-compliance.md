# Pattern Compliance — Full Scan Catalog

Self-contained scan procedures for Phase 2. Each scan category lists what to check, how to check it, and what a violation looks like.

## Backend Scans (10 categories)

### 1. Schema & Model

```bash
# Models missing TABLE constant
rg -n "class.*extends Model" ${MODULE}/app/Models/
rg -n "const TABLE" ${MODULE}/app/Models/

# Legacy $casts property (should use casts() method)
rg -n "protected \$casts" ${MODULE}/app/Models/

# Untyped relationships (missing return type)
rg -n "function .*\(\)$" ${MODULE}/app/Models/ | grep -v "casts\|boot\|TABLE"

# Soft deletes mismatch (trait vs migration)
rg -n "SoftDeletes" ${MODULE}/app/Models/
rg -n "softDeletes" ${MODULE}/database/migrations/
```

Per model checklist:
- Has `TABLE` constant matching migration table name
- Uses `casts()` method (not `$casts` property)
- All relationships have return types (`BelongsTo`, `HasMany`, etc.)
- `$fillable` or `$guarded` defined
- SoftDeletes trait matches migration

### 2. DTO (Spatie Data)

```bash
# DTOs missing TypeScript decorator (if exposed to frontend)
rg -n "extends Data" ${MODULE}/app/Data/
rg -n "#\[TypeScript\]" ${MODULE}/app/Data/

# Validation messages — must use translation keys, not hardcoded English
rg -n "messages\(\)" ${MODULE}/app/Data/

# Check Optional vs nullable semantics
rg -n "Optional\|" ${MODULE}/app/Data/
```

Per DTO checklist:
- Has `#[TypeScript]` if consumed by frontend
- Properties use typed PHP 8.4 declarations
- Validation messages use `trans()` / translation keys
- Mutations namespace (`Data/Mutations/`) for input DTOs
- Display namespace (`Data/Display/`) for output DTOs

### 3. Actions

```bash
# Check action signatures
rg -n "public function execute" ${MODULE}/app/Actions/

# Transaction boundaries
rg -n "DB::transaction" ${MODULE}/app/Actions/

# Actions with writes but no transaction
for action in $(find ${MODULE}/app/Actions -name "*.php" -type f); do
  writes=$(rg -c "->save\(\)|::create\(|->update\(|->delete\(" "$action" 2>/dev/null || echo 0)
  tx=$(rg -c "DB::transaction" "$action" 2>/dev/null || echo 0)
  if [ "$writes" -gt 0 ] && [ "$tx" -eq 0 ]; then
    echo "MISSING TRANSACTION: $action ($writes writes)"
  fi
done
```

Per action checklist:
- Single `execute()` with typed params and return
- `DB::transaction()` wraps multi-write operations
- Uses DTO payloads (not raw arrays)
- `Model|string` parameter with `instanceof` resolution
- Side effects after commit (`DB::afterCommit`)
- Activity logging for state changes

### 4. Controllers

```bash
# Controller thickness — should NOT contain data operations
rg -n "DB::|->where\(|->save\(\)" ${MODULE}/app/Http/Controllers/

# Inertia page name verification
rg -n "Inertia::render\|->render\(" ${MODULE}/app/Http/Controllers/

# Hardcoded flash messages (should use trans())
rg -n "->with\('success'" ${MODULE}/app/Http/Controllers/
```

Per controller checklist:
- Thin orchestration (delegates to Actions)
- `Inertia::render()` page names match frontend file paths
- Uses `attempt()` pattern for mutations
- Flash/success messages use translation keys

### 5. Service Providers

```bash
# Inline FQCNs (should use ::class imports)
rg -n "Modules\\\\.*\\\\.*\\\\" ${MODULE}/app/Providers/

# Event registration
rg -n "Event::listen" ${MODULE}/app/Providers/
```

### 6. Routes

```bash
# Naming conventions
rg -n "->name\(" ${MODULE}/routes/web.php

# Expected pattern: module.resource.action
```

### 7. Translations (EN/BG Parity)

```bash
# Standard 8-file structure
ls ${MODULE}/lang/en/*/ 2>/dev/null
ls ${MODULE}/lang/bg/*/ 2>/dev/null

# Expected files: actions.php, forms.php, messages.php, pages.php,
# tables.php, filters.php, general.php, shared.php
```

For each file: read EN, read matching BG, compare key structure. Every key in EN must exist in BG and vice versa. Check for empty strings or placeholder translations.

### 8. Static Analysis

```bash
./vendor/bin/phpstan analyse ${MODULE}/app --level=4   # required gate
./vendor/bin/phpstan analyse ${MODULE}/app --level=5   # after level 4 clean
```

No `@phpstan-ignore` suppressions. No `mixed` return types. No missing parameter types.

### 9. Tests

```bash
# Pest style verification
rg -n "uses\(RefreshDatabase" ${MODULE}/tests/
rg -n "it\(|test\(" ${MODULE}/tests/

# Coverage gap: count actions vs test files
find ${MODULE}/app/Actions -name "*.php" | wc -l
find ${MODULE}/tests -name "*.php" | wc -l
```

### 10. Ownership & Boundaries

```bash
# Cross-module writes (this module writing to foreign tables)
rg -n "->save\(\)|::create\(|->update\(|->delete\(" ${MODULE}/app/ \
  | grep -v "${MODULE}/app/Models/"

# Cross-module imports
rg -n "use Modules\\\\" ${MODULE}/app/ | grep -v "use Modules\\\\{ModuleName}\\\\"

# Event chains
rg -n "event\(|Event::dispatch" ${MODULE}/app/
```

## Frontend Scans (10 categories)

Target: `resources/js/pages/admin/{module-slug}/`

### 1. Raw HTML Elements (CRITICAL)

```bash
rg -n "<h[1-6]|<p[ >]|<span[ >]|<a[ >]|<label[ >]|<table|<tr|<td" ${FRONTEND}/
```

Must use: `Text`, `Heading`, `Label`, `TextLink` from `@/components/ui/base/typography`.

**Exception:** Component library internals (`components/ui/`), markdown renderers.

### 2. Hardcoded Color Classes (HIGH)

```bash
rg -n "slate-|gray-|zinc-|bg-white|text-black" ${FRONTEND}/
```

Must use design tokens: `bg-background`, `bg-muted/30`, `text-foreground`, `text-muted-foreground`, `border-border`.

### 3. Hardcoded Strings (HIGH)

```bash
rg -n "\?\? '||| '" ${FRONTEND}/
```

All strings must use `t()` translations.

### 4. Invalid Component Variants (CRITICAL)

```bash
rg -n 'variant="default"|variant="outline"' ${FRONTEND}/
```

Valid Button variants: `dark|primary|light|secondary|error|warning|success|action`.
Valid Badge variants: `primary|secondary|success|info|error|destructive|main|warning`.

### 5. Wrong Import Paths (MODERATE)

```bash
# Legacy card imports (should be @/components/ui/base/cards)
rg -n "from.*composed/cards|from.*ui/card" ${FRONTEND}/

# Legacy form imports (should be @/components/ui/base/forms)
rg -n "from.*ui/forms|from.*composed/forms" ${FRONTEND}/
```

### 6. CoreProvider Double-Wrapping (MODERATE)

```bash
rg -n "<CoreProvider" ${FRONTEND}/
```

AdminLayout already provides CoreProvider. Adding it again is a violation.

### 7. Translation Hook Violations (HIGH)

```bash
rg -n "useTranslations\(\)" ${FRONTEND}/
```

Must use `useI18n()` — NEVER `useTranslations()`. Zero exceptions.

### 8. Form Pattern Violations (MODERATE)

```bash
rg -n "<Label|<Input" ${FRONTEND}/ | grep -v "import"
```

Must use `ControlledFormField` from `@/components/ui/base/forms`.

### 9. Action Pattern Violations (MODERATE)

```bash
rg -n "useInertiaOperation|defineActions" ${FRONTEND}/
```

Legacy patterns. Must use `useAction<V,P>()`.

### 10. Card Surface Violations (LOW)

```bash
rg -n "from.*shadcn.*card|Card.*CardHeader.*CardContent" ${FRONTEND}/
```

Must use `SmartCard` from `@/components/ui/base/cards`.

## Cross-Stack Parity (6 checks)

### 1. DTO → TypeScript Alignment

Every `#[TypeScript]` DTO must have a matching TypeScript interface in `generated.types.d.ts`.

### 2. Controller → Page Props Alignment

Every key in controller's `Inertia::render()` data array must match a frontend PageProps property.

### 3. Translation Key Parity

Every `t()` key in frontend must exist in: EN PHP + BG PHP + TS types.

### 4. Route Parity

Every `route()` call in frontend must match a named route in web.php/api.php.

### 5. Money Field Parity

Every `MoneyData` in a DTO must render via `MoneyDisplay` on frontend (not raw formatting).

### 6. Display State Parity (if applicable)

Display DTOs must have `#[TypeScript]`, frontend must read `data.states.*`.

---

## Deep Analysis Templates

Use these templates during Phase 1 strategic analysis for structured mapping.

### Ownership Matrix

| Table | Owning Module | Readers | Writers (violations) |
|-------|---------------|---------|---------------------|
| {table} | {module} | {list} | {violations} |

### Event Chain Map

| Event | Emitter | Listeners | Circular? |
|-------|---------|-----------|-----------|
| {event} | {module} | {modules} | Yes/No |

### Circular Import Map

| Module A | Imports from B | Module B | Imports from A | Resolution |
|----------|---------------|----------|---------------|------------|
| {A} | {classes} | {B} | {classes} | {fix} |

### Behavior Map (Executable Paths)

Trace each entry point:
- Routes → controllers → actions → side effects
- Transaction boundaries and retry behavior
- Events emitted, jobs dispatched, notifications sent

---

## Frontend Cross-File Consistency

### Translation Key Coverage

For every `t('some.key')` call:
1. Key exists in EN PHP file
2. Key exists in BG PHP file
3. Key exists in TS type file
4. No orphaned keys (defined but never used)

### Page Structure Compliance

For each `*.page.tsx`:
- [ ] Uses `AdminLayout` (not `AppMainLayout`)
- [ ] Does NOT wrap with `<CoreProvider>` — app bootstrap (`app.tsx`/`ssr.tsx`) owns CoreProvider
- [ ] Has typed `PageProps<T>` interface
- [ ] Uses `useI18n('<scope>')` for page-level translations
- [ ] Breadcrumbs use translated labels
- [ ] Page actions use `PageActionType[]` from `@/components/layout/page/partials/page-actions`

### Core Services Compliance

**`useCore()` is read-only** — returns `{ i18n, errors, toast, auth, polling }`. No config parameter, no side effects.

Available services (from `@/services/core`):

| Export | Purpose |
|--------|---------|
| `useCore()` | Read-only shared service access (i18n, errors, toast, auth, polling) |
| `useServiceContainer()` | Same services, for hooks outside page-level `useCore` scope |
| `useAction<V,P>(config)` | Mutation hook — modal rendering, typed payloads, error handling |
| `usePageActions(actions)` | Auto-filter action handles by `surface: 'page' \| 'both'` |
| `getActions(actions, surface)` | Pure filter utility for action handles |
| `useFieldErrors(errors, fieldMap)` | Map backend error keys to form field names |
| `useDirtyAwareReset(form, defaults)` | Reset form only when defaults change and form is not dirty |
| `useRegisterCoreConfig(config)` | Explicit polling/config registration (replaces `useCore(config)`) |
| `createPageConfig<T>()(config)` | Type-safe config builder for polling targets |

**Removed (NEVER use):**
- `useFetch`, `useOptimistic`, `useI18n` — removed services
- `comparators`, `byId`, `byLength`, `scalar`, `deep` — removed utilities
- `registerCoreConfig`, `mergeConfigs`, `createBaseConfig` — removed config helpers
- `CoreServicesWithConfig`, `PollingControls`, `PollingState`, `Watcher*` — removed types
- `OperationDisplay`, `OperationHandle`, `OperationContentProps` — removed legacy types

### Hook Compliance

For each `hooks/*.ts` / `hooks/*.tsx`:

**Translation access:**
- [ ] Action hooks (`use-*-actions.tsx`) use `useI18n()` — hooks run outside page `useCore` scope
- [ ] Page components and show-page card/dialog partials use `useI18n('<scope>')` — standard page-level access
- [ ] Pure presentational components receive `t` as a prop — no Core hook dependency
- [ ] **NEVER** `useTranslations()` anywhere — zero exceptions

**Action hooks:**
- [ ] All mutations use `useAction<V,P>(config)` from `@/services/core`
- [ ] Each action returns an `ActionHandle<V,P>` (open, execute, close, reset, isOpen, isLoading, errors, payload, render, visible, disabled, label, variant)
- [ ] Actions declare `surface: 'page' | 'table' | 'both'` (or omit for headless/silent)
- [ ] Page actions derived via `usePageActions(actions)` from `@/services/core` — auto-filters by surface
- [ ] Table actions filtered via `getActions(actions, 'table')` from `@/services/core`
- [ ] Modal content via `display.content: (ctx: ActionRenderContext) => ReactNode` — NOT wrapper components
- [ ] Visit options nested under `options: { ... }` from `@inertiajs/core` (NOT flat `preserveScroll`/`preserveState`)
- [ ] Return value memoized: `return useMemo(() => ({ confirm, remove }), [confirm, remove])`
- [ ] Errors mapped via `useFieldErrors(errors, fieldMap)` in form components

**Polling:**
- [ ] Config-driven polling uses `useRegisterCoreConfig(config)` — NOT `useCore(config)`
- [ ] Polling targets use `{ interval, only, pauseOnModal }` — NOT `{ target, watchers, compare }`
- [ ] Config keys are static and namespaced: `'admin:bookings:index'`

**Dangerous patterns (see `frontend-dangerous-patterns` skill):**
- [ ] No inline object/array creation in render paths (triggers infinite re-renders)
- [ ] No `useEffect` with unstable deps (objects, arrays, functions created during render)
- [ ] Memoized column definitions: `useMemo(() => getColumns(t), [t])`
- [ ] Filter configs defined outside components or memoized with stable deps

**Legacy patterns (NEVER in new code):**
- `useInertiaOperation`, `defineActions`, `ActionRecord`, `action-context.ts`
- `useCore(config)` — `useCore()` is now zero-argument, read-only
- `useFetch`, `useOptimistic`, `comparators` — removed services/utilities
- `OperationContentProps`, `OperationDisplay`, `OperationHandle` — removed types
- `registerCoreConfig`, `mergeConfigs` — removed config helpers
- Standalone `useTranslations()` hook
- Entire `services/inertia/` directory — deleted (actions, helpers, hooks, types)
