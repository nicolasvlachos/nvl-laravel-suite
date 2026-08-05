# Skill Engagement Map

When to load which skill during a module audit. Each skill carries domain-specific rules that the audit alone cannot replicate — load the skill, internalize its doctrine, then apply it to findings.

**Rule:** Load a skill only when the audit reaches code in that skill's domain. Never pre-load all skills at once — that wastes context.

---

## Phase 0: Context Gathering

No skills needed. Use git, glob, and file reads.

---

## Phase 1: Strategic Analysis

### Always Load

| Skill                   | Load When                                | What It Gives You                                                                                                                                                                                                  |
| ----------------------- | ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `backend-architecture` (this skill — already loaded) | **Phase start** — before reading any PHP | The non-negotiable Tier 1 baseline: `declare(strict_types=1)`, import hygiene, class/method PHPDocs, boundary contracts (Controller→Action→Service→Model→DTO), zero-tolerance service-locator ban. Without this baseline you cannot judge whether a file is compliant. |

### Load Per Section

#### 1.1 Business Logic Stress Test + 1.2 Intent Drift

| Skill                 | Load When                          | What It Gives You                                                                                                                                                                                                                          |
| --------------------- | ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `references/actions.md` (in this skill) | You're reading files in `Actions/` | Typed `execute()` contracts, transaction boundaries, `Model\|string` resolution, `DB::afterCommit` for side effects, activity logging rules, DTO-based writes via `toModelFiltered()`. Tells you exactly what a correct action looks like. |
| `backend-spatie-data` | You're reading files in `Data/`    | DTO rules: `#[TypeScript]` requirement, Display vs Mutations namespace separation, validation via `rules()`/`scopedRules()` only, `readonly` constructor properties, `Optional` vs `?T` semantics, `toModelFiltered()` for writes.         |

#### 1.3 Chain of Command

| Skill                    | Load When                                                              | What It Gives You                                                                                                                                                                                                      |
| ------------------------ | ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `backend-controllers`    | You're reading files in `Http/Controllers/`                            | Extends `InertiaController`, implements `pageOptions()`, uses `registerPaginatedPage()`/`registerDataPage()`, wraps mutations in `$this->attempt(...)`, flash messages use module translation keys with `:item` param. |
| `backend-models`         | You're reading files in `Models/`                                      | `TABLE` constant, `casts()` method (not `$casts`), typed relationships, `#[Scope]` attribute (protected, void return), trait composition by concern (`*Filters`, `Has*`, `*Activity`), lean body (no business logic).  |
| `references/services.md` (in this skill) | You find services in `Services/` OR an action is becoming a god-method | Ownership rules, naming conventions (intent-first, never Helper/Manager/Utils), action-vs-service decomposition rule (4-service threshold), transaction ownership, Arrays-For-Now rule, stateless design, action as thin orchestrator pattern. |

#### 1.4 Models & Builders

| Skill                | Load When                               | What It Gives You                                                                                                                                                           |
| -------------------- | --------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `backend-models`     | Model has 5+ scopes or complex queries  | `newEloquentBuilder()` delegation, typed builder methods, `forReporting()` static entry points, filter trait rules (`{Model}Filters` with `allowedFilters`/`allowedSorts`). |
| `backend-migrations` | You need to verify schema against model | UUID PKs, meaningful column comments, string columns for enums (cast in model), FK constraints explicit, indexes only for real query paths, PostgreSQL-native patterns.     |

#### 1.5 Orchestration & Side Effects

| Skill              | Load When                                                           | What It Gives You                                                                                                                                                                                                         |
| ------------------ | ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `backend-activity` | Module has activity logging or observers with timeline side effects | `ActivityLog` facade as only entry point, actor-first headlines, `{attributes, old}` properties structure, `HasModelActivity` + `ActivityMapping` for primary models, sub-resource logging pattern, timeline noise rules. |
| `references/actions.md` (in this skill) | Checking transaction boundaries and side-effect ordering | `DB::transaction()` wraps mutations, `DB::afterCommit()` for jobs/events/notifications, no dispatch inside transaction without afterCommit. |

#### 1.6 Cross-Module Boundaries

| Skill                     | Load When                                              | What It Gives You                                                                                                                                                            |
| ------------------------- | ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `backend-api-filters`     | Module exposes API endpoints consumed by other modules | Route naming (`api.{module}.{resource}`), suggestion endpoint contract (`{data: [...]}` envelope), filter trait rules, ILIKE search, clamped limits, short-query guards.     |
| `backend-suggestions-api` | Module has suggestion/autocomplete endpoints           | Suggestion DTO with `fromModel()`, query service with ILIKE, thin API controller, versioned routes at `/api/v1/{resource-slug}/suggestions`, search guards (1-char → empty). |

---

## Phase 2: Pattern Compliance

The scan scripts handle mechanical detection. Load these skills to **interpret** scan results:

| Skill                       | Load When Scan Finds                                                            | What It Tells You                                                                              |
| --------------------------- | ------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| `backend-models`            | Missing TABLE constant, legacy `$casts`, untyped relationships                  | Exact model compliance rules + how to fix                                                      |
| `backend-spatie-data`       | Missing `#[TypeScript]`, DTOs in wrong namespace, hardcoded validation messages | DTO separation rules (Display/ vs Mutations/), validation translation pattern                  |
| `references/actions.md` (in this skill) | Actions without transactions, multiple public methods, untyped signatures | Action signature patterns per CRUD type, transaction rules |
| `backend-controllers`       | Controllers with business logic, missing `attempt()`, hardcoded flash messages  | Controller thinness rules, attempt pattern, flash translation keys                             |
| `backend-translations-i18n` | EN/BG key mismatch, missing files in 8-file structure                           | 8 required files, controller sharing rules (INDEX vs DETAIL subsets), EN/BG parity enforcement |
| `backend-quality-assurance` | PHPStan errors, test coverage gaps                                              | Align-first process, level 4→5 escalation, Pest test patterns, Inertia contract testing        |

---

## Phase 3: Spatie Data & Full-Stack Parity

| Skill                     | Load When                                              | What It Gives You                                                                                                                                                                                                                  |
| ------------------------- | ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `backend-spatie-data`     | **Always in Phase 3** — DTO parity is the core concern | Display DTO decorators (`#[TypeScript]`, `#[TypeScriptOptional]`, `#[MapOutputName(CamelCaseMapper)]`), root DTO pattern, `toArray()` vs `toModel()` vs `toModelFiltered()` semantics.                                             |
| `fullstack-state-display` | Show page has 3+ hooks computing derived state         | Full Evaluation Protocol: hook inventory, value classification, scoring matrix, savings estimate. Display DTO architecture: `Data/Display/` namespace, stateless `StateResolver` service, `'states' => $states->toArray()` wiring. |
| `fullstack-money`         | Module has money fields (prices, totals, commissions)  | `MoneyCast` in models, `Money->toData()` for payloads, `MoneyData`/`MoneyPairData` in DTOs, record currency ownership rules, `MoneyDisplay`/`MoneyInput` on frontend.                                                              |

---

## Phase 4: Quality Gates

| Skill                       | Load When             | What It Gives You                                                                                                                                                       |
| --------------------------- | --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `backend-quality-assurance` | **Always in Phase 4** | PHPStan level 4 first (required gate), level 5 only after level 4 clean, Pint formatting, Pest test patterns, `RefreshDatabase` for feature tests, factory usage rules. |

---

## Conditional Skills (load only when the module touches these domains)

| Skill                | Trigger Condition                                                          | What It Gives You                                                                                                                                                                     |
| -------------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `backend-mailing`    | Module has `Mail/` directory or sends emails                               | Native Laravel templates vs MailerSend, `trans()` for all copy, `mail.testing` interception safety, EN/BG email key parity, `emails.php` stays backend-only.                          |
| `backend-media`      | Module uses `InteractsWithMedia` trait                                     | Collection registration, variation presets, `MediaAdder` fluent builder, upload/attach/detach pipeline, media API endpoints, storage disk config.                                     |
| `backend-auth-stack` | Module touches authentication, authorization, invitations, or social login | Guard-aware services, `VerifyAuthenticatableAction` eligibility gate, invitation-driven registration, social provider baseline (Google), magic-link token security, throttling rules. |
| `backend-metrics`    | Module has `Get*StatsAction` or dashboard metrics                          | Metric payload contract (`value`, `change: {value, direction, label}`, `sparkline`), query efficiency, cache + invalidation, sparkline determinism, translation-backed labels.        |
| `app-navigation` | Module adds or changes admin sidebar entries                           | Frontend nav in `resources/js/data/navigation.ts`, labels in `locales/{en,bg}/navigation.json`, route-name entries, Lucide icons, URL-driven active state.                                     |

---

## Loading Protocol

1. **Read the skill's SKILL.md** to internalize its doctrine
2. **Apply its rules** to the files you're currently analyzing
3. **Use its checklist** to validate findings
4. **Reference its anti-patterns** to catch violations the scan scripts miss
5. **Cite the skill** in findings: `Skill ref: backend-architecture/references/actions.md` tells the implementer where to find the correct pattern

**Do NOT load skills speculatively.** Load when you reach code in that domain. The skill descriptions above tell you the exact trigger.

---

## Quick Decision Tree

```
Reading Controllers/ → load backend-controllers
Reading Actions/     → open references/actions.md (in this skill)
Reading Services/    → open references/services.md (in this skill)
Reading Models/      → load backend-models
Reading Data/        → load backend-spatie-data
Reading Migrations/  → load backend-migrations
Reading Observers/   → load backend-activity
Reading Mail/        → load backend-mailing
Reading locales/     → load translations
Reading routes/      → load backend-api-filters
Running PHPStan      → load backend-quality-assurance
Running tests        → load backend-quality-assurance
Money fields found   → load fullstack-money
3+ show-page hooks   → load fullstack-state-display
Auth/invitation code → load backend-auth-stack
Media/upload code    → load backend-media
Stats/metrics code   → load backend-metrics
Sidebar nav code     → load app-navigation
Suggestions endpoint → load backend-suggestions-api
```
