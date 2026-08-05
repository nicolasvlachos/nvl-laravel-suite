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

## Verify

Test exact PHP/JSON round trips, Unicode, escaped values, custom outputs, malformed inputs, zero-hit scans, format-specific usage, conflicts, concurrent exporters, locks, atomic recovery, backups, pruning, and path attacks.
