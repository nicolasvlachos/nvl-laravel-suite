# Changelog

All notable changes to `nvl/csv` are documented here.

## [Unreleased]

### Added

- Added scalar tenant work manifests, class-resolved handlers, captured queue
  envelopes, exact cleanup, and adoption readiness checks.

### Fixed

- Use empty proprietary escape defaults and presets so embedded quotes and trailing backslashes survive standard CSV round trips; analyzer reads use the same convention. Explicit legacy escape settings remain available.
- Preserve the first row’s inferred export field order across reordered or incomplete rows, and reset inferred fields between exports.
- Preserve the first headerless input row on non-seekable and decoded streams without attempting to rewind the input.

## [2.0.0] - 2026-08-29

### Changed

- Declared Laravel 13 and `nvl/data` 2.x compatibility while preserving the
  typed analysis, import, export, and queued-processing consumer API.

## [1.0.7] - 2026-08-22

### Fixed

- Added explicit import transaction connection selection so whole-import and
  batch rollback guarantees apply to non-default Laravel connections.

### Changed

- Aligned the documented runtime requirement with the PHP 8.4+ package
  baseline.
- Extracted connection-aware transaction ownership from the stateful importer
  into a focused internal runner without changing the fluent import API.

## [1.0.5] - 2026-08-12

### Changed

- Released unchanged under the suite's shared version.

## [1.0.2] - 2026-08-12

- Made `CSVExport::fromQuery()` generic over its concrete Eloquent model so
  invariant `Builder<ConsumerModel>` types pass maximum-level PHPStan.
- Excluded tests, static-analysis configuration, coverage output, and test-run
  caches from production release archives.
- Fixed nested transaction ownership, error-threshold handling, strict row-shape validation, and bounded failure diagnostics.
- Applied import DTO column mappings, types, validation policy, and caller metadata consistently.
- Corrected UTF-32 BOM detection, boolean and JSON cast/validation consistency, exact date validation, UTC ISO output, and legacy-encoding BOM behavior.
- Improved logical-record delimiter detection, sampled large-file quality scoring, inferred export headings, common PHP value serialization, and chunked-array memory use.

## [1.0.0] - 2026-08-08

- Ported the complete `App\Lib\CSV` callable surface to `Nvl\Csv`.
- Added typed analysis, options, progress, import, and export Data objects with generated TypeScript registration.
- Added local and Laravel-disk streaming, BOM-aware input decoding, consistently encoded output streams, validators, transformers, filters, field mappings, and result objects.
- Added operation-local duplicate policies and explicit transaction behavior.
- Added serializable, storage-staged Laravel batch processing with tracking and cancellation.
- Added strict static analysis and comprehensive Pest coverage for compatibility and edge cases.

<!-- tenancy-program-p2 -->
Configurable-tenancy implementation and adoption documentation are present. The final consolidated verification matrix is pending; do not treat this package as release-ready until that gate passes.
