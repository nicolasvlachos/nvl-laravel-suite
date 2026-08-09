# Changelog

All notable changes to `nvl/data` are documented here.

## [Unreleased]

## [1.0.1] - 2026-08-09

- Added validated PHP-to-TypeScript replacement maps with legacy host-map
  compatibility and explicit configuration precedence.
- Made transformer warnings fail generation and freshness checks before
  incomplete declarations can be published or accepted.
- Added staged, serialized TypeScript generation with rollback-safe publication.
- Added split namespace declaration output, configurable scope mappings, and application-compatible Data extraction.
- Bound HTTP delivery to a persisted integrity manifest with checksum, file-count, size, symlink, and archive limits.
- Added recursive Optional filtering, nesting limits, and normalized-key collision detection for model transforms.
- Added exact TypeScript name/location discovery, duplicate public-symbol checks, and manifest-wide revisions.
- Made request-time publication locks fail fast with retryable responses and bounded manifest writes.
- Rejected empty delivery middleware and generated-artifact path collisions before publication.
- Normalized ZIP entry timestamps so content-addressed archives remain byte-stable.
- Added `nvl-data.php` to Laravel's conventional `config` publish group.
- Removed the obsolete `RecordTypeScriptType` compatibility processor.

## [1.0.0] - Unreleased

- Added the package-family DTO and persistence-transform boundary.
- Added typed pagination DTOs and deterministic source registration.
- Added generated TypeScript manifests, stale checks, integrity checks, and protected artifact delivery.
- Standardized generated declarations under `Nvl.*`.
