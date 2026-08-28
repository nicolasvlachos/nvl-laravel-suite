---
name: nvl-translations
description: Implement, integrate, test, or review nvl/translations in Laravel 12–13. Use for PHP or JSON language-file scanning, database workspace synchronization, status and conflicts, deterministic file export, source or target profiles, backups, pruning, path safety, locks, atomic writes, or translation API authorization.
---

# NVL Translations

Treat PHP and JSON language files as authoritative. The database is an editable synchronization workspace, never an implicit replacement source.

## Configure profiles

- Define source and target profiles for application, package, vendor-style, module-style, or explicit custom roots.
- Treat mail catalogs as application PHP groups, never as an overlapping scope.
- Select PHP grouped files, locale JSON files, locales, configured scanner roots, and named output targets.
- Keep roots inside configured boundaries and reject traversal, symlink escape, overlapping destructive scopes, or unintended vendor writes.

## Synchronize safely

- Run `nvl:translations:scan` to discover usages.
- Run `nvl:translations:sync --dry-run` before importing authoritative files into the workspace.
- Run `nvl:translations:status` before editing or exporting.
- Run `nvl:translations:export --dry-run` before writing files.
- Select an explicit conflict strategy when file and workspace hashes diverge.
- Stop exports when the authoritative pre-export read is incomplete.
- Use a shared cache lock, staged atomic sibling files, batch rollback, validation, optional backups, and explicit force for file replacement.
- Run `nvl:translations:prune --dry-run` before removing stale destination files.

## Expose editing

- Keep the management API disabled by default.
- Authorize list, update, import, export, scan, and prune through `TranslationsAuthorization`; pruning requires its own ability.
- Require `force=true` for every non-dry-run API export.
- Require expected workspace versions in `UpdateTranslationEntryAction`.
- Preserve valid UTF-8 string/null values, unambiguous nested PHP keys, long keys, and case-sensitive JSON keys deterministically.

## Build catalog reads

- Build consumer HTTP filters with `Nvl\Translations\Services\TranslationEntryFilterSchema::make(): Nvl\Filterable\Definitions\FilterSchema`; do not instantiate `TranslationEntry` to obtain its schema. `Nvl\Filterable\Http\QueryFilterSetFactory::fromQuery(array $query, FilterSchema $schema): Nvl\Filterable\Data\FilterSet` parses the transport input.
- The schema allows `search` with `equals`; `scope_type`, `scope_name`, `locale`, `format`, and `group` with `equals|in`; `key` with `equals|contains`; and boolean `missing_value`, `changed_since_import`, and `is_missing` with `equals`. It allows at most 25 filters, 5 sorts, 100 values per set filter, and 255 characters per string. Sort aliases are `scope_type`, `scope_name`, `locale`, `format`, `group`, `key`, `updated_at`, `last_imported_at`, and `id`; the default is `-updated_at` with `id` as the tie-breaker.
- Pass the resulting `Nvl\Filterable\Data\FilterSet` to both `Nvl\Translations\Actions\Entries\ListTranslationEntriesAction::execute(int $perPage = 25, ?FilterSet $filters = null): Illuminate\Pagination\LengthAwarePaginator` and `Nvl\Translations\Actions\Entries\GetTranslationCatalogStatisticsAction::execute(?FilterSet $filters = null): Nvl\Translations\Data\TranslationCatalogStatisticsData` for exact filter parity. Listing clamps `perPage` to 1–200 and returns `LengthAwarePaginator<int, Nvl\Translations\Models\TranslationEntry>`.
- `GetTranslationCatalogStatisticsAction` authorizes `Nvl\Translations\Enums\TranslationsAbility::ListEntries` through `Nvl\Translations\Contracts\TranslationsAuthorization` before querying. Do not repeat the authorization in an application service unless that service performs another protected operation.
- `TranslationCatalogStatisticsData` exposes integer `total`, `missing`, `conflicts`, and `changed` fields plus `array<string, int>` `locales` and `scopes` maps. Missing means `is_missing=true`; conflicts mean `sync_status=conflict`; changed means a non-null source hash with durable status `edited` or `conflict`. Preserved edits are stored as `edited`; `preserved` is only an import result counter.
- Locale and canonical scope-token maps are ordered count descending/key ascending and capped at 100 entries. Blank or null locale groups normalize to `unknown`. Scope keys match command tokens such as `app`, `module:Website`, and `custom:shared`. JSON always emits both maps as objects, including numeric-only locale keys. An empty filtered catalog returns zero for every integer and empty maps.
- The statistics action uses three queries regardless of result size: one scalar aggregate and one bounded grouped query per dimension.

```php
use Nvl\Filterable\Http\QueryFilterSetFactory;
use Nvl\Translations\Actions\Entries\GetTranslationCatalogStatisticsAction;
use Nvl\Translations\Actions\Entries\ListTranslationEntriesAction;
use Nvl\Translations\Services\TranslationEntryFilterSchema;

$schema = app(TranslationEntryFilterSchema::class)->make();
$filters = app(QueryFilterSetFactory::class)->fromQuery($request->query(), $schema);

$entries = app(ListTranslationEntriesAction::class)->execute(25, $filters);
$statistics = app(GetTranslationCatalogStatisticsAction::class)->execute($filters);
```

## Verify

Test exact PHP/JSON round trips, Unicode, escaped values, custom outputs, malformed inputs, zero-hit scans, format-specific usage, conflicts, concurrent exporters, locks, atomic recovery, backups, pruning, path attacks, authorization-before-query, filter parity, empty statistics, deterministic aggregate ordering, dimension caps, and query budgets.
