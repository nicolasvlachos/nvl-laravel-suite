# Changelog

All notable changes to `nvl/support` are documented here.

## [Unreleased]

## [1.0.5] - 2026-08-12

### Changed

- Corrected the historical v1.0.0 release date and classified its already
  shipped support contracts under that stable release.

## [1.0.0] - 2026-08-08

- Added transport-neutral `BusinessException` and `ResponseCode`.
- Added stable machine codes, suggested presentation statuses, safe public context, internal diagnostics, and exception chaining.
- Removed DTO, TypeScript registry, pagination, domain, route, model, and persistence responsibilities.
- Enforced backed response-code implementations directly through the `ResponseCode` contract.
- Strengthened standalone boundaries, response-code validation coverage, publication checks, and architecture verification.
