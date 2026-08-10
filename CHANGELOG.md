# Changelog

All notable changes to `nvl/laravel-suite` are documented in this file. The
suite follows [Semantic Versioning](https://semver.org/) and versions all 20
embedded modules together.

Module-level implementation history remains available in each
`packages/nvl/<module>/CHANGELOG.md` file.

## [Unreleased]

### Changed

- Added Auth principal attribute mapping, dry-run-first first-party principal
  adoption, physical relationship-collision diagnostics, and feature-aware
  schema reconciliation, with sparse validated DTO persistence for partial
  principal mutations.
- Added dry-run-first Mail Notifications legacy adoption plus fail-closed,
  privacy-bounded administrative list, show, statistics, and suggestion APIs.
- Added versioned strict TypeScript generation flags and generated-artifact
  lint/format exclusions, registered Mail Notifications public enums for
  declaration generation, and made CSV exports accept concrete Eloquent
  builders without static-analysis errors.
- Separated automatic vendor migration loading from host-owned published
  migrations across Auth, Activity, Comments, Media, and Mail Notifications,
  including timestamp-aware publishing and strict duplicate-owner diagnostics.
- Added fail-closed Templates schema ownership checks, a staged
  Templates/Content/Media adoption workflow, bounded Content scope resolution,
  and an opt-in revision-aware Media asset resolver for class templates.

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
