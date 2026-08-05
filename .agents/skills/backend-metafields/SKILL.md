---
name: backend-metafields
description: Use when implementing, querying, validating, translating, or reviewing nvl/metafields definitions and owner values, including polymorphic attachment, typed storage, references, localized definition copy, and localized values.
---

# Backend Metafields

Use definitions as the schema and metafields as owner-specific values. Route all mutations through package Actions.

## Model the field

- Address definitions by the canonical `namespace.key` handle.
- Keep type, validation, required state, translatable state, position, and reference model type on `MetafieldDefinition`.
- Store localized `title`, `description`, `hint`, `default_value`, and `properties` in definition translation rows.
- Store nonlocalized values in `metafields.value`.
- Store localized values in `metafield_translations.value`.
- Store references in `referenced_id`; never serialize models into value JSON.

Only allow translations for string, text, rich text, JSON, array, and URL definitions. Reject translations for boolean, numeric, date/time, color, and reference definitions.

## Mutate safely

1. Build `MutateMetafieldDefinitionPayload`, `SyncOwnerMetafieldValuePayload`, or `SyncOwnerMetafieldsPayload`.
2. Resolve definitions and owners through registered package mechanisms.
3. Execute the matching Action.
4. Use patch semantics unless replacement was explicitly requested.
5. Dispatch events after commit.

Never bypass type validation or call `updateOrCreate` on translation relations from a controller.

## Read efficiently

- Use `Metafield::getValue($locale)` for a typed resolved value.
- Use definition display helpers for localized labels and hints.
- Eager-load `definition.translations` and value `translations` for lists.
- Use the catalog/field services for consumer payloads.
- Return raw base values only for nontranslatable definitions.

## Verify

Test every type cast, required and validation rules, unsupported translated types, raw-value regressions, definition fallback, value fallback, explicit clears, patch/replace behavior, reference resolution, polymorphic owner isolation, eager-loading, and after-commit events.
