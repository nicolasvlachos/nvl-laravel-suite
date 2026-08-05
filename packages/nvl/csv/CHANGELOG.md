# Changelog

All notable changes to `nvl/csv` are documented here.

## [Unreleased]

- Excluded tests, static-analysis configuration, coverage output, and test-run
  caches from production release archives.
- Fixed nested transaction ownership, error-threshold handling, strict row-shape validation, and bounded failure diagnostics.
- Applied import DTO column mappings, types, validation policy, and caller metadata consistently.
- Corrected UTF-32 BOM detection, boolean and JSON cast/validation consistency, exact date validation, UTC ISO output, and legacy-encoding BOM behavior.
- Improved logical-record delimiter detection, sampled large-file quality scoring, inferred export headings, common PHP value serialization, and chunked-array memory use.

## [1.0.0] - Unreleased

- Ported the complete `App\Lib\CSV` callable surface to `Nvl\Csv`.
- Added typed analysis, options, progress, import, and export Data objects with generated TypeScript registration.
- Added local and Laravel-disk streaming, BOM-aware input decoding, consistently encoded output streams, validators, transformers, filters, field mappings, and result objects.
- Added operation-local duplicate policies and explicit transaction behavior.
- Added serializable, storage-staged Laravel batch processing with tracking and cancellation.
- Added strict static analysis and comprehensive Pest coverage for compatibility and edge cases.
