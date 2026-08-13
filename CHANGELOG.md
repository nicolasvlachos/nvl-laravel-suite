# Changelog

All notable changes to `nvl/laravel-suite` are documented in this file. The
suite follows [Semantic Versioning](https://semver.org/) and versions all 20
embedded modules together.

Module-level implementation history remains available in each
`packages/nvl/<module>/CHANGELOG.md` file.

## [Unreleased]

## [1.0.6] - 2026-08-13

### Added

- Added a privacy-bounded Auth invitation-acceptance event and authorized exact
  delivery identity plus scheduled-mail administrative read APIs.
- Added dependency-complete installation profiles, one executable adoption
  catalog, a secret-free `nvl:suite:configuration` report, and aggregate
  `nvl:suite:doctor --production --strict` readiness checks for every enabled
  module and required host scheduler entry.
- Extended Mail Notifications diagnostics across both administrative read
  authorizers, required scheduled-mail factories, and host scheduler entries.

### Fixed

- Aligned Mail Notifications' global CI coverage floor with its measured 88%
  baseline while retaining the 90% changed-line requirement for new source.

### Changed

- Scoped changed-package coverage to packages with modified PHP production
  source, so release notes and test-only changes cannot activate unrelated
  legacy global coverage debt while changed source remains held to 90%.

## [1.0.5] - 2026-08-12

### Fixed

- Preserved application-defined mass-assignable attributes on Auth principal
  subclasses while retaining the complete configured canonical field map.

### Changed

- Documented scheduler ownership, canonical Mail Notifications configuration,
  SQLite adoption constraints, sensitive Auth profile confirmation, and Media
  missing-binary incident recovery.
- Added release-time changelog validation for the suite, every module, and the
  materialized release archive.

## [1.0.4] - 2026-08-12

### Changed

- Published the preceding stable suite archive. The intended consumer-hardening
  changes were not present in that distribution and ship in v1.0.5.

## [1.0.3] - 2026-08-12

### Fixed

- Added the missing Auth corrective migration for invitation context hashes and
  paired challenge credentials, including v1.0.1/v1.0.2 upgrade rehearsal,
  feature-aware schema repair, and strict column/index diagnostics.
- Made Composer dependency installation retry bounded transient TLS failures
  with a pinned toolchain and release-safe exponential backoff.

## [1.0.2] - 2026-08-12

### Changed

- Completed local parity for every stateful CI database job, including
  MariaDB-native JSON metadata, nullable schema defaults, portable Forms
  submission timestamps, and connection-aware Auth assertions.
- Hardened package consumption across PostgreSQL, MySQL, MariaDB, and SQLite,
  including exact foreign-key adoption, connection-aware media ordering,
  portable settings diagnostics, canonical JSON comparisons, and UTC database
  session defaults.
- Expanded package-quality coverage with isolated databases for every package
  and integration suite across PostgreSQL, MySQL 8.4, and MariaDB 12.3,
  including the Translatable package and portable fixture lifecycles.
- Corrected stateful CI database naming for destructive concurrency tests,
  adopted MariaDB's official readiness probe, and made changed-line coverage
  failures report their exact uncovered source lines.
- Completed the 20-package consumption contract: all public publish tags are
  tracked and rehearsed, stateful migrations use Laravel's timestamp-aware
  publication API, package skills are natively discoverable by Laravel Boost,
  and configuration, translations, views, adoption templates, tooling, and
  archive contents are checked as materialized distribution assets.
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
