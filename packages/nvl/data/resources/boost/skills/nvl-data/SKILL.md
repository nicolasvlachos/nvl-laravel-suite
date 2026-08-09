---
name: nvl-data
description: Implement, integrate, test, or review nvl/data on PHP 8.3–8.5 and Laravel 12–13. Use for Spatie Data DTOs, persistence transforms, paginated contracts, deterministic PHP-to-TypeScript generation, source registration, artifact manifests, stale checks, or protected generated-type delivery.
---

# NVL Data

Use this package as the package family's only DTO and PHP-to-TypeScript boundary. Do not move infrastructure-only objects into DTOs when transformation, validation, or transport typing adds no value.

## Define DTOs

- Extend Spatie `Data` and use the `DataTransform` trait where persistence mapping is required.
- Represent pagination with `PaginatedCollection` and `PaginationMeta`.
- Distinguish omitted `Optional` values from explicit `null`.
- Extract all eligible Data classes and backed enums while keeping native/Carbon date, collection, nested DTO, and mutation semantics stable across PHP and TypeScript.
- Publish generated symbols under `Nvl.<Package>.*`.

## Register type sources

- Register package or application sources through `TypeScriptSourceRegistry`.
- Use stable provider keys and priorities; duplicate keys or symbols must fail clearly.
- Keep roots inside configured project boundaries and reject traversal or symlink escape.
- Never add application-specific source paths to package configuration.

## Generate artifacts

- Run `nvl:data:types:generate` to create declarations and the manifest.
- Run `nvl:data:types:check` in CI to detect stale output.
- Run `nvl:data:types:manifest --write` when only the manifest must be refreshed.
- Treat explicit `#[TypeScript(name: ..., location: ...)]` values as the public symbol contract and fail duplicate public symbols.
- Use the manifest `revision` for catalog synchronization and its artifact `hash` for declaration/archive synchronization.
- Keep HTTP artifact routes disabled by default. Enabled routes serve manifest-listed files only and never generate in a request.
- Configure exceptional PHP references through validated `type_replacements`;
  transformer warnings fail generation and freshness checks.
- TypeScript Transformer 3.3 removed
  `Spatie\TypeScriptTransformer\Attributes\RecordTypeScriptType`; use
  `LiteralTypeScriptType('Record<string, unknown>')` for dynamic records.

## Verify

Test deterministic ordering, duplicate sources, invalid roots, symlinks, manifests, checksums, ETags, archive limits, combined package generation, DTO transforms, and `tsc --noEmit`.
