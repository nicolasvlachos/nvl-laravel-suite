---
name: nvl-filterable
description: Implement, integrate, test, or review nvl/filterable in Laravel 13. Use for allowlisted Eloquent filters, typed filter sets, HTTP query parsing, safe relation filtering, sort aliases, operator rules, complexity limits, or database-portable filtering.
---

# NVL Filterable

Treat filtering as an explicit allowlisted query contract, not a general search or facets engine.

## Declare filters

- Define every accepted alias, target column or relation, compatible operator set, value type, null behavior, and custom handler.
- Declare sort aliases, deterministic defaults, and a stable pagination tie-breaker.
- Never pass user-provided column names, relation paths, or directions directly to Eloquent.
- Keep relation aliases shallow and bounded.

## Parse and apply

- Build a typed `FilterSet` independently of HTTP.
- Use `QueryFilterSetFactory::fromHttpQuery()` at request boundaries and `fromQuery()` for transport-neutral callers.
- Apply criteria through `EloquentFilterApplier` or the `Filterable` trait.
- Reject malformed query objects, booleans, ranges, sets, dates, arrays, duplicate sorts, unsupported operators, and excess filter/sort/value/string complexity.
- Treat `contains` and `not_contains` values as literal substrings by escaping SQL wildcard characters.
- Expect custom handlers to receive already-normalized criteria.
- Choose an explicit driver strategy where string or date behavior differs by database.

## Verify

Test injection payloads, unknown aliases, null and empty values, booleans, sets, ranges, date-time zones, relations, custom handlers, default sorting, complexity limits, and SQLite/PostgreSQL/MySQL parity.
