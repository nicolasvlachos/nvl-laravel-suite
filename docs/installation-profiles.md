# Installation profiles

Installation profiles are adoption examples, not new runtime configuration.
Publish `config/nvl-suite.php`, disable every module, enable the profile's direct
modules, and let `SuiteServiceProvider` add transitive dependencies in canonical
order. Compare the resulting application with:

```bash
php artisan nvl:suite:configuration --profile=auth-only
php artisan nvl:suite:doctor --strict
```

The configuration command reports `MATCH` only when the effective enabled
module set equals the dependency-complete profile. It never prints arbitrary
configuration values or secrets.

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
php artisan nvl:suite:doctor --production --strict --format=json
```

The production Doctor fails for package Doctor failures, invalid migration
ownership flags, missing required feature-gated scheduler entries, debug mode,
or a missing application key. Package Doctors remain authoritative for schema,
authorization, registry, storage, queue, and unsafe-default checks.
