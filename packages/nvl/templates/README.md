# NVL Templates — API and usage

[← NVL Laravel Suite](../../../README.md)

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/laravel-suite:^1.0` |
| Module identifier | `nvl/templates` |
| PHP namespace | `Nvl\Templates` |
| Service provider | `Nvl\Templates\Providers\TemplatesServiceProvider` |
| Configuration | `config/templates.php` |

`nvl/templates` is a composable Laravel 12–13 package for rendering
source-controlled Blade templates as HTML, PDF, or application-defined output.
It supports PHP 8.3 and newer.

Applications migrating class-based document templates can use the familiar
surface under `Nvl\Templates\Templates`, `Nvl\Templates\Pdf`, and
`Nvl\Templates\Html`. The reusable method contract remains available, but its
execution is now backed by the same bounded, verified renderer as direct and
stored templates.

The package deliberately has two layers:

1. A small rendering core built around `Nvl\Templates\Template`, typed options,
   renderer contracts, Blade starting views, verified output, and response
   services.
2. A complete optional database implementation for localized template
   metadata, draft/published versions, immutable Content snapshots, owner
   assignments, idempotent queued renders, management APIs, and render history.

Both layers use the same renderer registry and output validation. The database
layer resolves a stored version into the public `Template` class; it does not
maintain a second renderer architecture.

## Purpose and boundaries

Templates composes other NVL capabilities:

- Content owns editable blocks, localized copy, structured fields, regions,
  trees, and immutable composition snapshots.
- Media owns images, documents, private delivery, transformations, localized
  metadata, availability, and storage.
- Translatable owns localized database metadata.
- Filterable owns safe management-list filtering.
- Data owns public DTO and generated TypeScript contracts.
- Templates owns template selection, rendering options, Blade execution, PDF
  generation, stored publication versions, assignments, and render records.

Templates never stores arbitrary executable PHP or Blade source. Views and
renderer classes remain in source control. It has no custom strings or assets
table: use Content fields and Media references.

The package is headless. It does not ship an administration UI, email delivery,
business-specific documents, or consumer-domain authorization.

Composer installs the required package family and mPDF dependencies:

```text
templates
├── content
│   ├── media
│   ├── support
│   └── translatable
├── data
├── filterable
├── media
├── translatable
└── mpdf
```

## Installation

```bash
composer require nvl/laravel-suite:^1.0
php artisan migrate
php artisan nvl:content:definitions:sync
php artisan nvl:templates:sync
php artisan nvl:templates:doctor --strict --format=json
```

Laravel discovers `Nvl\Templates\Providers\TemplatesServiceProvider`
automatically.

Publish only the artifacts the application needs to own:

```bash
php artisan vendor:publish --tag=templates-config
php artisan vendor:publish --tag=templates-migrations
php artisan vendor:publish --tag=templates-views
php artisan vendor:publish --tag=templates-skills
```

Choose exactly one migration owner. For automatic vendor loading, leave
`templates.migrations.enabled=true` and do not publish `templates-migrations`.
For host-owned migrations, publish `templates-migrations`, set
`templates.migrations.enabled=false` before the first migration, and maintain
the copied files as application migrations. Never run both sources; Laravel
retimestamps published migrations.

Views can also be published to a guarded custom path:

```bash
php artisan nvl:templates:views:publish
php artisan nvl:templates:views:publish \
    --path=resources/views/documents \
    --force
```

The destination must remain beneath `templates.views.allowed_publish_roots`.
Existing files are preserved unless `--force` is supplied. Source links,
destination traversal, and symlink escapes are rejected.

## Render a directly constructed template

Create the public template value and execute the core Action:

```php
use Nvl\Templates\Actions\RenderTemplateAction;
use Nvl\Templates\Data\TemplateOptions;
use Nvl\Templates\Template;

$template = new Template(
    key: 'account-summary',
    view: 'documents.account-summary',
    data: [
        'memberName' => 'Ada Lovelace',
        'balance' => '125.00',
    ],
    options: new TemplateOptions(
        renderer: 'blade',
        locale: 'en',
        subject: 'Account summary',
        filename: 'account-summary.html',
    ),
    schema: [
        'type' => 'object',
        'properties' => [
            'memberName' => ['type' => 'string', 'maxLength' => 100],
            'balance' => ['type' => 'string', 'maxLength' => 32],
        ],
        'required' => ['memberName', 'balance'],
        'additionalProperties' => false,
    ],
);

$rendered = $renderTemplate->execute($template);
```

`RenderTemplateAction` returns `RenderedTemplateData` containing:

- output bytes;
- MIME type and renderer alias;
- exact byte size and SHA-256 checksum;
- optional subject;
- a safe suggested filename.

Direct rendering is an application service boundary and has no actor/resource
to authorize. The caller must authorize the surrounding use case before
constructing the Template. The database adapter performs its configured
template authorization internally.

Renderer output is rejected when its size/checksum facts are inconsistent,
when it exceeds `templates.limits.output_bytes`, when its MIME type or filename
is unsafe, or when PDF bytes do not have a PDF signature.

Applications can extend `Nvl\Templates\Template` for domain-specific,
constructor-typed templates:

```php
final class InvoiceTemplate extends Template
{
    public function __construct(InvoiceView $invoice)
    {
        parent::__construct(
            key: 'invoice',
            view: 'documents.invoice',
            data: ['invoice' => $invoice->toArray()],
            options: new TemplateOptions(
                renderer: 'pdf',
                locale: $invoice->locale,
                subject: "Invoice {$invoice->number}",
                filename: "invoice-{$invoice->number}.pdf",
            ),
        );
    }
}
```

The `view` argument may be omitted to use
`templates.views.defaults.<renderer>`.

## Blade contract and bundled views

Every source-controlled Blade view receives the same explicit variables:

| Variable | Value |
| --- | --- |
| `$template` | The public `Nvl\Templates\Template` instance |
| `$options` | The resolved `TemplateRenderContext` |
| `$data` | Bounded template data |
| `$settings` | Assignment or implementation-layer settings |
| `$composition` | Optional rendered Content composition |
| `$blocks` | Root Content blocks, or an empty list |
| `$regions` | Content blocks grouped by region, or an empty map |

Bundled starting views are:

- `nvl-templates::html.document`
- `nvl-templates::pdf.document`
- `nvl-templates::pdf.header`
- `nvl-templates::pdf.footer`

Bundled anonymous components are:

- `<x-nvl-templates::section>`
- `<x-nvl-templates::table>`
- `<x-nvl-templates::page-break>`

The document views render Content blocks when a composition exists and fall
back to escaped `data.content`. They are intentionally plain foundations, not a
consumer design system.

Blade must escape ordinary template data with `{{ }}`. Render rich text only
through Content’s sanitized renderer DTO/component path. Never use raw Blade
output for caller-provided data.

## Content and Media composition

A direct `Template` may receive an already rendered
`RenderedContentCompositionData`:

```php
$template = new Template(
    key: 'catalog-sheet',
    view: 'documents.catalog-sheet',
    data: ['reference' => 'SUMMER'],
    options: new TemplateOptions(renderer: 'pdf', locale: 'en'),
    composition: $composition,
);
```

The composition locale must match the requested template locale. Blade receives
its blocks and regions without additional queries.

Media values embedded in Content remain Media DTOs. Templates never assembles
storage paths, bypasses private-media authorization, or writes Media
association tables. Stored templates capture immutable Content values at
publication while resolving current Media availability and signed delivery at
render time.

## Typed PDF rendering

The bundled `pdf` renderer uses mPDF. Configure it through typed value objects:

```php
use Nvl\Templates\Data\PdfMargins;
use Nvl\Templates\Data\PdfOptions;
use Nvl\Templates\Data\TemplateOptions;
use Nvl\Templates\Enums\PdfOrientation;
use Nvl\Templates\Enums\PdfPageSize;

$options = new TemplateOptions(
    renderer: 'pdf',
    locale: 'en',
    subject: 'Quarterly report',
    filename: 'quarterly-report.pdf',
    pdf: new PdfOptions(
        pageSize: PdfPageSize::A4,
        orientation: PdfOrientation::Landscape,
        margins: new PdfMargins(
            left: 12,
            right: 12,
            top: 18,
            bottom: 16,
        ),
        defaultFont: 'dejavusans',
        defaultFontSize: 10,
        imageDpi: 144,
        imageQuality: 88,
        headerView: 'documents.pdf.header',
        headerData: ['label' => 'Internal'],
        footerView: 'documents.pdf.footer',
        watermark: 'DRAFT',
        watermarkOpacity: 0.08,
        compress: true,
        pdfa: false,
    ),
);
```

Supported page sizes are A3, A4, A5, Letter, and Legal. Page size,
orientation, six margins, font, font size, document/image DPI, image quality,
title, author, creator, subject, keywords, header/footer views and data,
watermark, compression, and PDF/A behavior can be set globally or per
template.

Global defaults live in `templates.pdf.defaults`. A per-template non-null
option overrides its corresponding default.

PDF safety defaults:

- generated HTML has a configurable byte ceiling;
- executable, embedded, form, import, and unsafe resource markup is rejected;
- absolute local paths outside configured asset roots, traversal, credentialed
  URLs, nonstandard remote ports, and unsafe schemes are rejected;
- remote resources are disabled unless HTTPS hosts are exactly allowlisted;
- data images are size bounded, MIME allowlisted, and byte-sniffed against
  their declared MIME type;
- the mPDF temporary directory must be writable beneath an allowed root;
- source-defined raw header/footer HTML is bounded and passes the same unsafe
  markup guard; stored definitions use trusted Blade views;
- final PDF bytes are output-size bounded and signature checked.

Treat every Blade view as trusted source code and every data value as untrusted.

## Reusable class-template API

Applications with class-based document templates may extend
`Nvl\Templates\Templates\BaseTemplate` or
`Nvl\Templates\Templates\BasePdfTemplate`. This surface preserves the familiar
template workflow while routing generation through `RenderTemplateAction`,
`PdfOptionsResolver`, `PdfHtmlGuard`, output verification, and protected
responses.

```php
use Nvl\Templates\Support\PdfConfig\Data\MetadataData;
use Nvl\Templates\Support\PdfConfig\Enums\PaperSize;
use Nvl\Templates\Templates\BasePdfTemplate;

final class StatementTemplate extends BasePdfTemplate
{
    protected function configure(): void
    {
        $this->setPageSize(PaperSize::A4);
        $this->setMetadata(new MetadataData(title: 'Statement'));
        $this->setOptions([
            'filename' => 'statement.pdf',
            'storage_path' => 'statements',
        ]);
    }

    protected function getViewPath(): string
    {
        return 'documents.statement';
    }

    public function getName(): string
    {
        return 'Statement';
    }

    public function getModule(): string
    {
        return 'Documents';
    }

    protected function getRequiredData(): array
    {
        return ['recipient_name'];
    }
}

$template = $container->make(StatementTemplate::class)
    ->setLanguage('en')
    ->withFallbackLanguage('bg')
    ->setData(['recipientName' => 'Ada'])
    ->setOption('reference', 'REF-100');

return $template->download('statement.pdf');
```

`setData()` accepts arrays, Laravel `Arrayable` objects, and objects exposing
`toArray()`. A configured Spatie Data class is constructed before rendering,
and root keys are normalized to snake case. `setVariable()`, `setOptions()`,
`variant()`, required-value validation, and localized fallback state remain
available.

Class-template Blade views receive:

| Variable | Value |
| --- | --- |
| `$data` | Validated template data |
| `$options` | Bounded implementation options |
| `$language` | Active locale |
| `$content` | Read-only `ContentAccessor` |
| `$assets` | Read-only `AssetAccessor` |
| `$config` | `EngineConfig` |
| `$composition` | Optional rendered Content composition |

Use `$content->get('path', $default)` for persisted or transitional copy.
Persisted copy belongs to a `RenderedContentCompositionData` supplied through
`withComposition()`; `withContent()` exists for deliberate in-memory values.
Use `$assets->get()`, `$assets->getFile()`, `$assets->fileUrl()`, and
`$assets->has()` for registered assets.

Asset inputs fail closed:

- local files must be regular files beneath
  `templates.compatibility.assets.allowed_local_roots`;
- local, inline, and generated data are size bounded;
- inline values must be complete base64 image data URIs whose detected bytes
  match the declared image MIME type;
- remote values require HTTPS and an exact host in
  `templates.pdf.remote_assets.allowed_hosts`;
- frame, sticker, and scoped asset handles are resolved through
  `TemplateAssetResolver`; the default resolver returns no assets.

Templates also ships an opt-in NVL Media resolver. Aliases are source
controlled, collection-like scopes remain deterministic, and an exact Media
revision can be pinned:

```php
'assets' => [
    'driver' => 'media',
    'media' => [
        'aliases' => [
            'brand-logo' => [
                'media_id' => '0198f51d-7f5b-7000-8000-000000000001',
                'scope' => 'document',
                'type' => 'logo',
                'delivery' => 'path', // path or url
                'expected_revision' => 3,
            ],
        ],
    ],
],
```

`path` requires a local Media disk and the resulting file must remain under
`templates.compatibility.assets.allowed_local_roots`. `url` uses Media's
public or signed-private delivery and still obeys the Templates remote-resource
policy. Missing Media, stale revisions, and unavailable named variations throw
`TemplateResolutionException`. `MediaTemplateAssetRegistry::registerAdoptionAliases()`
is available for controlled in-process legacy alias maps; durable mappings
belong in configuration.

Class renderers that need complete localized copy can resolve ordered Content
scope fallback without request pagination:

```php
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentScopeData;
use Nvl\Content\Facades\Content;

$copy = Content::resolveScopes([
    new ContentScopeData('site', 'tenant-a'),
    new ContentScopeData('site', 'default'),
], 'bg', ContentActorData::system(), limit: 250);

$template->withContent($copy->values);
```

The first scope wins for duplicate block keys. Reads include only published
blocks, are public-only by default, apply the consumer authorization query
scope, use Content's locale fallback, sort deterministically, and query
`limit + 1` so incomplete reads fail with `ContentScopeOverflowException`.
The Content block catalog also permits the allowlisted `scope in [...]` filter.

`BasePdfTemplate` retains fluent paper, orientation, margin, metadata,
numbering, protection, watermark, header/footer, frame, sticker, variant,
generation, preview, download, and storage methods. `EngineConfig::setTempDir`
is supported, but every override must remain beneath
`templates.pdf.allowed_temp_roots`. Image-error diagnostics require both
application debug mode and `templates.pdf.allow_debug_image_errors`; this
prevents diagnostic paths from leaking in production output.

`setHeaderHtml()` and `setFooterHtml()` are intended only for trusted
source-controlled class definitions. Their size and markup are validated.
Stored definitions accept trusted header/footer view names instead. QR
generation is not simulated: `supportsQrCode()` returns `false`, and an
application that needs QR content should generate a validated asset through
its own integration and register it normally.

`Nvl\Templates\Pdf\PdfService`,
`Nvl\Templates\Pdf\Contracts\PdfServiceInterface`,
`Nvl\Templates\Pdf\Contracts\GeneratedPdfInterface`,
`Nvl\Templates\Html\HtmlPayload`, and
`Nvl\Templates\Html\TemplateRenderer` are injectable adapters for incremental
class-template adoption. `GeneratedPdfInterface` provides protected inline and
download responses, byte access, and atomic local persistence.

## HTTP responses

Use `TemplateResponseFactory` to return verified bytes without manually
constructing content headers:

```php
$rendered = $renderTemplate->execute($template);

return $responses->inline($rendered);
// or
return $responses->download($rendered);
```

Responses include protected Content-Type, Content-Length, ETag,
X-Content-Type-Options, and safe Content-Disposition headers. Custom headers
cannot contain line breaks, and protected output headers cannot be overridden.
Rendered output and render-status responses are private and send `no-store`
cache directives.

## Custom renderers

Implement `Nvl\Templates\Contracts\TemplateRenderer`:

```php
final class MarkdownTemplateRenderer implements TemplateRenderer
{
    public function render(TemplateRenderContext $context): RenderedTemplateData
    {
        $content = $this->compile($context->data());

        return new RenderedTemplateData(
            content: $content,
            mimeType: 'text/markdown; charset=UTF-8',
            renderer: $context->renderer,
            byteSize: strlen($content),
            checksum: hash('sha256', $content),
            subject: $context->subject,
            suggestedFilename: $context->filename,
        );
    }
}
```

Register it in configuration:

```php
'renderers' => [
    'blade' => BladeTemplateRenderer::class,
    'pdf' => MpdfTemplateRenderer::class,
    'markdown' => MarkdownTemplateRenderer::class,
],
```

Aliases and classes are validated at boot. Duplicate aliases fail closed.
Driver-specific values are available through
`TemplateRenderContext::$rendererOptions`.

## Database implementation

The database implementation remains a first-class part of the package.

Package-owned UUID tables are:

- `templates`: synchronized definition key, renderer, state, schema snapshot,
  metadata, and optimistic revision.
- `templates_i18n`: localized management title and description.
- `template_versions`: numbered draft/published/retired state, immutable
  Content snapshot/hash, publication facts, metadata, and revision.
- `template_assignments`: unique owner/profile assignment, optional pinned
  version, bounded settings, and revision.
- `template_renders`: encrypted request payload, canonical digest,
  idempotency key, immutable profile/assignment-settings snapshot, leased
  lifecycle, attempts, private output facts, and bounded failure.

Actor and owner identifiers are strings so consumers may use integers, UUIDs,
ULIDs, or other scalar keys. Table names and the database connection are
configurable. `templates.migrations.enabled` defaults to `true`.

Only management title/description belongs to `templates_i18n`. Editable
document content, locale rows, structured values, and Media IDs belong to
Content blocks attached to the template version.

### Stored definitions

Register a source-controlled definition:

```php
'definitions' => [
    'invoice' => [
        'renderer' => 'pdf',
        'view' => 'documents.invoice',
        'profiles' => ['default'],
        'subject_path' => 'body.subject',
        'required_regions' => ['main'],
        'allowed_content_definitions' => [
            'documents.invoice-header',
            'documents.invoice-lines',
            'documents.invoice-totals',
        ],
        'schema' => [
            'type' => 'object',
            'properties' => [
                'invoiceNumber' => ['type' => 'string', 'maxLength' => 64],
            ],
            'required' => ['invoiceNumber'],
            'additionalProperties' => false,
        ],
        'renderer_options' => [
            'page_size' => 'A4',
            'orientation' => 'portrait',
            'header_view' => 'documents.pdf.header',
            'footer_view' => 'documents.pdf.footer',
            'filename' => 'invoice.pdf',
        ],
    ],
],
```

`renderer_options` uses snake-case configuration names and is converted to the
same typed `TemplateOptions`/`PdfOptions` used by direct templates. Unknown PDF
options fail during provider boot. Definition keys are an exact allowlist;
unknown keys and simultaneous snake/camel aliases fail during provider boot.

Payload schemas use a deliberately bounded JSON Schema subset. Every node
requires one of `array`, `boolean`, `integer`, `null`, `number`, `object`, or
`string`. Supported common keywords are `nullable` and `enum`; objects support
`properties`, `required`, and `additionalProperties`; arrays support `items`,
`minItems`, and `maxItems`; numbers support `minimum` and `maximum`; strings
support `minLength` and `maxLength`. Unsupported keywords fail at boot. Payload,
settings, schema, metadata, and renderer options must contain JSON values only,
and their root value must be an object.

`CreateTemplateData` selects a registered definition key; renderer and payload
schema are copied from that definition. Management mutations may change
lifecycle, metadata, and localized labels but cannot override executable
structure. Structural changes flow through source control and the sync command.

Synchronize definitions:

```bash
php artisan nvl:templates:sync --dry-run --format=json
php artisan nvl:templates:sync --format=json
```

Definitions removed from source are preserved but archived during
synchronization. Reintroducing the definition allows normal synchronization;
the command never deletes versions, assignments, renders, or localized rows.

### Versions and publication

Use `CreateTemplateAction` and `CreateTemplateVersionAction`, then place
Content blocks on the `TemplateVersion` model’s reserved `document` group. The
model implements `ContentOwner` with `HasContent` and persists the stable
`template-version` morph alias. Publish through `PublishTemplateVersionAction`
with the exact current revision.

Publication:

1. locks the version and verifies optimistic concurrency;
2. resolves its source-controlled definition;
3. captures a bounded Content composition;
4. enforces required regions and allowed Content definitions;
5. stores canonical immutable JSON through
   `ContentCompositionSnapshotCast` and its SHA-256 integrity version;
6. retires the previous published sibling;
7. emits the package event after commit.

Published and retired versions are immutable. Create another draft version for
later edits.

Render a persisted publication through the database adapter:

```php
$rendered = $renderStoredTemplate->execute(
    'invoice',
    new RenderTemplateData(
        locale: 'en',
        payload: ['invoiceNumber' => 'INV-1001'],
    ),
    TemplateActorData::system(),
);
```

`RenderStoredTemplateAction` resolves authorization, profile, assignment,
version, snapshot integrity, Content/Media projections, subject, and assignment
settings. It then constructs the public `Nvl\Templates\Template` and delegates
to the core `RenderTemplateAction`. Both publication and rendering consume the
injected `Nvl\Content\Content` application surface; the stored model attribute
is a typed `ContentCompositionSnapshotData`, not an array that each consumer
must rehydrate.

### Assignments

Register owner aliases through `TemplateOwnerResolver`. `AssignTemplateAction`
maps one template/profile to an owner and may pin an exact published or retired
version. `UnassignTemplateAction` requires the current assignment revision.
Assignment settings are bounded and exposed as `$settings` to Blade.

### Queued rendering

`QueueTemplateRenderAction` creates an encrypted, canonical,
idempotency-protected render record and dispatches `RenderTemplateJob` after
commit. Queueing performs the complete stored-template preflight before
acceptance and snapshots the resolved version, profile, payload, and assignment
settings. `ProcessTemplateRenderAction` claims a time-bounded lease and renders
that immutable request through the same core `RenderTemplateAction`, so later
assignment changes cannot alter accepted work.

Configure queue, connection, attempts, timeout, lease duration, unique-lock
duration, pending-recovery age, backoff, recovery batch size, payload
retention, and private output disk under `templates.rendering`. Keep
`pending_recovery_seconds` greater than `unique_for`, and keep the queue
connection’s `retry_after` greater than the job timeout; the doctor verifies
both constraints.

```bash
php artisan queue:work --queue=default
php artisan nvl:templates:renders:recover
```

`RenderTemplateJob` uses a Laravel unique lock until processing begins, an
overlap lock during execution, and a database lease.
Schedule `nvl:templates:renders:recover` at an interval shorter than the
operational recovery target; it redispatches one bounded batch of pending
records older than `pending_recovery_seconds` and processing records with
expired leases. This also recovers a record when its original queue push failed.
A worker may finalize or fail a render only while it owns the matching
processing token and current dispatch generation.

When output persistence is enabled, the result is attached as private Media
through the package Media integration. The application owns retention,
delivery, and business workflow decisions.

## Routes and authorization

Management and stored-render routes are independently disabled by default:

```php
'routes' => [
    'management' => [
        'enabled' => true,
        'prefix' => 'api/v1/templates',
        'name' => 'nvl.templates.management.',
        'middleware' => ['api', 'auth:sanctum', 'throttle:60,1'],
    ],
    'render' => [
        'enabled' => true,
        'prefix' => 'api/v1/templates/render',
        'name' => 'nvl.templates.render.',
        'middleware' => ['api', 'auth:sanctum', 'throttle:60,1'],
    ],
],
```

Bind `TemplateAuthorization` to the application’s policy adapter. The default
permits trusted system actors only.

Stored render and assignment authorization receives the selected template,
owner type/id, profile, and pinned version in its context so consumer policies
can enforce domain ownership before resolution.

The render API returns actual output bytes with the renderer MIME type. Send
`download: true` to request attachment disposition. The queue endpoint returns
the durable render identifier and status. `GET /renders` and
`GET /renders/{render}` expose authorized history/status facts and the private
Media identifier, never payload, settings, processing tokens, or failure text.
Non-system actors are always scoped to their own requester identity.

Routes are transport adapters over the stored Actions; middleware does not
replace Action authorization.

## Configuration reference

| Key | Purpose |
| --- | --- |
| `connection`, `tables.*`, `migrations.enabled` | Database adoption and storage |
| `adoption.*` | Adoption manifest byte and record bounds |
| `authorization.class` | Stored-template policy adapter |
| `routes.management.*`, `routes.render.*` | Optional API prefixes, names, and middleware |
| `definitions` | Source-controlled database implementation definitions |
| `owners` | Assignment owner resolver aliases |
| `default_renderer`, `default_locale`, `views.defaults.*` | Direct Template defaults |
| `renderers` | Renderer alias/class allowlist |
| `limits.*` | Schema, data, settings, options, payload, and output limits |
| `views.*` | Namespace and guarded default/custom publication paths |
| `rendering.*` | Queue, retries, leases, recovery, payload retention, and private output |
| `compatibility.assets.*` | Class-template local/inline asset roots and byte limits |
| `assets.driver`, `assets.media.aliases` | Null or NVL Media-backed class-template aliases |
| `pdf.temp_path`, `pdf.allowed_temp_roots` | mPDF workspace boundary |
| `pdf.allow_debug_image_errors` | Explicit debug-only image diagnostics gate |
| `pdf.remote_assets.*`, `pdf.data_images.*` | Resource policy |
| `pdf.defaults.*` | Global typed PDF defaults |

Configuration contains no required Closure and is safe for
`php artisan config:cache`.

## Commands

```bash
php artisan nvl:templates:doctor
php artisan nvl:templates:doctor --strict --format=json
php artisan nvl:templates:doctor --scope=core --strict
php artisan nvl:templates:doctor --scope=database --strict

php artisan nvl:templates:adopt storage/adoption/templates.json --format=json
php artisan nvl:templates:adopt storage/adoption/templates.json --prepare --format=json
php artisan nvl:templates:adopt storage/adoption/templates.json --apply --format=json

php artisan nvl:templates:sync --dry-run
php artisan nvl:templates:sync --format=json
php artisan nvl:templates:renders:recover --format=json

php artisan nvl:templates:views:publish
php artisan nvl:templates:views:publish \
    --path=resources/views/documents \
    --force

php artisan nvl:content:definitions:sync
php artisan nvl:content:doctor --strict --format=json
php artisan queue:work --queue=default
```

The Templates doctor does not mutate state. It checks canonical tables,
columns, named index definitions, primary keys, and foreign-key delete rules plus bindings,
registered renderers/definitions/owners, bundled/default views, configured
limits, mPDF availability, temporary-path containment, durable-render columns,
queue lease/timeout/retry configuration, Content-definition registration, and
the private output disk. Use `--scope=core` when adopting only direct/class
rendering without the package tables.

## Failure behavior

- Invalid source definitions fail during provider boot.
- Invalid direct Template data, schema, view, locale, renderer, filename, or
  options fail before rendering.
- Payload schema mismatches throw `TemplateResolutionException`.
- Missing assignments/versions, stale revisions, inactive templates, and
  corrupt snapshots fail closed.
- Renderer output with inconsistent facts or unsafe headers is rejected.
- PDF resource and path violations fail before mPDF receives the HTML.
- Queue processing records bounded failure context, recovers only sufficiently
  old pending records or expired leases, and rethrows for normal retry handling.

## Upgrade and adoption

This package has no supported pre-1.0 package release. Applications adopting
another template system should use the versioned `nvl:templates:adopt`
manifest. The command defaults to a read-only plan: it inventories canonical
and declared staging schemas, validates explicit legacy-to-target key and scope
maps, validates locales, verifies every declared asset already maps to an
available NVL Media row, and reports exact expected counts. A manifest must
declare `legacy_asset_count`; a non-empty legacy asset set without one mapping
per row fails closed.

Use this staged sequence:

1. Disable `templates.migrations.enabled` while a conflicting canonical table
   name is still owned by the legacy system.
2. Rename legacy tables to explicit staging names and list them under
   `staging_tables` in the manifest.
3. Run the command without options and inspect the JSON inventory and mapping
   plan.
4. Run `--prepare` to drop every non-primary, non-SQLite-autoindex named index
   from only those declared staging tables. This prevents SQLite schema-wide
   index-name collisions when canonical migrations run.
5. Enable and run package migrations. The compatibility preflight rejects any
   unowned or structurally incomplete canonical Templates table.
6. Run `--apply`. Template and Content writes use their public Actions,
   optimistic revisions, publication rules, and locale/value validation.
7. Re-run `--apply`; every entry must report `unchanged`. Copy the returned
   `media_aliases` map into `templates.assets.media.aliases`, validate output,
   then remove staging in a separate forward-only host migration.

The apply phase is restart-safe and reconciles target counts, but it may span
different configured database connections and therefore is deliberately not
presented as one cross-database transaction.

A minimal manifest is:

```json
{
  "version": 1,
  "staging_tables": ["legacy_templates", "legacy_template_assets"],
  "legacy_asset_count": 1,
  "templates": [
    {
      "legacy_key": "legacy-welcome",
      "key": "welcome",
      "translations": {"en": {"title": "Welcome"}}
    }
  ],
  "content": [
    {
      "legacy_key": "legacy-welcome-copy",
      "legacy_scope": "legacy-site",
      "legacy_scope_key": "main",
      "definition": "template-copy",
      "key": "welcome-copy",
      "scope": "site",
      "scope_key": "main",
      "translations": {"en": {"text": "Welcome"}},
      "publish": true
    }
  ],
  "assets": [
    {
      "legacy_alias": "logo",
      "key": "brand-logo",
      "media_id": "0198f51d-7f5b-7000-8000-000000000001",
      "expected_revision": 1
    }
  ]
}
```

Applications should then:

1. move executable views into source control;
2. define reusable Content blocks for strings, tables, repeaters, rich text,
   images, and documents;
3. import binaries through Media;
4. import stored definitions, versions, assignments, and localized labels
   through public Actions;
5. publish versions to capture immutable snapshots;
6. compare representative HTML/PDF bytes, localization, authorization,
   assignments, Media delivery, and queued results before cutover.

Do not import arbitrary stored PHP/Blade as executable source.

For source-controlled class templates, rename imports into the
`Nvl\Templates\Templates`, `Nvl\Templates\Pdf`,
`Nvl\Templates\Html`, and `Nvl\Templates\Support\PdfConfig` namespaces.
Constructor dependencies are injected, static application lookups are not
required, and copy/assets move to Content and Media. Method names used for
template configuration and PDF generation remain available. Direct custom
font registration and global engine mutation should move into package
configuration or a custom renderer so deployment-time validation remains
possible.

## Development and verification

```bash
composer validate --strict
vendor/bin/pint --format agent
vendor/bin/phpstan analyse --level=max
vendor/bin/pest --compact
```

The suite covers clean database installation, direct Template rendering,
stored publication/version workflows, Content/Media composition, payload
schemas, optimistic concurrency, assignments, idempotent queues, HTML and PDF
output, output checksums/responses, resource security, route defaults, and
diagnostics.

## License

NVL Templates is open-sourced under the MIT License.
