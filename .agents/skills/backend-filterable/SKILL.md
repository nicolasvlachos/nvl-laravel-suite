---
name: backend-filterable
description: Implement, integrate, test, diagnose, or review nvl/filterable in Laravel. Use for allowlisted Eloquent filters, typed FilterSet contracts, HTTP query parsing, safe relation filtering, operator/type rules, strict value normalization, deterministic sort aliases, query complexity limits, or database-portable filter behavior.
---

# Filterable Package

Treat `nvl/filterable` as a bounded allowlisted query layer. Do not use it as a faceted-search, aggregation, authorization, or arbitrary query-language engine.

## Define the contract

- Map every public alias to a fixed column or relation path through `FilterDefinition`.
- Declare the value type, compatible operators, enum values, and nullability explicitly.
- Use a custom handler only for bounded application-specific predicates. Expect its `FilterCriterion` value to be normalized already.
- Declare every sort through `SortDefinition`; express descending defaults with a `-` prefix in `defaultSorts`.
- Declare an `id`-style `tieBreakerSort` for deterministic pagination.
- Set endpoint-appropriate `maximumFilters`, `maximumSorts`, `maximumValuesPerFilter`, and `maximumStringLength`.

Never accept request-provided columns, relations, SQL fragments, or undeclared operators.

## Parse and apply

- Construct `FilterSet` directly for transport-neutral callers.
- Use `QueryFilterSetFactory::fromHttpQuery()` in controllers so malformed shapes and values become safe 422 validation errors.
- Use `QueryFilterSetFactory::fromQuery()` only when the caller handles `FilterableException`.
- Apply sets with `EloquentFilterApplier` or the model `Filterable` trait.
- Keep authorization outside filtering and complete it before exposing query results.

Use `not_equals` and `not_contains` for distinct negative semantics. Treat `contains` input literally; `%` and `_` are not caller-controlled SQL wildcards.

## Verify

Test exact HTTP shapes, stable error paths, unknown aliases, incompatible operators, strict booleans/numbers/dates, empty and oversized values, sets and ranges, null checks, escaped wildcards, relation filters, normalized custom handlers, duplicate and excessive sorts, tie-break ordering, injection payloads, and SQLite/PostgreSQL/MySQL parity.
