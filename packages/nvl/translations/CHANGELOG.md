# Changelog

All notable changes to `nvl/translations` are documented here.

## [Unreleased]

## [1.0.7] - 2026-08-22

### Changed

- Aligned the documented runtime requirement with the PHP 8.4+ package
  baseline.

## [1.0.5] - 2026-08-12

### Changed

- Released unchanged under the suite's shared version.

## [1.0.2] - 2026-08-12

- Excluded development-only tests and static-analysis configuration from
  release archives and removed obsolete empty package directories.
- Added consumer-contract coverage for every workspace command, strict
  diagnostics, authorization, public validation, filters, and conflict
  responses.
- Corrected repeated missing-entry reporting and rejected null-leaf/nested PHP key collisions during export.
- Added durable scan-run records and scope-aware usage identities so zero-hit, same-second, and same-line multi-scope scans produce correct unused reports.
- Kept PHP and JSON usage identities separate and excluded already-missing source rows from unused reports.
- Rejected ambiguous PHP dot-key segments, catalog output leakage, scanner/import symlink escapes, ambiguous namespaces, unsafe artifact plans, and case-insensitive destination collisions.
- Blocked exports after incomplete authoritative reads and aligned `changed_since_import` with durable synchronization state.
- Tightened API list shapes, required update values, command option validation, doctor checks, configuration typing, path normalization, and catalog query indexes.

## [1.0.0] - 2026-08-08

- Added authoritative PHP and JSON language-file scanning and database workspace synchronization.
- Added case-sensitive SHA-256 catalog identities with unrestricted long keys and exact UTF-8 string/null value fidelity.
- Added configurable source and distinct named target profiles, source hashes, conflicts, typed status, shared locks, staged batch export, rollback, backups, and pruning.
- Added path, symlink, encoding, malformed-file, duplicate/overlapping-scope, target-overlap, and vendor-write protections.
- Added scoped literal-key scanning, configurable patterns, retained usage history, and unused-key reporting.
- Added disabled-by-default authorized workspace APIs with optimistic revisions, independent prune authorization, force requirements, and stable error responses.
- Added Laravel 12–13, PHP 8.3–8.4, SQLite/MySQL/PostgreSQL quality coverage and the documented migrations and skill publish tags.
