# NVL Translations

A standalone Laravel 12–13 file-catalog manager for reading, scanning, editing, synchronizing, and resaving PHP-array and JSON translation files.

Use `nvl/translatable` for locale-specific Eloquent content. This package only manages Laravel language files.

## Purpose and boundary

The workflow is deliberately simple:

1. Read translation files from configured source directories.
2. Synchronize each leaf string into editable database rows.
3. Edit those rows through package Actions or the optional management API.
4. Resave the rows as valid Laravel PHP or JSON translation files.

The database is an editing and synchronization workspace. It is not installed as Laravel's runtime translation loader, does not override file lookup precedence, and does not store translatable model content.

## Requirements and installation

- PHP 8.3+
- Laravel 12 or 13
- `nvl/data`, `nvl/filterable`, and `nvl/support`

```bash
composer require nvl/translations
php artisan migrate
php artisan vendor:publish --tag=translations-config
php artisan vendor:publish --tag=translations-migrations
php artisan vendor:publish --tag=translations-skills
```

The package supplies English and Bulgarian validation copy. Publish overrides when needed:

```bash
php artisan vendor:publish --tag=translations-translations
```

## File formats

Both native Laravel formats are independent and may be synchronized together or separately:

```text
lang/
├── en/
│   ├── messages.php
│   └── validation.php
├── bg/
│   └── messages.php
├── en.json
└── bg.json
```

- PHP catalogs use locale directories and nested arrays.
- JSON catalogs use one `<locale>.json` object per locale.
- PHP rows retain their group path, such as `messages` or `admin/actions`.
- JSON rows use Laravel's full source string as the key.
- Only valid UTF-8 string or null leaf values are managed. Booleans and numbers are rejected instead of being silently coerced. Nested PHP arrays are flattened and reconstructed; empty arrays contain no translation leaves.
- Dots in PHP leaf identities represent nesting. Literal dots or empty strings inside an individual PHP array-key segment are rejected because they cannot be round-tripped without changing the array shape.

## Configure source locations

The application source defaults to Laravel's `lang_path()`, which is the root `lang` directory in Laravel 12–13:

```php
'paths' => [
    'app' => lang_path(),
    'vendor' => lang_path('vendor'),
],

'module_roots' => [],

'discovery' => [
    'modules' => false,
    'vendor' => false,
],
```

The conventions discovered automatically are:

- `app`
- `module:<ModuleName>` from `Modules/<Module>/lang` or `Modules/<Module>/Resources/lang`
- `vendor:<package>` from `lang/vendor/<package>`

Module and vendor discovery are disabled by default. Mail files remain ordinary application PHP groups (`mail` or `mail/*`), not a second overlapping storage scope.

Add explicit module roots before enabling module discovery:

```php
'module_roots' => [
    base_path('Modules'),
],
```

Additional configured file roots become `custom:<name>` scopes:

```php
'custom_scopes' => [
    'shared' => base_path('translations/shared'),
    'frontend' => resource_path('locales'),
],
```

Every configured path must be an absolute local directory. Scope tokens and locale names are validated; HTTP and CLI input is never interpreted as an arbitrary output path.

## Import files into the editable catalog

```bash
php artisan nvl:translations:sync
php artisan nvl:translations:sync --format=php
php artisan nvl:translations:sync --format=json
php artisan nvl:translations:sync --scope=app --scope=custom:shared --format=both
```

Programmatic use:

```php
$result = app(ImportTranslationsAction::class)->execute(
    scopeTokens: ['app'],
    format: 'both',
);
```

The result reports scopes, files, entries, created rows, updated rows, preserved database edits, conflicts, newly missing rows, and warnings.

Import is read-first and mutation-second. With strict mode enabled, a malformed source file stops synchronization before any selected scope changes in the database:

```php
'import' => [
    'fail_on_error' => true,
    'conflict_strategy' => 'fail',
],
```

Conflict strategies:

- `fail` rolls back the selected synchronization and throws a conflict exception, producing a non-zero command exit code.
- `prefer_file` uses the current file value.
- `prefer_database` preserves the editable workspace value.
- `--strategy=interactive` prompts for one of those strategies in an interactive terminal.

Missing configured directories are reported and skipped. A successfully read selected format marks rows absent from that source as missing; parse errors never cause missing markers.

PHP catalog loading is output-buffered: a catalog that emits output is rejected instead of corrupting a console or API response. Files or directories reached through symbolic links are rejected.

## Edit database rows

Use the validated DTO and Action:

```php
$entry = app(UpdateTranslationEntryAction::class)->execute(
    entry: $entry,
    data: UpdateTranslationEntryPayload::validateAndCreate([
        'value' => 'Save changes',
        'expectedRevision' => $entry->revision,
    ]),
);
```

`ListTranslationEntriesAction` provides paginated, filterable administrative reads. `ListTranslationFilterOptionsAction` returns available scope, locale, and PHP-group filters.

Edits use both a database row lock and the same workspace lock as synchronization, and require the current optimistic revision. The source hash is intentionally not replaced by a database edit. A later import can therefore distinguish an unsaved database edit from an unchanged source file.

## Configure output directories

`source` writes back to each scope's configured source directory. Additional output locations are named, trusted maps:

```php
'export_targets' => [
    'source' => [],

    'generated' => [
        'app' => storage_path('translations/generated/app'),
        'custom:shared' => storage_path('translations/generated/shared'),
    ],
],
```

A named target must explicitly map every selected scope. This supports generated directories, deployment staging, frontend handoff directories, or other application-owned locations without accepting filesystem paths from requests.

Named destinations must be distinct from every source scope, the backup directory, and each other. Duplicate discovered scope tokens that point at different roots fail configuration validation.

## Resave files

```bash
php artisan nvl:translations:export --dry-run
php artisan nvl:translations:export --scope=app --format=php --force
php artisan nvl:translations:export --scope=app --format=json --locales=en,bg --force
php artisan nvl:translations:export --scope=app --target=generated --format=both --force
```

Programmatic use:

```php
$result = app(ExportTranslationsAction::class)->execute(
    scopeTokens: ['app'],
    locales: ['en', 'bg'],
    format: 'both',
    target: 'generated',
    prune: false,
);
```

Exports:

- rebuild nested PHP arrays from dot keys;
- sort groups and keys deterministically;
- emit strict PHP files and pretty UTF-8 JSON;
- create missing target directories;
- stage and validate every replacement before changing any target file;
- apply sibling-file atomic replacements as one backed-up batch and restore original contents if a later operation fails;
- preserve existing file permissions when possible;
- never write outside the configured scope target.

Every export performs a fresh authoritative-source read first. Export stops when that read is incomplete, including when best-effort importing is enabled, so an unreadable source file cannot be overwritten from a partial workspace.

Exporting to `source` updates the synchronization hash because the imported source was replaced. Exporting to another named target does not alter source tracking, so a later source import still preserves unexported database edits.

## Pruning

Pruning is opt-in because it deletes stale translation files:

```bash
php artisan nvl:translations:prune \
    --scope=app \
    --locales=en,bg \
    --format=both \
    --target=generated \
    --dry-run
```

Pruning is constrained to the selected configured target, scopes, locales, and formats:

- PHP pruning only considers `.php` files below the selected locale directory.
- JSON pruning only considers the selected `<locale>.json` file.
- Other extensions and unselected locales are untouched.

## Source usage scanning

Configure the application source paths and extensions scanned for literal Laravel translation keys:

```php
'scan' => [
    'paths' => [
        base_path('app'),
        base_path('extensions'),
        resource_path('views'),
        resource_path('js'),
    ],
    'extensions' => ['php', 'blade.php', 'js', 'jsx', 'ts', 'tsx', 'vue'],
    'retention_days' => 30,
    'namespaces' => [
        'content' => 'module:Content',
        'package-ui' => 'vendor:package-ui',
    ],
],
```

```bash
php artisan nvl:translations:scan
php artisan nvl:translations:unused --help
```

Dynamic keys cannot be discovered statically. Preserve them in unused reports with `translations.scan_allowlist`.

The scanner is intentionally heuristic: it records only configured literal-key call patterns. The package defaults cover Laravel helpers, `Lang::get`, `Lang::choice`, Blade `@lang`/`@choice`, and JavaScript `t`/`$t`; override `scan.patterns` only with tested regular expressions. Non-namespaced usages belong to the `app` scope; an unknown namespace is skipped rather than treated as a global usage. Successful scans prune usage history older than `scan.retention_days` when retention is greater than zero.

Each successful scan stores a durable run marker, including zero-hit scans. Latest-scan unused reports therefore do not reuse stale hits, and usage matching keeps PHP and JSON identities separate. Ambiguous namespace names must be resolved explicitly through `scan.namespaces`.

Workspace synchronization uses an atomic cache lock:

```php
'lock' => [
    'store' => 'redis', // null uses the application default
    'seconds' => 300,
    'wait_seconds' => 0,
],
```

All application nodes must use the same lock-capable cache store. Size the lock lifetime above the longest expected import, export, or scan.

## Optional management API

The API defaults to `api/v1/translations`:

```php
'routes' => [
    'enabled' => true,
    'prefix' => 'api/v1',
    'middleware' => ['api'],
    'management_middleware' => ['auth'],
],
```

It provides list, row update, import, export, and scan endpoints. Entry updates require both `value` and `expectedRevision`. Import/export requests accept bounded configured scope-token lists, `php|json|both`, locale filters, a named export target, and the explicit prune flag. Every non-dry-run API export requires `force=true`; pruning additionally authorizes the independent `prune` ability. Stale revisions and source conflicts return `409`, workspace locks return `423`, and unsafe public inputs return `422`. Applications may add Sanctum, verification, permissions, throttling, or response middleware.

Disable package routes when the consumer owns its management controllers:

```php
'routes' => [
    'enabled' => false,
],
```

## Operational workflow

The safe database-editing cycle is:

```bash
php artisan nvl:translations:sync --scope=app --format=both
# Edit rows through the management application.
php artisan nvl:translations:export --scope=app --target=source --format=both --force
git diff -- lang
php artisan nvl:translations:sync --scope=app --format=both
```

Review file diffs before committing. Use `--prune` only when the database catalog is intentionally authoritative for the selected destination.

Inspect installation and workspace status without mutation:

```bash
php artisan nvl:translations:status --format=json
php artisan nvl:translations:doctor --strict --format=json
```

## TypeScript, skill, and quality

DTOs and enums register with `nvl/data`; configured type generation includes them automatically. Publishing `translations-skills` installs package-specific agent guidance.

```bash
composer install
composer quality
```

`composer quality` checks Pint formatting, Larastan, and the isolated Testbench/Pest suite. From this monorepo root, use the package configuration explicitly:

```bash
vendor/bin/pest \
    --test-directory=packages/nvl/translations/tests \
    --configuration=packages/nvl/translations/phpunit.xml.dist \
    --bootstrap=vendor/autoload.php \
    packages/nvl/translations/tests
```

## License

Released under the [MIT License](LICENSE).
