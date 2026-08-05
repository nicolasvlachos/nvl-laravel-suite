# Upgrading NVL Data

## Upgrading to 1.0

Version 1.0 removes application-specific source paths, old TypeScript namespaces, and speculative DTO doctrine.

1. Register source roots through `TypeScriptSourceRegistry` with stable keys.
2. Move pagination contracts to `Nvl\Data\Data\PaginatedCollection`.
3. Replace previous generated symbols with the `Nvl.<Package>.*` namespace.
4. Keep the default split writer, or set `writer=global` when a consumer requires one physical declaration file.
5. Remove legacy `RecordTypeScriptType` attributes; use native PHP types, `DataCollectionOf`, nested DTOs, or supported TypeScript type attributes.
6. Run `php artisan nvl:data:types:generate`; this writes manifest schema version 2 with separate artifact `hash` and full metadata `revision`.
7. Commit the declaration files and manifest, then run `php artisan nvl:data:types:check`.
8. Keep generated-type routes disabled unless middleware and artifact roots are explicitly configured.

Generation in an HTTP request is not supported in 1.0.
