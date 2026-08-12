# Changelog

All notable changes to `nvl/primitives` are documented here.

## [Unreleased]

## [1.0.5] - 2026-08-12

### Changed

- Released unchanged under the suite's shared version.

## [1.0.2] - 2026-08-12

- Fixed fixed-currency decimal casts for SQLite `DECIMAL` hydration while continuing to reject floating-point input.
- Removed tests and static-analysis configuration from release archives.
- Added the `primitives-translations` publish tag for the bundled English and
  Bulgarian validation catalogs.

## [1.0.0] - 2026-08-08

- Added immutable validated value objects, Laravel casts, validation rules, and DTOs.
- Added exact money arithmetic, allocation, explicit rounding and currency contracts, directed conversion rates, deterministic formatting, and canonical minor-unit storage.
- Added strict RFC 3339 UTC instants, ordered BCP 47 core locale parsing, explicit `Length`, nullable postal codes, and complete weight conversions.
- Added strict null semantics and scalar fixed-currency modes to Eloquent casts.
- Added English and Bulgarian Laravel validation messages.
- Added ISO-backed reference catalogs with injected configuration, distinct locale labels, strict entries and deterministic unavailable or stale exchange-rate behavior.
- Added stable `Nvl.Primitives.*` TypeScript-facing contracts.
