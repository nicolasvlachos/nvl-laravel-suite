# Upgrading

## 1.0

This is the first stable contract. There are no compatibility aliases for
consumer-owned block implementations. Import application data through
application-owned code that maps old definition keys, scopes, localized JSON,
placements, and Media IDs into the public Content Actions.

Before every upgrade, run `php artisan nvl:content:doctor --strict`, back up the
five Content tables, deploy schema changes before code that consumes them, and
run `php artisan nvl:content:definitions:sync --dry-run` before synchronization.
Definitions are source-authoritative: deleting a definition file or config entry
orphans its mirror and never silently deletes content blocks.

If the application owns pre-existing compatible Content tables, keep
`content.migrations.enabled` disabled for that schema unless migration history
has been explicitly reconciled. Package migrations fail closed on table-name
collisions and never adopt an existing table implicitly.

The unreleased v1 schema stores actor identifiers as nullable strings, not
numeric morph columns. Because the package is not published, update the
original migrations and rebuild disposable development databases rather than
adding transitional migrations.

Patch is the default update mode. Callers that intend complete replacement must
send `ContentMutationMode::Replace` explicitly. Parent and child placements
must use one owner, group, and region; remove leaf placements before their
parents and remove all placements before deleting a reusable block.

The pre-release owner resolver boundary has been removed. Register aliases
directly to Eloquent model classes that implement `ContentOwner` and use
`HasContent`. Placement DTOs no longer accept `ownerType` or `ownerId`;
Actions, renderers, snapshots, and the facade receive the persisted owner model
plus an explicit group. Rebuild disposable databases so placements include the
group column and group-scoped unique/index contracts.

Pre-release resolver implementations must use the final
`ContentReferenceResolver` signatures: both `exists()` and `display()` receive
`ContentValidationContext`. Read actor, locale, resolved owner, visibility,
field path, and public/preview state from that context. Pages and Templates now
delegate their actual actors into Content, so consumer authorization must
explicitly permit those renders instead of relying on an internal system
bypass.

Placement mutations require an atomic-lock-capable cache store. Configure
`content.placements.lock_seconds` and `lock_wait_seconds` for the deployment
environment; `nvl:content:doctor --strict` fails when the active cache store
cannot provide locks. Contract changes to a synchronized definition must also
increase its version.

Every stored definition version change now requires an explicit sequential
`ContentDefinitionMigration` chain whenever older blocks exist. Deploy and
register those classes before synchronizing the new source version, run
`nvl:content:definitions:migrate --dry-run`, apply bounded batches, and finish
with the strict doctor. Editing or publishing an old block no longer adopts the
new schema implicitly; it fails with `definition_migration_required` until its
atomic migration succeeds.

The final pre-release rich-content contract uses semantic presets rather than
consumer-copied object schemas. Replace repeated image/link/button/banner
shapes with the built-in preset aliases where appropriate, then increase each
affected definition version. Definitions and the preset catalog now expose
compiled JSON Schema documents; rendered presets return typed DTOs and backed
enums. Nested localized leaves belong in translation rows while their parent
structure remains in base values.

Custom `ContentFieldPreset` implementations must implement `validate()` for
final locale-resolved invariants and `jsonSchema()` for the equivalent editor
contract. Extending `AbstractContentFieldPreset` supplies pass-through
implementations. The built-in image preset now rejects publication of a
non-decorative Media value without resolved alt text.

Registry authoring inputs use the internal `ContentDefinitionSource`; public
registry output remains `ContentDefinitionData` and now contains a typed
`ContentSchemaData`. Preset fields and composition snapshot blocks are typed
DTOs as well. Regenerate declarations with
`php artisan nvl:data:types:generate`, commit the resulting artifacts, and run
`php artisan nvl:data:types:check`.
