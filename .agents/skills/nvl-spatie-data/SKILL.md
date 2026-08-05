---
name: nvl-spatie-data
description: FUTURE PROPOSAL ONLY, not active doctrine. Use only when explicitly asked to review, design, migrate, or update future Gift Come True Spatie Laravel Data v4 and Spatie TypeScript Transformer v3 DTO patterns, including semantic DTO naming without the Data suffix, mutation/display contract separation, generated TypeScript accuracy, validation rules, DataTransform persistence mapping, collections, wrapping, dates, and module-by-module migration planning.
---

# Backend Spatie Data V2

> FUTURE SKILL - NOT ACTIVE DOCTRINE.
>
> This skill is a proposed replacement for `backend-spatie-data`. Do not treat it as binding repository doctrine until the team explicitly adopts it. When both skills exist, use the current active `backend-spatie-data` unless the user explicitly asks for v2, future doctrine, or migration planning.

## Purpose

Use this future skill to design and review Spatie Data contracts as precise boundary objects for Gift Come True. The v2 doctrine keeps the good parts of the current pattern while reducing over-annotation, fixing generated TypeScript drift, separating mutation mechanics from display truth, and replacing the generic `Data` suffix with semantic names over time.

## Required Loading

Before acting, read the minimum reference set for the task:

- `references/doctrine.md` - Always read for v2 principles and non-negotiables.
- `references/naming-and-shapes.md` - Read when creating, renaming, splitting, or reviewing DTO class shapes.
- `references/typescript-contracts.md` - Read when changing generated TypeScript or TypeScript Transformer attributes.
- `references/frontend-and-api-docs.md` - Read when a DTO migration affects frontend consumers, API routes, `config/api-docs.php`, or generated API docs.
- `references/spatie-docs-and-package-facts.md` - Read when validating package behavior against upstream docs.
- `references/migration-playbook.md` - Read when planning migration from current `*Data` classes or current `backend-spatie-data`.

Use these scripts for discovery before broad audits or migrations:

- `scripts/scan-spatie-data-patterns.sh`
- `scripts/scan-frontend-type-references.sh`
- `scripts/scan-api-docs-contracts.sh`

## Execution Workflow

1. Confirm the user explicitly requested this future skill or v2 doctrine.
2. Load `references/doctrine.md`, then only the additional reference files matching the task.
3. Inspect sibling DTOs and immediate action/controller/service consumers before proposing or changing a contract.
4. Classify each Data class as mutation payload, display/page payload, stored JSON, provider payload, query/filter payload, or domain result.
5. Apply semantic naming and shape rules before touching TypeScript attributes.
6. Prefer native PHP types and PHPDoc/PHPStan shapes before adding TypeScript override attributes.
7. Verify generated TypeScript matches PHP truth, especially nullability and optionality.
8. Always regenerate TypeScript during a module migration, even when the change looks backend-only.
9. Update frontend generated-type references and API docs response contracts when class names, namespaces, payload shapes, or type scopes change.
10. If changing active code, run the normal backend gates from `backend-architecture` plus `php artisan typescript:transform`, frontend type checks, and module API docs generation when API contracts are affected.
11. Report changed contracts, generated type effects, frontend consumers, API docs effects, and remaining migration risk.

## Core Doctrine Summary

- Data classes are contract objects for boundaries, not generic helper wrappers.
- Mutation payloads may be optional/nullable because they model client payload mechanics.
- Display/page payloads must express business truth, not form optionality.
- Stored JSON and provider payloads may preserve raw/dynamic shapes only at explicit raw boundaries.
- Drop the universal `Data` suffix for new v2 classes; use semantic names such as `CreateBookingPayload`, `BookingShowPage`, `BookingListItem`, `BookingWorkflowEligibility`, `BookingMetadata`, and `ExternalBookingResult`.
- Keep `DataTransform` as the repository bridge: `toArray()` is frontend/API shape, `toModel()` is storage shape, and `toModelFiltered()` is write shape.
- Do not use `#[LiteralTypeScriptType]` for ordinary primitives or nullable primitives.
- Do not use `#[TypeScriptType(SomeClass::class)]` when the native PHP property type already expresses the referenced class.
- Never allow a TypeScript override to erase PHP nullability or optionality.
- Prefer typed arrays or Laravel collections with PHPDoc/generics for normal lists; use `DataCollection` only when its behavior is needed.
- Keep validation in complete `rules()` arrays. Manual rules replace inferred rules for that property unless explicitly merged.
- Add `defaultWrap()` only when a route/controller/resource actually returns a wrapped Data response.

## Output Format

For audits or recommendations, return:

1. Contract classification summary.
2. Findings ordered by correctness risk, then maintainability.
3. Proposed target names and shapes.
4. Generated TypeScript impact.
5. Frontend reference impact.
6. API docs/response contract impact.
7. Migration steps and validation commands.

For code changes, return:

1. Files changed.
2. Contract behavior changed.
3. TypeScript generated or not generated.
4. Frontend references updated or verified.
5. API docs contracts/generated docs updated or verified.
6. Tests/checks run.
7. Remaining risks.

## Failure Handling

- If v2 was not explicitly requested, stop and say this skill is future-only.
- If upstream Spatie behavior is uncertain, check official docs or installed package code before making claims.
- If generated TypeScript disagrees with PHP, treat the generated contract as suspect until `php artisan typescript:transform` confirms the current behavior.
- If a DTO has legacy callers, keep the runtime contract first and plan an incremental migration.
- If the class represents business truth but nullable fields exist only because update forms send nulls, split mutation and display contracts instead of widening display optionality.
