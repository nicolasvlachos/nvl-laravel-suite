# NVL Content — API and usage

[← NVL Laravel Suite](../../../README.md)

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/laravel-suite:^1.0` |
| Module identifier | `nvl/content` |
| PHP namespace | `Nvl\Content` |
| Service provider | `Nvl\Content\Providers\ContentServiceProvider` |
| Configuration | `config/content.php` |

`nvl/content` is a headless, schema-driven content-block engine for Laravel
12–13 on PHP 8.4+. It provides reusable, translatable blocks, typed fields,
validated structured data, semantic rich-content presets, generated JSON
Schemas, typed render projections, model-backed owner placements, named
composition groups, trees and regions, Media and model references, immutable
composition snapshots, and safe Blade starting views.

It is intended for page builders, reusable site sections, email/document
content, CMS-like application content, and any workflow that needs an
ACF-style field system without coupling content to an administration UI.

## Purpose

Content provides one reusable contract for defining, validating, localizing,
placing, versioning, and rendering structured application content. It replaces
consumer-specific block tables and closed enums with source-defined schemas,
open adapter registries, DTO/Action boundaries, and explicit integrations.

## Architecture and boundaries

Source-controlled definitions are authoritative. They describe available block
types, recursive field schemas, defaults, views, scopes, and regions. A
database mirror makes definitions queryable and records source hashes; it does
not allow executable schemas or Blade source to be edited as database content.

Persisted blocks contain normalized values, dedicated locale rows, a snapshot
of the definition schema/view used when they were created, lifecycle state,
optimistic revision, and audit actor identifiers. Placements reuse blocks on
allowlisted owners and provide stable keys, regions, order, parent-child trees,
visibility, and non-localized overrides.

The package is deliberately headless:

- It does not ship an admin application, page-builder JavaScript, or a consumer
  model assumption.
- Binary ownership, associations, delivery, and transformations remain in
  `nvl/media`; Content values store Media UUIDs.
- Locale fallback and the central resource registry remain in
  `nvl/translatable`.
- Reference values use registered aliases and never accept arbitrary model
  class names.
- Blade files remain source controlled. Database values are never evaluated as
  PHP or compiled as Blade.

The dependency graph is:

```text
content
├── data
├── filterable
├── media
├── support
└── translatable
```

Composer installs these declared dependencies automatically.

## Installation

```bash
composer require nvl/laravel-suite:^1.0
php artisan migrate
php artisan nvl:content:definitions:sync --dry-run
php artisan nvl:content:definitions:sync
php artisan nvl:content:definitions:migrate --dry-run
php artisan nvl:content:definitions:migrate
php artisan nvl:content:doctor --strict --format=json
```

Laravel discovers `Nvl\Content\Providers\ContentServiceProvider`. Automatic
migrations are enabled by default. Existing applications that own compatible
tables must disable `content.migrations.enabled` for that schema before
migrating and leave package migration ownership disabled. Bundled migrations
fail closed when a target table already exists; they never silently adopt or
later drop a pre-existing table.

Optional publish tags are:

```bash
php artisan vendor:publish --tag=content-config
php artisan vendor:publish --tag=content-migrations
php artisan vendor:publish --tag=content-views
php artisan vendor:publish --tag=content-skills
```

Choose exactly one migration owner. For automatic vendor loading, leave
`content.migrations.enabled=true` and do not publish `content-migrations`. For
host-owned migrations, publish `content-migrations`, set
`content.migrations.enabled=false` before the first migration, and maintain the
copied files as application migrations. Never run both sources; Laravel
retimestamps published migrations.

Package-owned records use UUID primary keys. Owner, actor, and reference
identifiers are strings so consumer models may use integers, UUIDs, ULIDs, or
other scalar keys. All table names and the package database connection are
configurable.

## First working composition

The smallest complete integration needs one owner model, one definition, and
an authorization adapter. This system-only adapter is suitable for deployment,
seeding, and queue workflows; request-driven applications should replace it
with their own user/tenant policy before exposing management routes.

```php
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Models\ContentBlock;

final class SystemContentAuthorization implements ContentAuthorization
{
    public function authorize(
        ContentAbility $ability,
        ContentActorData $actor,
        ?ContentBlock $block = null,
        ?Model $owner = null,
        array $context = [],
    ): void {
        if (! $actor->system) {
            throw new AuthorizationException('Content access is restricted to system workflows.');
        }
    }
}
```

Register that adapter, the owner alias, locales, and a source definition in
`config/content.php`:

```php
'authorization' => [
    'class' => App\Content\SystemContentAuthorization::class,
],
'owners' => [
    'page' => App\Models\Page::class,
],
'locales' => [
    'available' => ['en'],
    'required_on_publish' => ['en'],
],
'definitions' => [
    'hero' => [
        'name' => 'Hero',
        'allowed_scopes' => ['global'],
        'allowed_regions' => ['main'],
        'schema' => [
            'fields' => [[
                'key' => 'title',
                'type' => 'text',
                'label' => 'Title',
                'localized' => true,
                'required' => true,
            ]],
        ],
    ],
],
```

The owner declares its composition groups:

```php
use Illuminate\Database\Eloquent\Model;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Traits\HasContent;

final class Page extends Model implements ContentOwner
{
    use HasContent;

    public const array CONTENT_GROUPS = ['content'];
}
```

After synchronizing definitions, the canonical application service supports
the complete create, publish, place, and render flow:

```php
use Nvl\Content\Content;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;

$actor = ContentActorData::system();
$content = app(Content::class);
$page = Page::query()->findOrFail($pageId);

$draft = $content->createBlock(
    new CreateContentBlockData(
        definition: 'hero',
        key: 'homepage-hero',
        translations: ['en' => ['title' => 'Welcome']],
    ),
    $actor,
);
$published = $content->publishBlock($draft, $draft->revision, $actor);
$content->place(
    $published,
    $page,
    'content',
    new PlaceContentBlockData(key: 'hero', region: 'main'),
    $actor,
);

$composition = $content->render($page, 'content', 'en', $actor);
$title = $composition->value('hero.title');
```

Never construct a system actor from request input. For authenticated request
workflows, use `ContentActorData::fromAuthenticatable($user)` and a consumer
`ContentAuthorization` implementation that validates that actor, owner, block,
ability, and contextual group/public-render flags.

## Configuration reference

| Key | Default / purpose |
| --- | --- |
| `connection` | `null`; use Laravel’s default connection |
| `tables.*` | Names for definitions, blocks, `blocks_i18n`, placements, and revisions |
| `migrations.enabled` | `true`; disable only during controlled schema adoption |
| `definition_migrations` | Sequential `ContentDefinitionMigration` classes for stored block upgrades |
| `definition_sync.*` | Cross-process definition synchronization lock lifetime and wait bounds |
| `definition_migration.*` | Default/maximum atomic batch size and deadlock retry count |
| `authorization.class`, `authorization.callback` | Policy adapter and optional callback used by the default adapter |
| `definitions` | Inline source-authoritative definitions |
| `definition_paths` | Optional PHP/JSON source files or directories |
| `required_definition_paths` | Roots that must exist or application boot fails |
| `allowed_definition_roots` | Real-path boundary for every discovered source |
| `definition_limits.*` | Maximum discovered files and bytes per source file |
| `scopes` | Scope alias to validated `key_pattern` map |
| `owners`, `references`, `field_types` | Allowlisted owner model, reference resolver, and field adapter class maps |
| `presets` | Consumer semantic preset classes or declarative preset definitions |
| `links.*` | Safe semantic-link schemes and relative URI policy |
| `locales.available` | Accepted normalized content locales; empty permits Translatable defaults |
| `locales.required_on_publish` | Locales that must satisfy required localized fields |
| `validation.*` | Payload, metadata, reference display, snapshot, revision, schema, depth, item, string, unknown-field, URL-scheme, and JSON-reference limits |
| `rich_text.*` | Input length, link schemes, and relative-link policy used by Symfony’s sanitizer |
| `media.*` | Public/private policy, per-field maximum, and private URL lifetime |
| `placements.*` | Maximum placements/depth plus owner-lock lifetime and wait bounds |
| `rendering.*` | Default source-controlled view and strict missing-view policy |
| `view_publishing.allowed_roots` | Absolute destinations permitted for guarded view publication |
| `routes.management.*`, `routes.public.*` | Independent enable flags, path/name prefixes, and middleware stacks |

Run the strict doctor after publishing or changing configuration. Every
configured middleware entry must be a non-empty string. Production applications
that cache configuration should bind a `ContentAuthorization` class rather
than storing a Closure callback in cached configuration.

## Defining blocks

Definitions may be placed inline in `config/content.php`, or in sorted
`*.content.php` and `*.content.json` files under configured
`content.definition_paths`. Paths must resolve beneath
`content.allowed_definition_roots`; traversal and symlink escapes fail closed.
Duplicate keys also fail during application boot. Discovery is bounded by
`content.definition_limits.maximum_files` and
`content.definition_limits.maximum_file_bytes`; unreadable or oversized
sources fail the boot rather than being partially loaded.

Optional paths may be absent, which keeps the package installable before an
application creates `resources/content`. Put deployment-critical roots in
`content.required_definition_paths`; a missing required root fails boot and
prevents an accidental empty scan from orphaning the synchronized mirror.

```php
'definitions' => [
    'marketing.hero' => [
        'name' => 'Marketing hero',
        'description' => 'Reusable hero with localized copy and one image.',
        'category' => 'marketing',
        'version' => 1,
        'view' => 'content.blocks.marketing-hero',
        'allowed_scopes' => ['global', 'site'],
        'allowed_regions' => ['main', 'header'],
        'defaults' => ['enabled' => true],
        'schema' => [
            'fields' => [
                [
                    'key' => 'title',
                    'type' => 'text',
                    'label' => 'Title',
                    'required' => true,
                    'localized' => true,
                    'settings' => ['max_length' => 120],
                ],
                [
                    'key' => 'body',
                    'type' => 'rich_text',
                    'label' => 'Body',
                    'localized' => true,
                ],
                [
                    'key' => 'image',
                    'type' => 'media',
                    'label' => 'Image',
                    'settings' => ['mime_types' => ['image/avif', 'image/webp', 'image/jpeg']],
                ],
                [
                    'key' => 'links',
                    'type' => 'repeater',
                    'label' => 'Links',
                    'settings' => ['max_items' => 4],
                    'fields' => [
                        ['key' => 'label', 'type' => 'text', 'label' => 'Label'],
                        ['key' => 'url', 'type' => 'url', 'label' => 'URL'],
                    ],
                ],
            ],
        ],
    ],
],
```

A PHP definition file may return one definition, a list, or a map keyed by
definition key. JSON files use the same shape. Snake-case definition options
are mapped deterministically. This authoring shape is an internal
`ContentDefinitionSource`; after compilation, every public definition uses
`ContentDefinitionData` with a typed recursive `ContentSchemaData` and
`ContentFieldDefinitionData` tree. Raw source arrays therefore never
masquerade as compiled client contracts.

Run synchronization after changing source definitions:

```bash
php artisan nvl:content:definitions:sync --dry-run --format=json
php artisan nvl:content:definitions:sync --format=json
```

The plan reports create, update, unchanged, and orphan keys. Removed source
definitions are marked orphaned instead of deleting blocks that still depend
on their schema snapshots. Contract changes (schema, defaults, scopes, regions,
or view) must increase the definition version; version decreases are rejected.
Unknown definition/field properties, conflicting keyed identities or
snake/camel aliases, ambiguous booleans, irrelevant child/item declarations,
malformed JSON Schemas, invalid defaults, and unregistered reference aliases
fail during application boot. External Media/reference defaults are
shape-checked at boot and resolved against live authorization and availability
when content is written.

## Evolving definitions

A definition version is a persisted content contract, not documentation.
Whenever a version changes and older blocks exist, register every sequential
one-version migration. Synchronization rejects the new mirror when any stored
version lacks a complete path.

```php
use Nvl\Content\Contracts\ContentDefinitionMigration;
use Nvl\Content\Data\ContentDefinitionMigrationContextData;
use Nvl\Content\Data\ContentDefinitionMigrationValuesData;

final class MarketingHeroV1ToV2 implements ContentDefinitionMigration
{
    public function definitionKey(): string
    {
        return 'marketing.hero';
    }

    public function fromVersion(): int
    {
        return 1;
    }

    public function toVersion(): int
    {
        return 2;
    }

    public function migrate(
        ContentDefinitionMigrationContextData $context,
    ): ContentDefinitionMigrationValuesData {
        $translations = $context->translations;

        foreach ($translations as $locale => $values) {
            $values['title'] = $values['headline'] ?? null;
            unset($values['headline']);
            $translations[$locale] = $values;
        }

        return new ContentDefinitionMigrationValuesData(
            values: $context->values,
            translations: $translations,
            metadata: $context->metadata,
        );
    }
}
```

Register migrations in configuration:

```php
'definition_migrations' => [
    MarketingHeroV1ToV2::class,
],
```

Each step is deterministic and may change only base values, locale values, and
metadata. The package replans against current source definitions, locks every
target in stable order, verifies the exact planned revision, executes the full
chain, validates the result against the final compiled schema, replaces locale
rows, validates every dependent placement region and override, resynchronizes
Media, records a `migrated` revision, and dispatches `ContentBlockChanged`
after commit. Any failure rolls back the complete batch. Soft-deleted blocks
are upgraded without reattaching Media until restoration.

```php
$plan = $content->planDefinitionMigrations(
    actor: $actor,
    definition: 'marketing.hero',
    limit: 100,
);

if ($plan->blocked === []) {
    $result = $content->applyDefinitionMigrations($plan, $actor);
}
```

Plans contain identities, versions, and revisions, never content values.
`updateBlock()` and `publishBlock()` return
`definition_migration_required`/`409` for an old block instead of silently
stamping it with the latest definition.

## Built-in field types

The stable built-in aliases are:

| Category | Aliases | Behavior |
| --- | --- | --- |
| Strings | `text`, `textarea` | Length and optional pattern validation |
| Semantic strings | `url`, `uri`, `email`, `color`, `date`, `date_time` | Strict normalized semantic validation; `url` is absolute while `uri` also supports safe site-relative links |
| Choices | `select`, `multi_select` | Only configured options; bounded unique lists |
| Numbers | `integer`, `number` | Strict numeric types with optional minimum and maximum |
| Flag | `boolean` | Boolean values only; no ambiguous string coercion |
| Rich text | `rich_text` | Sanitized at mutation and returned as an explicit safe render DTO |
| Structured | `object`, `list`, `repeater`, `table` | Recursive child/item schemas and bounded depth/items |
| Arbitrary JSON | `json` | Opis JSON Schema Draft 2020-12 validation |
| Media | `media`, `media_collection` | Available Media UUIDs, MIME/visibility/ownership policy, safe display DTOs |
| References | `reference`, `reference_list` | Allowlisted resolver aliases and bounded IDs |

Repeaters receive stable `_key` values during normalization. This lets a
headless editor reorder or patch rows without treating the current array index
as identity. Localized structural repeater rows must send an existing base
`_key`; reordered and partial locale rows remain supported without positional
guessing. Arrays replace as a unit in patch mode; nested JSON objects use
merge-patch semantics.

Structured fields recurse through `fields` or an `item` definition. The global
payload, depth, field-count, item-count, metadata, string, and snapshot limits
are configurable and enforced before persistence or rendering. Unknown fields
fail by default. Built-in field settings are also allowlisted and validated at
boot—including lengths, item counts, numeric bounds, options, regular
expressions, URL schemes, MIME types, and reference aliases—so misspelled
schema options cannot remain dormant until production content is edited.
The `javascript`, `data`, `file`, and `vbscript` schemes are always denied,
including in rich text and consumer-provided allowlists. Global scheme
configuration is validated during provider registration and field-specific
overrides are validated with their definitions.

The `json` field requires a schema in `settings.schema`. Remote `$ref` values
are denied by default. Enabling remote references should only be considered
with a consumer-owned resolver and explicit network/security policy.

Custom types implement `Nvl\Content\Contracts\ContentFieldTypeAdapter` and are
registered by alias in `content.field_types`. Registration rejects mismatched
or duplicate aliases. An adapter may additionally implement
`ContentFieldDefinitionValidator` to reject invalid type-specific settings at
application boot rather than waiting for the first mutation.

## Semantic rich-content presets

Presets are reusable semantic schemas built on the same bounded field system.
A source definition references the semantic alias; Content expands it to a
complete recursive schema before validation and persistence:

```php
'homepage.banner' => [
    'name' => 'Homepage banner',
    'category' => 'marketing',
    'version' => 1,
    'allowed_scopes' => ['site'],
    'schema' => [
        'fields' => [
            [
                'key' => 'banner',
                'preset' => 'banner',
                'label' => 'Banner',
                'required' => true,
            ],
        ],
    ],
],
```

The compiled field retains `preset => 'banner'` as its semantic hint and
contains ordinary `object`, `media`, `rich_text`, `select`, and other child
fields. Consumers therefore have one validation, persistence, translation,
Media-association, snapshot, and rendering path rather than a separate runtime
for special blocks.

The built-in presets are:

| Alias | Semantic value |
| --- | --- |
| `link` | Localized label/title, safe internal or external destination, target, and allowlisted relationship tokens |
| `button` | A link plus semantic `primary`, `secondary`, or `tertiary` emphasis |
| `image` | Image Media plus localized alt, title, rich caption, credit, decorative state, and focal point |
| `heading` | Localized eyebrow, title, rich description, and semantic `h1`–`h6` level |
| `banner` | Heading, image, primary/secondary buttons, and direction-aware alignment |

Rendered preset values are typed as `RenderedContentLinkData`,
`RenderedContentButtonData`, `RenderedContentImageData`,
`RenderedContentHeadingData`, and `RenderedContentBannerData`. Closed semantic
choices use backed enums such as `ContentLinkTarget`, `ContentHeadingLevel`,
and `ContentAlignment`; Media and sanitized rich text keep their existing safe
DTO projections.

Every registered preset is compiled and validated during application boot,
including presets that no definition currently uses. Consumer presets may
implement `ContentFieldPreset` or be declared under `content.presets`.
Definition fields may override presentation metadata, defaults, and settings,
but cannot replace a preset's `type`, `fields`, or `item` structure.
Custom normalization receives one base or locale partition at a time;
`ContentValidationContext::$localized` identifies which partition is active.
It must return JSON-safe normalized data. `validate()` receives each complete,
locale-resolved value after schema-aware fallback, so cross-field semantic
invariants do not have to inspect storage partitions. `jsonSchema()` adds the
same constraints to the editor contract. Rendering receives that merged value
and may project it to a typed DTO.

The built-in image preset uses these hooks to require non-empty,
locale-resolved alt text when a non-decorative image is published. Decorative
images intentionally render with an empty alt attribute. Link and button
destinations reject unsafe schemes, protocol-relative values, control
characters, credential-bearing HTTPS URLs, and backslashes in relative URIs.

`Content::presets($actor)` and `ListContentPresetsAction` return the complete
editor catalog. Preset `field` values are typed
`ContentFieldDefinitionData` objects, not arrays. Each preset and each
`ContentDefinitionData` also includes a JSON Schema Draft 2020-12 document
with `x-content-type`, `x-content-localized`, and `x-content-preset`
annotations. This is the canonical contract for API clients and generic form
builders; Filament remains a consumer integration.

## Localization

Set `localized => true` on any leaf or subtree whose values vary by locale.
Localized values are stored in `content_blocks_i18n`; structural/base values
stay on the block. A non-localized object may contain localized descendants,
so an image UUID, link destination, target, heading level, or layout choice can
stay canonical while its alt text, label, caption, or title translates.
Locale normalization and fallback are delegated to `nvl/translatable`.

```php
$block = $create->execute(
    new CreateContentBlockData(
        definition: 'homepage.banner',
        key: 'homepage-banner',
        scope: 'site',
        scopeKey: 'main',
        values: [
            'banner' => [
                'image' => ['media' => $media->id],
                'primary_action' => ['href' => '/donate'],
                'alignment' => 'center',
            ],
        ],
        translations: [
            'en' => [
                'banner' => [
                    'heading' => ['title' => 'Build something useful'],
                    'image' => ['alt' => 'Volunteers preparing donations'],
                    'primary_action' => ['label' => 'Donate now'],
                ],
            ],
            'bg' => [
                'banner' => [
                    'heading' => ['title' => 'Създайте нещо полезно'],
                    'primary_action' => ['label' => 'Дарете сега'],
                ],
            ],
        ],
    ),
    $actor,
);
```

Configure available locales and locales required for publication under
`content.locales`. Every Content locale must also be registered in
`translatable.locales`, which remains the canonical locale runtime. Publishing
validates the complete schema again, including
required localized fields and Media/reference availability. When
`content.locales.available` is empty, Content uses the Translatable locale
registry. Locale aliases that normalize to the same key are rejected instead
of silently overwriting one another. HTTP rendering defaults to the
request-scoped `ContentLocale`, not Laravel's UI locale.

Nested fallback is schema-aware and resolves leaf by leaf. In the example, a
Bulgarian render keeps the base Media and destination, uses the Bulgarian
heading/button copy, and may fall back to the English image alt without
replacing the surrounding image object. Repeater translations match stable
base `_key` values; localized projections cannot create unrelated base rows or
objects.

`content.blocks` is registered in the Translatable resource registry so a
consumer may gather and manage all localized package resources through one
authorization boundary.

## Owners, groups, scopes, placements, and trees

Scopes describe where a block may be selected; owners describe what receives a
composition. Each scope has a key pattern. The built-in global scope uses `*`.
Applications can add site, tenant, channel, brand, or other opaque scope keys.

Owner aliases map directly to Eloquent models implementing
`Nvl\Content\Contracts\ContentOwner`. Use the supplied
`Nvl\Content\Traits\HasContent` trait to provide the polymorphic relationship
and hard-delete cleanup:

Provider integrations should depend on
`Nvl\Content\Contracts\ContentOwnerRegistrar`, not the concrete owner
registry.

```php
'owners' => [
    'page' => Acme\Models\Page::class,
],
```

```php
final class Page extends Model implements ContentOwner
{
    use HasContent;

    public const array CONTENT_GROUPS = [
        'content',
        'navigation',
    ];
}
```

A group is a named composition partition on one owner. Placement keys, trees,
limits, locks, live renders, and snapshots are isolated per group. Regions
remain layout slots inside a group, while a definition’s `category` remains an
editor-catalog grouping. This permits the same owner to expose independent
surfaces such as `content`, `navigation`, and `email` without key collisions.
Every owner declares at least one valid group through `CONTENT_GROUPS`, or a
single group through `CONTENT_GROUP`. Group discovery returns this declaration,
including groups with no placements, and every placement or render rejects an
undeclared group.

Place a block through the canonical model-first application surface:

```php
$placement = $content->place(
    $block,
    $page,
    'content',
    new PlaceContentBlockData(
        key: 'hero',
        region: 'main',
        sortOrder: 10,
    ),
    $actor,
);
```

Placement validation enforces the block definition’s regions, owner existence,
parent ownership, a single region throughout each subtree, maximum placement
count per group, maximum tree depth, cross-group parent rejection, and cycle
prevention. A placement may contain
bounded non-localized overrides. Localized copy must remain in the block’s
locale rows. Hidden ancestors suppress their complete subtree; a visible child
is never promoted when its parent is hidden, private, unpublished, missing, or
otherwise ineligible. Placement create/update/delete operations are serialized
with an atomic cache lock keyed by owner and group, including the empty-tree
case that a database row lock cannot protect.

`Nvl\Content\Content::render()` loads a complete owner composition with
stable ordering, validates its complete tree before rendering, groups root
blocks by region, resolves locale values, converts Media IDs to safe Media
DTOs, and converts reference IDs through their registered display resolver.
Each live or snapshot render owns an isolated resource cache: Media and its
display relations are batch-loaded once for the composition, and repeated
reference display lookups are memoized without leaking state between requests
or queued jobs.

Remove placements explicitly; deleting a block while any placement exists is
rejected, and a parent placement cannot be removed before its children:

```php
$unplace->execute(
    placement: $placement,
    expectedRevision: $placement->revision,
    actor: $actor,
);
```

## Media and references

Public Content may reference only reusable public Media by default. Private
Content may reference private Media when enabled, but mutation requires the
configured Media authorization/uploader policy. Content creates and removes
associations only through Media Actions.

At render time:

- Public files become `Nvl\Media\Data\Display\PublicMedia` projections.
- Private files require the Media download policy and become
  `Nvl\Content\Data\RenderedPrivateMediaData` with temporary signed URLs.
- Missing, quarantined, deleted, or otherwise unavailable files are omitted.
- Disk names, internal paths, digests, quarantine details, and uploader
  identifiers are never exposed by the public projection.

The Media download policy receives the resolved registered owner for both live
and snapshot rendering, so tenant/owner-aware policies do not have to infer
context from a URL.

References use a configured `reference_type` setting and a resolver
implementing `Nvl\Content\Contracts\ContentReferenceResolver`. That resolver
owns existence, authorization, and locale-aware display data. Raw PHP class
names are never accepted from content input. Resolver display payloads are
JSON-only, byte/item/depth/string bounded, cached per composition, and cannot
replace the reserved stable `id` key. Both resolver methods receive
`ContentValidationContext`, including actor, normalized locale, owner,
group, visibility, field path, and public/preview mode.

Localized Media fields create Media associations with the normalized locale.
Because Content and Media writes form one logical transaction, any block that
contains or previously contained Media references must use the same named
database connection as Media. `nvl:content:doctor --strict` verifies this.
Private URL lifetime is configured with
`content.media.private_url_ttl_minutes`.

## Rendering and Blade starting views

Render headlessly:

```php
$composition = $renderer->render(
    owner: $page,
    group: 'content',
    locale: 'en',
    actor: $actor,
);

$title = $composition->value('hero.title');
$firstTitle = $composition->firstValue('title');
```

The bundled anonymous components are:

```blade
<x-nvl-content::composition :composition="$composition" />
<x-nvl-content::block :block="$block" />
<x-nvl-content::field :value="$value" />
```

Definitions may choose a source-controlled view. Strict view mode rejects a
missing configured view. Rich text is sanitized on mutation and represented by
`Nvl\Content\Data\RenderedRichTextData`; only the bundled explicit rich-text
branch emits its already-sanitized HTML without escaping.

Publish the starting views to the default or another allowlisted directory:

```bash
php artisan nvl:content:views:publish
php artisan nvl:content:views:publish --path=resources/views/content --force
```

The destination must remain under `content.view_publishing.allowed_roots`.
Traversal, symlink escape, non-directory targets, and accidental replacement
without `--force` fail or skip safely.

## Immutable composition snapshots

Versioning consumers such as `nvl/templates` use
`Nvl\Content\Content::capture()` and `renderSnapshot()`. Capture stores a bounded,
canonical, JSON-safe composition containing definition schemas/views,
normalized base and locale values, placement tree facts, overrides, and Media
or reference IDs. Every entry is a
`ContentCompositionSnapshotBlockData` containing a typed
`ContentSchemaData`; array hydration restores the same nested DTO graph. The
snapshot includes a SHA-256 integrity version.
Consumers that persist the snapshot can use
`Nvl\Content\Casts\ContentCompositionSnapshotCast` to keep the Eloquent
attribute typed as `ContentCompositionSnapshotData` rather than repeatedly
hydrating untyped arrays.

Later edits to live blocks or placements do not alter the snapshot. Rendering a
snapshot verifies its hash, owner type, owner identifier, record count,
payload size, parent existence, region consistency, depth, and cycle freedom,
then resolves current
Media/reference delivery. This preserves published copy while still enforcing
current binary lifecycle and access decisions.

Do not use a live Content composition as the source of a legally or
operationally immutable template version; capture it at publication.
The hash detects corruption and owner substitution in trusted stored data; it
is not a MAC or digital signature. Do not accept caller-supplied snapshots as
authentic without a consumer-owned signature and trust boundary.

## Public application surface and DTOs

Application code, package integrations, the facade, commands, and optional
HTTP controllers use `Nvl\Content\Content` as the canonical boundary. Its
Actions and services are implementation units behind that application
surface:

| Capability | `Content` method | Input and concurrency |
| --- | --- | --- |
| Discover semantic presets | `presets()` | actor; returns compiled fields and JSON Schemas |
| Discover editor schemas | `definitions()` | actor; returns active `ContentDefinitionData` values |
| Discover declared groups | `groups()` | owner model and actor; includes empty groups |
| Inspect placement facts | `placements()` | owner model, group, and actor; includes hidden placement revisions |
| Bootstrap an editor | `editor()` | owner model, group, and actor; definitions, presets, groups, and placements |
| Synchronize sources | `syncDefinitions()` | actor and dry-run flag |
| Plan/apply definition upgrades | `planDefinitionMigrations()`, `applyDefinitionMigrations()` | bounded plan followed by exact atomic application |
| Browse/read blocks | `blocks()`, `block()` | allowlisted `FilterSet`, actor |
| Create/edit | `createBlock()`, `updateBlock()` | typed mutation DTO; updates require the exact revision |
| Publish/archive/delete/restore | corresponding block methods | expected revision; restore returns a draft |
| Place/edit/remove | `place()`, `updatePlacement()`, `deletePlacement()` | typed placement DTOs and expected revision |
| Render live | `render()` | owner model, group, normalized locale, actor, public policy |
| Capture/render immutable | `capture()`, `renderSnapshot()` | owner model/group or `ContentCompositionSnapshotData`, actor |

`UpdateContentBlockData` defaults to `ContentMutationMode::Patch`, preserving
omitted values, translations, and metadata. `Replace` is explicit and removes
omitted base values, locale rows, and metadata after schema validation. Lists
replace as units; objects are recursively patched. Use an exact expected
revision for every editable persisted resource.

Constructor-inject `Nvl\Content\Content` in Actions, services, controllers, and
other packages. `Nvl\Content\Facades\Content` is a static proxy to that exact
surface for concise Laravel application code; it is not a second execution
path. Every existing-resource transition requires the exact revision.

## Editor projections

New editor UIs should consume the package-owned projection instead of querying
placement, block, definition, or translation tables themselves. Inject the
focused Action when editor composition is the caller's only Content concern:

```php
use Nvl\Content\Actions\GetOwnerContentEditorAction;
use Nvl\Content\Data\ContentActorData;

$editor = app(GetOwnerContentEditorAction::class)->execute(
    $page,
    'homepage',
    ContentActorData::fromAuthenticatable($user),
);
```

Applications already using the canonical service or facade receive the same
DTO and authorization behavior:

```php
use Nvl\Content\Facades\Content;

$editor = Content::editor($page, 'homepage', $actor);
```

`ContentEditorData` contains deterministically ordered definitions, presets,
declared groups, placement DTOs with their editable `ContentBlockData`, and
`placementLimit`, the validated `content.placements.maximum_per_group` ceiling
the UI must enforce. The placed block projection includes its definition key,
lifecycle state, base values, localized values, metadata, and revisions, so a
consumer does not navigate `placement.block.definition` or
`placement.block.translations` itself.

For a page or dashboard index, inject the bounded Action and batch placement
facts rather than eager-loading Content relations in consumer code:

```php
use Nvl\Content\Actions\ListOwnerContentPlacementSummariesAction;

$placementsByOwner = app(ListOwnerContentPlacementSummariesAction::class)
    ->execute($pages, 'homepage', $actor);
```

The bulk projection deduplicates and authorizes every owner before storage
queries, consumes at most 100 persisted owner entries, preserves input identity
order, and enforces the configured per-group placement ceiling before mapping
DTOs. Its non-numeric keys use `<owner-type>:<owner-id>`; for example,
`page:01H...` and `account:42`. This keeps JSON object shape stable for UUID,
ULID, string, and integer owner IDs and permits different owner types to share
the same raw ID. Populated reads use five fixed queries for one or 25 owners of
the same registered type: owner existence, placements, blocks, definitions,
and translations. Empty input returns `[]` without querying.

The consumer's `ContentAuthorization` adapter must treat
`ContentAbility::ListPlacements` with `context.includes_blocks=true` as
permission to disclose the editable blocks placed on that authorized owner.
The ordinary model-returning placement call keeps its original exact
`['group' => $group]` context and does not preload blocks.

`Content::placements()` remains a documented 1.x compatibility API returning
identity-bearing `ContentPlacement` models. New editor and index reads should
use `Content::editor()`, `GetOwnerContentEditorAction`, or the bounded bulk DTO
projection so consumers do not own package query graphs or lazy relations.

## Generated PHP and TypeScript contracts

`nvl/data` is the sole DTO and PHP-to-TypeScript boundary. The Content service
provider registers its source directory automatically. Generate and verify the
application-wide declarations with:

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:manifest
php artisan nvl:data:types:check
```

The generated `Nvl.Content` namespace includes recursive compiled definitions,
preset fields, editor bootstrap data, definition migration plans/results,
mutation inputs, enums, rendered semantic values, and named composition
snapshot blocks. Dynamic, definition-specific `values`, `translations`, and
`overrides` remain `Record<string, unknown>` by design; the runtime definition
and its JSON Schema are the authority for those application-authored keys.
Internal definition sources and server-side migration payloads are hidden from
TypeScript generation.

## Filament and other editor integrations

Content is intentionally editor-neutral. A consumer-owned Filament resource or
page can bootstrap through one call:

```php
$editor = $content->editor($page, 'homepage', $actor);
```

`ContentEditorData` contains the compiled definitions, semantic presets,
declared groups, selected group, the placement ceiling, and revision-bearing
placements with their complete editable block DTOs. A recursive
Filament field factory should select a component by `field.preset` first, then
fall back to `field.type`; nested `fields` and `item` values use that same
function. The definition JSON Schema remains the client validation contract.
Mutations go back through the typed block and placement DTOs with exact
revisions. The package never requires Filament and never stores panel or form
component classes in schemas.

## Authorization and routes

`Nvl\Content\Contracts\ContentAuthorization` is invoked for all reads,
mutations, placements, rendering, and snapshots. The configured adapter denies
non-system callers unless `content.authorization.callback` explicitly returns
true. Bind a consumer policy adapter for larger applications. An adapter may
also implement `Nvl\Content\Contracts\ContentBlockQueryScope` to apply
actor/tenant constraints to block catalog queries before caller-controlled
filters and pagination. Create/update policy contexts include proposed
lifecycle state, and promoting an already-published block from private to
public additionally requires the `Publish` ability.

Management and public routes are independently disabled by default. If
enabled, each has configurable prefix, route-name prefix, and middleware:

```php
'routes' => [
    'management' => [
        'enabled' => true,
        'prefix' => 'api/v1/content',
        'name' => 'nvl.content.management.',
        'middleware' => ['api', 'auth:sanctum', 'throttle:60,1'],
    ],
    'public' => [
        'enabled' => true,
        'prefix' => 'api/v1/content',
        'name' => 'nvl.content.public.',
        'middleware' => ['api', 'throttle:120,1'],
    ],
],
```

Routes are thin adapters over the same DTO/Action authorization boundary.
Enabling a route is not a replacement for a correct authorization binding.
Invalid middleware entries fail closed instead of being silently discarded.

When management routes are enabled they expose:

| Method | Path below the configured management prefix | Route suffix |
| --- | --- | --- |
| `GET` | `/presets` | `presets.index` |
| `GET` | `/definitions` | `definitions.index` |
| `GET` | `/owners/{ownerType}/{ownerId}/groups` | `groups.index` |
| `GET` | `/owners/{ownerType}/{ownerId}/groups/{group}/placements` | `placements.index` |
| `GET` | `/owners/{ownerType}/{ownerId}/groups/{group}/editor` | `editor.show` |
| `GET` | `/owners/{ownerType}/{ownerId}/groups/{group}/preview` | `compositions.preview` |
| `GET`, `POST` | `/blocks` | `blocks.index`, `blocks.store` |
| `GET`, `PUT`, `PATCH`, `DELETE` | `/blocks/{block}` | `blocks.show`, `blocks.update`, `blocks.destroy` |
| `POST` | `/blocks/{block}/publish` | `blocks.publish` |
| `POST` | `/blocks/{block}/archive` | `blocks.archive` |
| `POST` | `/blocks/{block}/restore` | `blocks.restore` |
| `POST` | `/owners/{ownerType}/{ownerId}/groups/{group}/blocks/{block}/placements` | `placements.store` |
| `PUT`, `PATCH`, `DELETE` | `/placements/{placement}` | `placements.update`, `placements.destroy` |

The management placement listing includes hidden rows so an editor can repair
or unplace them; preview still honors placement visibility but may render
draft/private blocks after authorization. The `Render` policy receives
`context.public_only` so a consumer can distinguish public delivery from
management preview.

The public route group provides `GET
/owners/{ownerType}/{ownerId}/groups/{group}/composition` as
`compositions.show`. Mutation payloads
use the documented camel-case DTO field names. Controller validation rejects
invalid identifiers, enum values, revisions, tree order, and payload shapes
before an Action executes. The package controllers convert transport-neutral
`ContentException` failures and semantic `InvalidArgumentException` input
failures to stable JSON; stale revisions produce `stale_content`/`409`, old
definition versions produce `definition_migration_required`/`409`, and invalid
content produces `invalid_content`/`422`. They do not intercept authorization
or unexpected infrastructure failures.

## Persistence, concurrency, and events

Tables are:

- `content_definitions`: synchronized source metadata and hashes.
- `content_blocks`: base values, schema/view snapshot, scope, status, revision,
  and actor facts.
- `content_blocks_i18n`: one locale/value object per block.
- `content_placements`: owner/group tree, region, key, order, visibility,
  overrides, and revision.
- `content_revisions`: bounded immutable mutation snapshots.

All mutations own explicit transactions. Definition synchronization is
serialized by a package-wide atomic cache lock and replanned after database
row locks, including during rolling deployments. Update, publish, archive,
delete, placement update, and placement removal operations require the exact
expected revision. Placement rows are locked for tree mutations, deadlocks are
retried, and block deletion is refused until the reusable block has no
placements. Stale writes raise
`Nvl\Content\Exceptions\StaleContentException`.
`Nvl\Content\Events\ContentBlockChanged` and
`Nvl\Content\Events\ContentPlacementChanged` dispatch after commit. Placement
events carry placement, block, owner, group, revision, event, and actor
identity, including after deletion. Definition migrations use the `migrated`
block event and revision snapshot without including content values in
operational plans or events.

Deletion is a soft tombstone that detaches Content-managed Media associations
and preserves the unique scope/key identity. `RestoreContentBlockAction`
restores the block as a draft, revalidates/re-attaches every currently
available authorized Media reference, records a new revision, and rolls the
whole restore back if those links cannot be re-established. Archive is the
preferred reversible way to remove a block only from public delivery.

## Commands

```bash
# Read-only installation/configuration diagnostics
php artisan nvl:content:doctor
php artisan nvl:content:doctor --strict --format=json

# Definition plan and synchronization
php artisan nvl:content:definitions:sync --dry-run
php artisan nvl:content:definitions:sync --format=json

# Stored-value migration plan or one atomic batch
php artisan nvl:content:definitions:migrate --dry-run --format=json
php artisan nvl:content:definitions:migrate --definition=marketing.hero --limit=100

# Guarded Blade starting-view publication
php artisan nvl:content:views:publish
php artisan nvl:content:views:publish --path=resources/views/content --force
```

`--strict` makes the doctor return a non-zero code for an unhealthy schema,
missing columns, incompatible index semantics, missing/incorrect foreign keys,
stale definition mirror, invalid route configuration, missing default view,
incompatible Content/Media connection, or missing authorization binding. It
also rejects persisted placements whose owner alias or group is no longer
declared and verifies atomic definition-sync and placement-lock support.
Pending block versions or missing migration paths are unhealthy. Both
definition dry runs are read-only.

## Operational guidance

- For a version change, deploy the migration classes, synchronize definitions,
  dry-run and apply bounded migration batches, then require a healthy strict
  doctor before accepting editor writes.
- Keep route and config caches in CI; providers are deterministic and do not
  generate artifacts from HTTP requests.
- Keep Media transformation/scanning queues running if block schemas accept
  uploads managed by Media.
- Use eager-rendered composition DTOs at transport boundaries; do not expose
  Eloquent models or raw schema snapshots.
- Keep private delivery behind Media authorization and temporary signatures.
- Back up package tables before schema adoption and run the doctor before and
  after cutover.

## Development and verification

```bash
composer validate --strict
vendor/bin/pint --format agent
vendor/bin/phpstan analyse --level=max
vendor/bin/pest --compact
php artisan nvl:data:types:check
```

The package suite covers clean schema installation, source synchronization,
atomic definition migrations and stale plans, translation fallback, rich-text
sanitization, JSON Schema validation, repeaters, Media associations and
ownership, references, publication, placements, editor bootstrap data,
hidden-subtree pruning, snapshot integrity and malformed trees, typed snapshot
hydration, preset publication invariants, rendering, Blade components,
optimistic concurrency, facade lifecycle coverage, route defaults, bounded
definition discovery, generated contracts, and diagnostics.

## License

NVL Content is open-sourced under the MIT License.
