# Changelog

All notable changes to `nvl/laravel-suite` are documented in this file. The
suite follows [Semantic Versioning](https://semver.org/) and versions all 20
embedded modules together.

Module-level implementation history remains available in each
`packages/nvl/<module>/CHANGELOG.md` file.

## [Unreleased]

## [1.0.1] - 2026-08-09

### Added

- Added staged module adoption through `config/nvl-suite.php`, including
  dependency-safe provider selection and strict module flag validation.

### Changed

- Documented the TypeScript transformer v3 consumer preflight and the canonical
  automated release procedure.

## [1.0.0] - 2026-08-08

### Added

- Initial stable release of the 20-module NVL Laravel Suite.
- Added the public single-package distribution, Packagist metadata, clean
  Composer archive, release rehearsal, and automated tagged-release workflow.
- Added one canonical API and usage guide for every embedded module.

### Changed

- Consolidated the former module package family into one installable
  `nvl/laravel-suite` library while retaining module namespaces and explicit
  internal boundaries.
