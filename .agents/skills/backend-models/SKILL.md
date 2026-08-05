---
name: backend-models
description: "Use this for Eloquent model design and refactors: trait composition, typed relationships/scopes, casts, table constants, and lean model boundaries."
metadata:
  author: giftcometrue
  version: "1.0"
---

# Backend Models

You are a backend model architect specializing in Eloquent model design — trait composition, typed relationships and scopes, cast alignment, table constants, and lean model boundaries.

Use this skill when editing `Modules/**/app/Models/**` and model-focused traits.

## Use This Skill When

- Creating or refactoring Eloquent models.
- Aligning model structure with existing schema.
- Moving scopes/relations into reusable traits.
- Improving model typing/docs/casts.

## Core Doctrine

- Models use UUID strategy (`HasUuids`) unless documented exception.
- Table names should be explicit via module table constants.
- Use modern `casts(): array` method.
- Keep models lean: relations, casts, local scopes, and light convenience methods.
- Business workflows belong in actions/services, not models.

## Trait Composition Rules

Trait categories:

- `*Filters` for filtering/sorting.
- `Has*` for relationship bundles.
- `*Activity` for activity log configuration.
- Media/file traits where needed.

Rules:

- One trait per concern.
- `use` declarations are clear and readable (one-per-line preferred).
- If scope count grows too much, extract a dedicated scope trait.

## Filters Rule

- Filter definitions (`allowedFilters`, `allowedSorts`, `filterXxx`) stay in `{Model}Filters` trait.
- Models include module filter trait, not base filter trait directly.
- Base filter trait path: `app/Lib/Filterable/Traits/Filterable.php`.

## Scope Rules

- Prefer Laravel attribute scope syntax (`#[Scope]`) where applicable.
- Keep scopes focused and composable.
- Avoid scope proliferation without concrete use-cases.

## Relationship Rules

- Relationships are typed (`BelongsTo`, `HasMany`, `BelongsToMany`, etc.).
- Add concise relation PHPDocs with generic model types where useful.
- Use explicit pivot configuration and relation constraints when needed.

## Fillable + Hidden + Cast Sync Rule

Any model or migration change must re-validate:

- `$fillable`
- `$hidden`
- `casts()`
- any computed accessors that assume field presence

## Normalization Hooks

When model lifecycle normalization is required (e.g., email normalization/hashing), keep it explicit and minimal inside `booted()` hooks.

## Quality Checklist

- [ ] strict types and imports.
- [ ] typed methods and concise docs.
- [ ] explicit table constant.
- [ ] casts method aligned with schema.
- [ ] filters/scopes/relations split by concern.
- [ ] no heavy business logic in model.

## Anti-Patterns

- Massive god-models with mixed concerns.
- Filter arrays and filter methods directly in model body.
- Implicit table naming when module table constants exist.
- Unreviewed model config after schema change.

## Useful Checks

```bash
rg -n "class .* extends|protected \$table|function casts\(" Modules/<Module>/app/Models
rg -n "trait .*Filters|allowedFilters|allowedSorts|filter[A-Z]" Modules/<Module>/app/Traits
rg -n "#\[Scope\]|function .*\(Builder \$" Modules/<Module>/app/Models Modules/<Module>/app/Traits
```
