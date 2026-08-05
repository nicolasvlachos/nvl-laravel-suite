# Upgrading NVL Filterable

## Upgrading to 1.0

Version 1.0 no longer reads the Request facade from a model trait and never accepts raw user columns or relations.

1. Declare filter definitions and sort aliases on the filtered model or query boundary.
2. Convert HTTP input with `QueryFilterSetFactory`.
3. Pass the resulting `FilterSet` to `EloquentFilterApplier` or the `Filterable` trait.
4. Replace unknown-column pass-through with explicit aliases.
5. Add handlers for any application-specific relation or database behavior.

Unsupported operators, malformed values, and complexity overflow now fail closed.

The finalized 1.0 contract includes these changes from early previews:

- Replace `FilterOperator::Not` / `not` with the unambiguous `NotEquals` / `not_equals` or `NotContains` / `not_contains`.
- Pass `SortDirection::Asc` or `SortDirection::Desc` to programmatic `SortCriterion` instances.
- Remove the third `SortDefinition` constructor argument. Express default direction with `defaultSorts: ['-created']`.
- Remove custom filter casters. Use a declared `FilterValueType`; custom handlers now receive normalized criteria.
- Use `fromHttpQuery()` in controllers to return safe 422 validation errors. Keep `fromQuery()` for transport-neutral callers that handle `FilterableException`.
- Explicit HTTP filter objects must contain only `operator` and the required `value`. Null-check operators must omit `value`.
- Add `maximumSorts`, `maximumValuesPerFilter`, and `maximumStringLength` where endpoint-specific limits differ from the defaults.
- Declare a sort alias and `tieBreakerSort` for deterministic pagination.

Boolean, integer, decimal, date, date-time, list, range, and empty-string parsing is now strict. Review clients that sent values such as `off`, scientific decimals, relative dates, rollover dates, timezone-less timestamps, empty strings, or dummy null-check values.
