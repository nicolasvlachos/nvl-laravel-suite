---
name: nvl-templates
description: Implement, integrate, test, or review nvl/templates in Laravel 12–13. Use for directly constructed Template values, typed renderer/PDF options, Blade views, Content compositions, stored template definitions and versions, assignments, queued renders, output responses, view publication, APIs, or package architecture.
---

# NVL Templates

Treat Templates as a composition and rendering package with two layers that
share one pipeline:

1. `Template`, typed options, renderer contracts, Blade/PDF implementations,
   verified output, and response helpers.
2. The database implementation for definitions, localized metadata, versions,
   Content snapshots, assignments, and durable renders.

## Render directly

- Construct `Template` from a source-controlled view, bounded data, optional
  schema, optional Content composition, settings, and `TemplateOptions`.
- Use `RenderTemplateAction` for directly constructed templates.
- Use `TemplateResponseFactory` for protected inline/download responses.
- Extend `Template` for domain-specific typed constructors when useful.
- Keep ordinary Blade values escaped.
- Render rich text only through Content's sanitized DTO/component.

## Adopt class templates

- Extend `BaseTemplate` or `BasePdfTemplate` for source-controlled class
  templates that need the reusable fluent API.
- Resolve subclasses through Laravel's container; constructor dependencies are
  injected and no static application lookup is required.
- Use `ContentAccessor` and `AssetAccessor` in class-template Blade views.
- Supply persisted copy through `withComposition()` and keep binary ownership
  in Media.
- Bind `TemplateAssetResolver` for frame, sticker, or scoped asset handles.
- Keep local assets under configured roots; exact-allowlist every remote host.
- Treat raw header/footer HTML as trusted source only. Stored definitions use
  header/footer view names.
- Keep PDF image diagnostics disabled outside explicitly enabled debug
  environments.

## Configure rendering

- Use `TemplateOptions` for renderer, locale, subject, filename, custom driver
  options, and `PdfOptions`.
- Use `PdfMargins`, `PdfPageSize`, and `PdfOrientation` instead of untyped
  option strings in application code.
- Configure renderer aliases through `TemplateRendererRegistry`.
- Implement `TemplateRenderer` for additional formats.
- Return exact MIME, byte-size, checksum, and safe filename facts in
  `RenderedTemplateData`.
- Keep output and HTML byte limits enabled.

## Render PDFs safely

- Use `MpdfTemplateRenderer` through the `pdf` alias.
- Configure page, margins, fonts, DPI, image quality, metadata, header/footer
  views, watermark, compression, and PDF/A through typed options.
- Use trusted Blade views for headers and footers; never accept raw executable
  template source from a database or caller.
- Keep remote resources disabled or restrict them to HTTPS and exact hosts.
- Keep mPDF temporary files beneath configured allowed roots.
- Reject traversal, absolute local paths outside configured asset roots, unsafe
  schemes, credentialed URLs, oversized data images, and unsafe HTML elements.

## Use the database implementation

- Keep executable definitions and renderer options source-controlled.
- Synchronize definitions with `nvl:templates:sync`.
- Use `CreateTemplateAction` and `CreateTemplateVersionAction` for stored state.
- Compose version content through the `TemplateVersion` model’s reserved
  `document` Content group; it implements `ContentOwner` with `HasContent`.
- Consume the injected `Nvl\Content\Content` application surface for capture
  and snapshot rendering, and adapt authorization identity with
  `TemplateActorData::contentActor()`.
- Persist immutable content through `ContentCompositionSnapshotCast`; keep the
  model attribute typed as `ContentCompositionSnapshotData`.
- Publish with `PublishTemplateVersionAction` and the exact revision.
- Use `RenderStoredTemplateAction` for synchronous database-backed rendering.
  It resolves the publication and delegates to `RenderTemplateAction`.
- Use `AssignTemplateAction` and `UnassignTemplateAction` for owner/profile
  mappings.
- Use `QueueTemplateRenderAction` for durable idempotent work.
- Preflight queue requests before persistence and snapshot the resolved version,
  profile, payload, and assignment settings on the render record.
- Keep the database lease longer than the job timeout and the unique lock at
  least as long as the lease. Keep `pending_recovery_seconds` above the unique
  lock duration. Recover failed queue pushes and expired leases with
  `nvl:templates:renders:recover`.
- Let only the matching processing token complete or fail a claimed render.
- Use `GetTemplateRenderAction` and `ListTemplateRendersAction` for authorized
  history; never expose payload, settings, failure text, or processing tokens.
- Keep payload-related values JSON-only and use only the built-in bounded JSON
  Schema keywords unless the application binds a custom validator.
- Never write package, Content, or Media tables directly.
- Keep management and stored-render routes disabled until authorization and
  middleware are configured.

## Views

- Use or publish the bundled HTML/PDF document, header, footer, section, table,
  and page-break starting points.
- Publish to the default path with the `templates-views` tag.
- Use `nvl:templates:views:publish` for a guarded custom path.
- Keep destinations beneath `templates.views.allowed_publish_roots`.

## Verify

Run `nvl:templates:doctor --strict --format=json`; use `--scope=core` for
core-only adoption. Also run the Templates Pest suite, Pint, PHPStan at maximum
strictness, generated TypeScript checks, database matrices, cached boot/routes,
and archive inspection.
