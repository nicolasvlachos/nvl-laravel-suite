# Installation profiles

Installation profiles are reproducible module selections, not hidden runtime
modes. Preview the full dependency-complete configuration first, then write it
only after reviewing the exact enabled and disabled module list:

```bash
php artisan nvl:suite:configure --profile=content-platform
php artisan nvl:suite:configure --profile=content-platform --write
php artisan config:clear
```

`nvl:suite:configure` is dry-run-first. It writes only with `--write`, emits all
twenty module keys in canonical order, and atomically replaces only a `.php`
destination inside the application root. Use repeatable `--add` options to add
capabilities and their transitive dependencies to a profile, for example
`--profile=auth-only --add=comments`.

Compare the booted application with the selected profile after configuring
migration ownership, contracts, registries, queues, and schedules:

```bash
php artisan nvl:suite:configuration --profile=content-platform
php artisan nvl:suite:doctor --strict
```

The configuration command reports `MATCH` only when the effective enabled
module set equals the dependency-complete profile. It never prints arbitrary
configuration values or secrets.

Publish the skills for that same effective module set and verify their
read-only ownership/content contract with:

```bash
php artisan nvl:suite:skills:publish
php artisan nvl:suite:skills:doctor --strict
```

The publisher manages only directories recorded in
`.agents/skills/.nvl-suite-skills.json`. It never replaces an unmanaged skill,
even when `--force` is used.

## Upgrade and audit gate

Before changing the suite version, run the published configuration through the
current catalog and audit application source/runtime boundaries:

```bash
php artisan nvl:suite:upgrade:check --strict
php artisan nvl:suite:consumer-audit --strict
```

The upgrade checker is read-only. It reports missing, unknown, and non-boolean
module decisions and lists the migration ownership, host contracts, and
feature-gated scheduler entries that need review for newly encountered modules.
Neither command prints arbitrary configuration values or secrets.

Both commands use stable process outcomes: exit `0` means the requested gate
passed, exit `1` means actionable adoption or boundary findings remain, and
exit `2` means the arguments, destination, configuration source, or audit policy
is invalid.

During 1.x, an omitted module flag remains implicitly enabled for compatibility.
Once every key has been generated and reviewed, opt into strict explicit-module
diagnostics with:

```php
'adoption' => [
    'require_explicit_module_decisions' => true,
],
```

That switch changes diagnostics only; it does not change module registration in
1.x. The future 2.0 default can therefore be adopted deliberately rather than
surprising an existing application.

## Auth only

Enable `auth`.

| Concern | Adoption requirement |
|---|---|
| Effective modules | `data`, `auth` |
| Migrations | Choose package-owned or published application-owned Auth migrations before migrating. |
| Required boundary | Review the resolved `AuthManagementAccess` and every enabled Auth feature contract reported by `nvl:auth:doctor`. |
| Queues and scheduler | No mandatory queue or scheduler. `nvl:auth:prune` is an optional host-owned daily maintenance entry. |
| Verification | `nvl:suite:configuration --profile=auth-only`, `nvl:auth:doctor --strict`, then `nvl:suite:doctor --strict` |

## Content platform

Enable `pages`, `taxonomy`, `templates`, and `translations`.

| Concern | Adoption requirement |
|---|---|
| Effective modules | `support`, `data`, `filterable`, `translatable`, `media`, `content`, `metafields`, `seo`, `taxonomy`, `templates`, `translations`, `pages` |
| Migrations | Choose ownership once for Media, Content, Metafields, SEO, Taxonomy, Templates, Translations, and Pages. Translation rows remain owned by each domain schema. |
| Required boundaries | Review Media, Content, Metafields, SEO, Templates, Translations, and Pages implementations plus every registered owner/resource alias. |
| Queues and scheduler | Configure template-rendering and Media conversion queues when used. Schedule multipart pruning only when multipart uploads are enabled; sitemap warming, redirect pruning, and render recovery are optional host operations. |
| Verification | `nvl:suite:configuration --profile=content-platform`, `nvl:suite:doctor --strict`, and `nvl:data:types:check` |

## Communications

Enable `auth`, `forms`, `mail-notifications`, `templates`, and `translations`.

| Concern | Adoption requirement |
|---|---|
| Effective modules | `support`, `data`, `filterable`, `translatable`, `auth`, `mail-notifications`, `media`, `content`, `templates`, `translations`, `forms` |
| Migrations | Choose ownership once for Auth, Mail Notifications, Media, Content, Templates, Translations, and Forms. |
| Required boundaries | Review Auth management, mail-history reads, scheduled-mail reads, Media, Content, Templates, Translations, and Forms security contracts. Register provider, notifiable, scheduled-message, form-handler, renderer, and owner aliases before cutover. |
| Queues and scheduler | Configure mail delivery, template rendering, Media conversions, and any host-selected form callbacks. When scheduled mail is enabled, both processing and recovery commands are mandatory scheduler entries. |
| Verification | `nvl:suite:configuration --profile=communications`, `nvl:suite:doctor --strict`, and a real queue-worker delivery smoke test |

## Full suite

Keep all twenty module flags enabled.

| Concern | Adoption requirement |
|---|---|
| Effective modules | Every module in the [adoption matrix](adoption-matrix.md) |
| Migrations | Decide package-owned versus application-owned migrations independently for every stateful module; never run both ownership modes. |
| Required boundaries | Replace or explicitly configure every host boundary shown by `nvl:suite:configuration`; enabled management routes must have real authorization. |
| Queues and scheduler | Configure the application queues used by enabled capabilities and install every feature-gated mandatory scheduler entry reported by the strict Doctor. |
| Verification | `nvl:suite:configuration --profile=full-suite`, `nvl:suite:doctor --production --strict`, `nvl:data:types:check`, queue-worker smoke tests, and the application's full test suite |

## Production sequence

Run the effective report before deployment and the aggregate Doctor after the
final cached configuration, routes, migrations, and scheduler have been loaded:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan nvl:suite:configuration --format=json
php artisan nvl:suite:skills:doctor --strict --format=json
php artisan nvl:suite:doctor --production --strict --format=json
```

The production Doctor fails for package Doctor failures, invalid migration
ownership flags, missing required feature-gated scheduler entries, debug mode,
or a missing application key. Package Doctors remain authoritative for schema,
authorization, registry, storage, queue, and unsafe-default checks.
