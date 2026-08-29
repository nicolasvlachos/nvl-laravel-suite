---
name: nvl-content
description: Implement, integrate, test, or review nvl/content in Laravel 13. Use for source-controlled block definitions, typed or custom fields, localized content, Media and reference values, placements, regions, trees, rendering, immutable composition snapshots, Blade starting views, APIs, or package architecture.
---

# NVL Content

Keep block schemas and Blade views source-controlled. Persist only bounded,
validated values, locale rows, placement facts, lifecycle state, and immutable
composition snapshots.

## Define content

- Register definitions in configuration or deterministic `*.content.php` and
  `*.content.json` sources.
- Mark deployment-critical source roots as `required_definition_paths`; do not
  silently accept a missing authoritative directory.
- Synchronize them through `SyncContentDefinitionsAction` or
  `nvl:content:definitions:sync`. Keep an atomic-lock-capable shared cache so
  rolling deployment processes cannot race first-time definition writes.
- Treat definition versions as persisted contracts. Register one deterministic
  `ContentDefinitionMigration` per sequential version step, dry-run with
  `nvl:content:definitions:migrate --dry-run`, then apply bounded atomic
  batches. Revalidate the target scope and every dependent placement against
  the new contract. Never let an ordinary update silently adopt a newer schema.
- Use built-in aliases or implement `ContentFieldTypeAdapter`; reject duplicate
  aliases and arbitrary class names.
- Use semantic `link`, `button`, `image`, `heading`, and `banner` presets for
  repeated website structures. Add consumer presets through
  `ContentFieldPreset`, not by adding UI classes to schemas.
- Treat preset structure as canonical. A definition may supply its key, label,
  required/localized flags, default, and settings, but must not replace the
  preset type or recursive fields.
- Use the compiled definition JSON Schema and preset catalog for generic
  editors. Keep Filament and other UI-specific components consumer-owned.
- Keep raw `ContentDefinitionSource` values internal. Public definitions,
  preset fields, and snapshot blocks must expose the compiled recursive Data
  contracts used by `nvl/data` TypeScript generation.
- Validate preset partition shape in `normalize()`, final locale-resolved
  invariants in `validate()`, and mirror semantic editor constraints through
  `jsonSchema()`. Non-decorative published images require resolved alt text.
- Implement `ContentFieldDefinitionValidator` when a custom adapter has
  type-specific schema settings that must fail during boot.
- Keep definition and field defaults structurally valid at boot. Defer only
  live external Media/reference existence and authorization checks until use.
- Mark locale-dependent leaves or subtrees localized. Keep structural Media
  IDs, destinations, targets, layout, and emphasis in base values while their
  alt text, labels, captions, and copy live in locale rows.
- Register every Content locale in `translatable.locales`; Content may narrow
  that canonical registry but must not create a second locale universe.
- Keep JSON fields bounded and validate them with a Draft 2020-12 schema.
- Never evaluate database content as PHP or Blade.

## Mutate and compose

- Constructor-inject `Nvl\Content\Content` as the canonical boundary for its
  documented model-first operations. Its facade is a static proxy to the same
  service surface, not a second execution path. Keep undocumented Actions and
  services behind that boundary; inject the documented focused DTO-first editor
  Actions below when no equivalent `Content` method exists.
- The application surface exposes source synchronization, block browse/read,
  the complete block lifecycle, definitions, presets, groups, placements,
  live rendering, and snapshots.
- Pass the exact expected revision for updates and lifecycle transitions.
- Register stable owner aliases directly to Eloquent models implementing
  `ContentOwner`; use `HasContent` for the direct placement relationship and
  depend on `ContentOwnerRegistrar` from provider integrations.
- Declare every owner composition with the `CONTENT_GROUPS` constant, or one
  composition with the `CONTENT_GROUP` constant. Reject undeclared groups and
  return declared groups even before they contain placements.
- Treat groups as independent named owner compositions. Keep placement keys,
  trees, limits, locks, renders, and snapshots group-scoped; keep regions
  inside a group and definition categories in the editor catalog.
- Place blocks only through `Content::place()`.
- Discover active editor schemas through `Content::definitions()`, reusable
  semantic contracts through `Content::presets()`, declared groups through
  `Content::groups()`, and group placement facts through
  `Content::placements()`.
- Prefer `Content::editor()` or
  `Nvl\Content\Actions\GetOwnerContentEditorAction::execute(Illuminate\Database\Eloquent\Model&Nvl\Content\Contracts\ContentOwner
  $owner, string $group, Nvl\Content\Data\ContentActorData $actor): Nvl\Content\Data\ContentEditorData` when a
  consumer needs the complete typed definitions/presets/groups/placements
  bootstrap. Use `ContentEditorData::placementLimit` as the editor ceiling and
  `ContentPlacementData::block` for definition, lifecycle, values,
  translations, metadata, and block revision; do not navigate Eloquent block
  relations.
- For bounded indexes, inject
  `Nvl\Content\Actions\ListOwnerContentPlacementSummariesAction` and call
  `execute(iterable $owners, string $group, Nvl\Content\Data\ContentActorData
  $actor): array<string, list<Nvl\Content\Data\ContentPlacementData>>`.
  Pass zero to 100 persisted owner entries. Empty input performs no query;
  duplicate canonical identities are collapsed; every unique owner is
  authorized before SQL; one and 25 owners of one type use the same five-query
  projection. Read results by the serialization-safe `<owner-type>:<owner-id>`
  key, such as `page:01H...` or `account:42`.
- Treat `Nvl\Content\Enums\ContentAbility::ListPlacements` with
  `context.includes_blocks=true` as disclosure of
  the placed editable block DTO. Keep this owner-scoped decision explicit in
  the consumer authorization adapter.

```php
use Nvl\Content\Actions\GetOwnerContentEditorAction;
use Nvl\Content\Actions\ListOwnerContentPlacementSummariesAction;
use Nvl\Content\Data\ContentActorData;

$actor = ContentActorData::fromAuthenticatable($user);
$editor = app(GetOwnerContentEditorAction::class)
    ->execute($page, 'content', $actor);
$placementsByOwner = app(ListOwnerContentPlacementSummariesAction::class)
    ->execute($pages, 'content', $actor);
$pagePlacements = $placementsByOwner['page:'.(string) $page->getKey()] ?? [];
```

- For exact editor lookups, inject
  `Nvl\Content\Actions\FindContentBlockByKeyAction::execute(string $key,
  Nvl\Content\Data\ContentActorData $actor): Nvl\Content\Data\ContentBlockData`
  or
  `Nvl\Content\Actions\FindContentPlacementAction::execute(Illuminate\Database\Eloquent\Model&Nvl\Content\Contracts\ContentOwner
  $owner, string $group, string $idOrKey,
  Nvl\Content\Data\ContentActorData $actor): Nvl\Content\Data\ContentPlacementData`.
  Block keys must be byte-exact and unambiguous across active scopes. Placement
  IDs/keys stay byte-exact and owner/group scoped, reject collisions, and never
  compare a non-UUID key with the UUID primary-key column.
- Replace a placed block through
  `Nvl\Content\Actions\ReplaceContentPlacementAction::execute(Illuminate\Database\Eloquent\Model&Nvl\Content\Contracts\ContentOwner
  $owner, string $group, string $placement, string $block, int
  $expectedRevision, Nvl\Content\Data\ContentActorData $actor):
  Nvl\Content\Data\ContentPlacementData`. It locks the complete composition,
  revalidates existing overrides against the replacement definition, and
  changes only block identity and revision.
- Reorder with a complete
  `Nvl\Content\Data\Mutations\ReorderContentPlacementsData` set and inject
  `Nvl\Content\Actions\ReorderContentPlacementsAction::execute(Illuminate\Database\Eloquent\Model&Nvl\Content\Contracts\ContentOwner
  $owner, string $group,
  Nvl\Content\Data\Mutations\ReorderContentPlacementsData $data,
  Nvl\Content\Data\ContentActorData $actor): Nvl\Content\Data\ContentEditorData`.
  Include one
  `Nvl\Content\Data\Mutations\ReorderContentPlacementData` for every placement,
  even unchanged rows. Duplicate, partial, stale, cyclic, cross-region, or
  over-limit proposals fail atomically; changed rows update and emit events in
  ID order, and the Action returns a fresh editor DTO.
- Handle `Nvl\Content\Enums\ContentAbility::Place` contexts
  `replaces_placement=true` and `reorders_placements=true` in the consumer
  authorization adapter. These workflows are focused injected Actions, not
  `Content` facade methods, so the original service constructor stays callable.

- Treat model-returning `Content::placements()` as a documented 1.x identity
  compatibility surface. Build new reads from editor or placement-summary DTOs
  so consumers do not serialize lazy package relations.
- Remove leaf placements through `Content::deletePlacement()`; never delete
  placed blocks or silently orphan a child tree.
- Use Patch as the safe update default. Request Replace only when omitted base,
  locale, and metadata values are intentionally being removed.
- Keep localized values in block locale rows; placement overrides are
  non-localized.
- Merge locale fallback schema-aware and leaf by leaf. Match translated
  repeater rows to explicit, stable base `_key` values; never infer row
  identity from an array position or let translations invent structural rows.
- Validate regions, parents, depth, cycles, stable placement keys, and ordering.
- Keep a complete subtree in one region, and suppress descendants whenever an
  ancestor is hidden or unavailable.

## Media and references

- Store Media UUIDs in values; let Media own files, associations, scans,
  variations, authorization, and delivery.
- Permit public Media in public blocks. Apply uploader/owner authorization to
  private Media.
- Render through safe Media DTOs or authorized temporary URLs, never internal
  paths.
- Preserve locale on localized Media associations and keep Content and Media
  association writes on the same named database connection.
- Register `ContentReferenceResolver` aliases for consumer models. A resolver
  owns existence, policy, and locale-aware display data. Use the supplied
  `ContentValidationContext` for actor, owner, locale, visibility, field path,
  and public/preview decisions.

## Render and snapshot

- Use `Content::render()` for live model/group compositions.
- Use `Content::capture()` and `Content::renderSnapshot()` when a publishing
  consumer needs immutable copy.
- Keep render-resource caches per composition; never move Media/reference
  projections into static or long-lived worker state.
- Verify snapshot hashes and owner identity on every render.
- Reject oversized snapshots, missing parents, cross-region nesting, excessive
  depth, and cycles. Treat the hash as corruption detection, not authentication
  for untrusted caller input.
- Keep snapshot records as `ContentCompositionSnapshotBlockData` with typed
  `ContentSchemaData`; array hydration must restore the complete DTO graph.
- Persist snapshots with `ContentCompositionSnapshotCast` so consuming models
  expose `ContentCompositionSnapshotData`, not untyped arrays.
- Resolve Media and reference delivery at render time so lifecycle and access
  decisions stay current.
- Use the bundled Blade components or publish starting views with
  `nvl:content:views:publish`.

## Secure and verify

- Bind `ContentAuthorization`; the default denies non-system actors unless an
  explicit callback permits them.
- Implement `ContentBlockQueryScope` on the authorization adapter when block
  catalogs require actor/tenant query constraints. Treat published
  private-to-public updates as Publish operations as well as edits.
- Keep management and public routes disabled until their middleware and
  authorization behavior are tested.
- Enforce payload, metadata, schema, depth, item, string, and snapshot limits.
- Hard-deny `javascript`, `data`, `file`, and `vbscript` URI schemes even when
  consumer configuration attempts to allow them.
- Bound definition file count/size and placements per owner; invalid route
  middleware must fail closed.
- Run `nvl:content:doctor --strict --format=json`, definition dry runs, Pest,
  Pint, PHPStan at maximum strictness, `nvl:data:types:check`, database
  matrices, and clean `nvl/laravel-suite` consumer tests on Laravel 13.
- Treat pending block versions or missing migration paths as deployment
  failures. Require the doctor to verify semantic columns, indexes, and
  foreign keys. Migration plans/events must never contain content values.
