# Upgrading NVL Translations

## Upgrading to 1.0

Version 1.0 treats files as authoritative and the database as an editable workspace.

1. Install every package migration, including unique `identity_hash` columns, durable scan runs, usage `scan_id` linkage, and catalog query indexes.
2. Remove `mail` from configured scope selections; mail catalogs are application PHP groups.
3. Define explicit source, module-root, custom, and named target profiles.
4. Configure a shared lock-capable cache store for multi-node deployments.
5. Run `php artisan nvl:translations:doctor --strict --format=json`.
6. Run scan, `sync --dry-run`, sync, and status.
7. Resolve every source-hash conflict explicitly.
8. Run `export --dry-run`, enable backups, then export.
9. Run `prune --dry-run` before destructive cleanup.

PHP array-key segments containing literal dots or empty strings must be renamed before synchronization; dots are reserved for nested key paths. Exports now stop whenever the pre-export authoritative read reports an incomplete source.

Run a fresh scan after migrating. Usage identities now include the resolved scope, and the new scan-run linkage makes that scan the authoritative baseline while historical usage rows age out according to retention.

Never assume a database edit may silently overwrite a changed file.
