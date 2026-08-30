# Changelog

All notable changes to `nvl/filterable` are documented here.

## [Unreleased]

## [2.0.0] - 2026-08-29

### Changed

- Declared Laravel 13 and `nvl/data` 2.x compatibility while preserving typed
  `FilterSet` contracts as the explicit Suite 2.0 consumer-query boundary.

## [1.0.7] - 2026-08-22

### Changed

- Aligned the documented runtime requirement with the PHP 8.4+ package
  baseline.

## [1.0.5] - 2026-08-12

### Changed

- Corrected the historical v1.0.0 release date and classified its already
  shipped filtering work under that stable release.

## [1.0.0] - 2026-08-08

- Added typed, pure `FilterSet` contracts and an isolated HTTP adapter.
- Added allowlisted filter and sort definitions with bounded relation complexity.
- Added portable scalar, set, range, null, date, and date-time semantics.
- Removed implicit request parsing and faceted-search claims.
- Replaced ambiguous `not` semantics with `not_equals` and `not_contains`.
- Added strict query-object parsing, strict scalar/date normalization, and literal wildcard escaping.
- Added sort, set-value, and string-length complexity limits plus duplicate-sort rejection.
- Added stable exception codes and paths with an HTTP-safe 422 adapter.
- Added enum-backed sort directions, deterministic tie-breaker sorting, and indexed schema lookups.
- Added schema-time identifier, operator/type, nullability, and enum validation.
- Split value normalization from Eloquent predicate application and normalized custom-handler criteria.
- Added generated TypeScript value unions and sort directions.
- Added SQLite, PostgreSQL, and MySQL package coverage in CI.
