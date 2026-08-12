# Changelog

All notable changes to `nvl/content` are documented here.

## [Unreleased]

## [1.0.2] - 2026-08-12

- Normalizes MariaDB `longtext` JSON columns and string `NULL` defaults during
  portable schema diagnostics.
- Adds a bounded non-paginated scope-resolution contract with ordered fallback,
  locale resolution, publication/visibility enforcement, explicit overflow,
  authorization query scoping, and `scope in [...]` catalog filtering.
- Adds source-controlled block definitions and deterministic synchronization.
- Adds localized blocks, scoped reuse, placements, trees, revisions, and optimistic concurrency.
- Adds built-in and custom field adapters, JSON Schema validation, safe rich text, media and reference fields.
- Adds canonical semantic `link`, `button`, `image`, `heading`, and `banner`
  presets, compiled preset/definition JSON Schemas, typed render DTOs and
  enums, safe relative URIs, and an authorized preset catalog/API.
- Separates internal source definitions from typed compiled definition DTOs,
  makes preset fields and snapshot block/schema graphs strongly typed, and
  publishes those exact recursive contracts through `nvl/data` TypeScript
  generation.
- Adds final locale-resolved preset validation and JSON Schema customization;
  published non-decorative images require accessible alt text, and relative
  semantic links reject backslashes.
- Supports localized leaves inside structured values with schema-aware
  field-level fallback while preserving base Media, links, layout, and stable
  repeater structure across live and snapshot rendering.
- Adds headless DTO rendering, Blade components, optional APIs, doctor and publishing commands.

## [1.0.0] - 2026-08-08
- Adds bounded immutable composition snapshots with canonical integrity hashes
  and current Media/reference resolution for publishing consumers.
- Shares Translatable's exact locale fallback chain for live and snapshot
  rendering, rejects remote JSON Schema reference variants, and guards view
  publication from traversal and symlink escape.
- Binds snapshot integrity to owner identity and validates missing parents,
  regions, depth, size, and cycles during render.
- Prevents hidden/private/unpublished parents from promoting otherwise visible
  descendants into a composition.
- Adds definition discovery, owner placement inspection, authorized draft
  preview, and revision-safe placement removal Actions and API routes.
- Uses string-compatible actor columns, localized Media associations, typed
  private Media projections, bounded definition discovery, safe URL schemes,
  and same-connection checks for atomic Content/Media writes.
- Makes patch semantics the non-destructive update default and expands strict
  diagnostics for schema columns, indexes, routes, views, definitions, and
  database connections.
- Adds revision-safe block restoration as a draft with transactional Media
  reattachment and an after-commit restored event.
- Excludes tests, static-analysis configuration, coverage output, and test-run
  caches from release archives; the family archive gate rejects build-only
  files.
- Strictly compiles definition keys, property types, recursive field
  structure, reference aliases, and JSON Schemas at boot, and requires version
  progression for contract changes.
- Preserves nested placement override intent, rejects normalized locale
  collisions, shares the request-scoped Content locale, and recursively bounds
  arbitrary JSON, metadata, reference, and snapshot payloads.
- Adds stable API error codes, PATCH support, JSON object/list fidelity,
  recursive generated schema/snapshot types, and optional TypeScript mutation
  properties that match PHP defaults.
- Delegates real Pages/Templates actors into Content, supplies complete
  owner-aware reference contexts, and serializes placement tree mutations with
  atomic owner-group locks.
- Replaces owner resolver classes with registered Content-capable Eloquent
  models, adds the `HasContent` relationship trait and model-first facade, and
  makes named composition groups first-class across persistence, APIs,
  rendering, locks, and immutable snapshots.
- Completes the facade with revision-safe create, update, publish, archive,
  delete, and restore block lifecycle methods.
- Adds deterministic sequential definition migrations with authorized dry-run
  plans, exact-revision atomic batches, final schema/translation/Media
  validation, migrated revisions/events, strict doctor coverage, and
  `definition_migration_required` conflicts instead of silent version adoption.
- Adds one generated `ContentEditorData` bootstrap contract and management API
  endpoint for consumer-owned Filament and other headless editors.
- Hardens lifecycle/query authorization, definition-sync locking, definition
  and placement migrations, migration-table ownership, semantic schema
  diagnostics, private Media delivery, structured localization, default and
  normalized-payload validation, URI-scheme safety, and generated JSON Schema
  parity with focused regression coverage.
