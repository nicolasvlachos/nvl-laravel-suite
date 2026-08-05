# Upgrading NVL Templates

## To 1.0

There is no supported pre-1.0 public API. Install the clean schema, register
source-controlled render definitions and Content block definitions, import
structural rows through package Actions, and publish versions explicitly.

Before an adoption cutover:

1. Disable `templates.migrations.enabled`.
2. Map existing keys, locales, revisions, assignments, strings, structured
   values, and binary assets.
3. Run `php artisan nvl:templates:doctor --strict --format=json`.
4. Import binaries through Media; model strings, structured values, and Media
   UUIDs as Content fields.
5. Place blocks on the `TemplateVersion` model’s reserved `document` Content
   group and publish immutable snapshots.
6. Compare row counts, snapshot hashes, and representative rendered output.
7. Enable package migrations only after table compatibility is proven.

Never persist executable PHP or arbitrary Blade source from an old system.
Do not recreate legacy version-translation or template-asset tables.
When adopting PDF templates, move executable views into application source,
map print options into source-controlled `renderer_options`, configure a
dedicated writable mPDF temp path, and explicitly allowlist every remote image
host. Do not import untrusted HTML as an executable template.

Direct rendering now uses `Nvl\Templates\Template` with `TemplateOptions` and
`RenderTemplateAction`. Database-backed rendering uses
`RenderStoredTemplateAction`, which resolves the stored publication and
delegates to the direct Action. Custom renderers receive
`TemplateRenderContext`, not the removed `TemplateRenderContextData`.

PDF header and footer overrides are trusted view names plus bounded data. Raw
`header_html` and `footer_html` are reserved for trusted, source-defined direct
or class templates and pass through the package HTML guard. Stored definitions
should use trusted header/footer views.
Synchronous render routes return the actual output body and MIME type. Clients
that previously expected JSON must consume the binary response or use the
queued-render API.

Durable render rows now include `profile`, encrypted `settings`,
`dispatch_generation`, `processing_token`, `lease_expires_at`, and `failed_at`.
Because there is no supported pre-1.0 schema, these fields and their recovery
indexes are consolidated into the clean render-table migration. Configure
`lease_seconds` above the job timeout, `unique_for` at least as long as the
lease, `pending_recovery_seconds` above `unique_for`, and the queue connection’s
`retry_after` above the timeout. Schedule `nvl:templates:renders:recover` to
redispatch stale pending records and expired processing leases.

Source definitions and PDF defaults now reject unknown keys. Payload-related
values must be JSON-only objects, and the built-in validator accepts only the
bounded schema keywords documented in the README. Replace unsupported schema
keywords or bind a custom `TemplatePayloadValidator`.

The opt-in render route group now includes authorized render history/status
endpoints. Its middleware list may not be empty. Non-system users can access
only records matching their requester identity, and all render responses send
private no-store cache directives.

Source-controlled class templates may migrate incrementally to
`Nvl\Templates\Templates\BaseTemplate` or
`Nvl\Templates\Templates\BasePdfTemplate`. The reusable fluent methods remain,
but constructors now receive rendering and asset contracts through the
container. Replace custom string/asset persistence with Content compositions
and Media identifiers. Bind `TemplateAssetResolver` only when frame, sticker,
or scoped asset handles are required. Keep application-specific template
subclasses and authorization in the consuming application.
