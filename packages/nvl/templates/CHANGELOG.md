# Changelog

All notable changes to `nvl/templates` are documented here.

## [Unreleased]

## [1.0.2] - 2026-08-12

- Added a versioned staged-adoption command with schema inventory, explicit
  key/scope/locale/Media maps, staging-index preparation, Action-backed writes,
  idempotent reconciliation, and fail-closed asset counts.
- Added a compatibility preflight migration and expanded Doctor checks for
  every canonical Templates column and named index.
- Added an opt-in revision-aware NVL Media asset resolver, scoped alias
  registry, and source-alias adoption helper for class templates.
- Added consumer contract coverage for opt-in HTTP management/rendering, bounded JSON Schema validation, PDF configuration, and distribution hygiene.
- Added source-controlled definitions, renderer and owner registries.
- Added UUID template, translation, version, assignment, and render schemas.
- Added Content block compositions, immutable publication snapshots, current
  Media/reference resolution, optimistic revisions, and synchronous or queued
  rendering.
- Added bundled mPDF rendering with bounded page options, dedicated temp
  storage, dangerous-HTML rejection, and fail-closed remote asset allowlists.
- Added source-schema validation, render payload validation, canonical content
  and request hashing, draft updates, assignment removal, and concurrent
  idempotency recovery.
- Added fail-closed authorization, configurable opt-in APIs, diagnostics, and
  definition synchronization.
- Removed parallel version-string and template-asset persistence in favor of
  `nvl/content` fields, locale rows, placements, and Media integration.
- Uses the configured Templates database connection for every Action-owned
  transaction and after-commit queue dispatch.
- Added the directly constructible `Nvl\Templates\Template` core, typed
  `TemplateOptions`, `PdfOptions`, `PdfMargins`, and one shared renderer
  context for direct and database-backed templates.
- Added guarded publishable HTML/PDF document, header, footer, section, table,
  and page-break Blade foundations.
- Added typed PDF header/footer views, watermark, compression, PDF/A, metadata,
  orientation, margins, font, DPI, and image-quality overrides.
- Added verified output size/checksum facts and protected inline/download
  responses through `TemplateResponseFactory`.
- Added `RenderStoredTemplateAction` as the database adapter into
  `RenderTemplateAction`; synchronous APIs and queued work use the same
  rendering pipeline.
- Changed synchronous render APIs to return actual HTML/PDF bytes instead of
  JSON-encoding potentially binary output.
- Added the reusable `BaseTemplate`/`BasePdfTemplate` class surface,
  `PdfServiceInterface`, `GeneratedPdfInterface`, HTML payload/context
  adapters, fluent PDF configuration values, and guarded Content/asset view
  accessors.
- Preserved class-template configuration, variation, frame/sticker,
  generation, response, and storage methods while replacing static lookups
  with injected contracts and one verified rendering pipeline.
- Added bounded local/inline/remote asset policies, safe per-template temporary
  directory overrides, production-gated image diagnostics, validated raw
  source-defined headers/footers, and atomic local PDF persistence.
- Added ancestor-symlink-safe path resolution for view publication, PDF
  persistence, and temporary directories.
- Added strict JSON-only content guards, exact source-definition/PDF option
  keys, a documented bounded JSON Schema subset, and fail-closed route
  middleware configuration.
- Added one shared stored-render/version resolver and consistent published or
  retired assignment-pin policy.
- Added leased queue ownership, overlap and uniqueness locks, terminal-timeout
  handling, immutable assignment-settings snapshots, bounded recovery for
  expired leases and failed queue pushes, and queue configuration diagnostics.
- Consolidated durable render processing fields and recovery indexes into the
  unreleased package’s clean render-table migration.
- Added binary signature validation for data-image resources embedded directly
  in rendered PDF HTML.
- Serialized publication on the template aggregate, restricted publication to
  active synchronized drafts, and advanced retired-version revisions.
- Applied payload-retention policy to terminal failures and withheld partial
  output Media references unless a render completed successfully.
- Wired the configured default page size into both history APIs and added its
  bounds to doctor diagnostics.
- Added authorized render history/status APIs with private transport DTOs and
  no-store response headers.
- Made definition synchronization atomic and deterministic, with safe
  reactivation of restored source definitions.
- Added scoped core/database doctor checks and name-preserving migration
  publication.
- Preserved registered inactive templates during definition synchronization
  while continuing to reactivate restored archived definitions.
- Added owner, profile, and pinned-version context to assignment and stored
  render authorization so consumer policies can enforce domain ownership.
- Allowed class-template PDF assets only when absolute local paths pass the
  configured root, file, and size guard.
- Confined class-template save filenames to safe PDF basenames and made output
  disk diagnostics conditional on output persistence being enabled.
- Restored configured class-template page numbering without replacing trusted
  header/footer HTML and hardened PDF resource scanning for quoted whitespace
  and escaped URL payloads.

## [1.0.0] - 2026-08-08

- Initial coordinated package-family release for Laravel 12–13.
