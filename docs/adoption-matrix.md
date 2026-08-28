# Suite adoption matrix

This matrix is the suite-wide consumption checklist. The executable equivalent
is `php artisan nvl:suite:configuration`; production readiness is verified by
`php artisan nvl:suite:doctor --production --strict`.

`Package/application` means the consumer must select exactly one migration
ownership mode. `Domain` means the package supplies translation behavior while
the owning domain supplies its translation table. Optional scheduler entries
are operational recommendations; feature-gated entries marked required are
enforced when their feature is enabled.

## Consumer boundary doctrine

- **Allowed:** Actions, explicit services, contracts, DTOs, enums, owner traits, and documented identity/result models.
- **Compatibility-only in 1.x:** Consumer-initiated package model queries and relation aggregates remain supported only where already documented.
- **Forbidden:** Consumer writes through package models, builders, raw tables, pivots, or storage paths.
- **Explicit exceptions:** Filterable consumer builders, Translatable opted-in scopes, adoption migrations, and documented legacy bridges.

Adoption selects module ownership and operations; it never grants permission to
bypass these package boundaries. Package-documented facades remain adapters to
allowed Actions or explicit services.

| Module | Tables and migration ownership | Queues | Scheduler entries | Replaceable/security contracts | Registered aliases | TypeScript | Doctor |
|---|---|---|---|---|---|---|---|
| `support` | None | None | None | None | None | No | N/A |
| `data` | None | None | None | None | None | Yes | N/A |
| `filterable` | None | None | None | Caller-owned query definitions | None | Yes | N/A |
| `translatable` | Domain-owned translation tables | None | None | Typed definitions and locale policy | Translation resource keys | Yes | `nvl:translatable:doctor` |
| `activity` | Package/application via `activity.migrations.enabled` | `maintenance` for retention jobs | Package-registers `nvl:activity:purge-system` when retention scheduling is enabled | Gate abilities and policies | Activity mappings | Yes | `nvl:activity:doctor` |
| `auth` | Package/application via `nvl-auth.migrations.enabled` | None required | Optional host `nvl:auth:prune` | `AuthManagementAccess` plus enabled feature contracts | Principal/model and extension registries | Yes | `nvl:auth:doctor` |
| `csv` | None | Host-selected import/export queues | None | Caller mappings and transforms | None | Yes | N/A |
| `mail-notifications` | Package/application via `mail-notifications.migrations.enabled` | Mail delivery queue | Required host `nvl:mail-notifications:process-scheduled` and `nvl:mail-notifications:recover-scheduled` entries when scheduling is enabled | `MailNotificationReadAuthorization`, `ScheduledMailReadAuthorization`, tracking/storage/provider contracts | Provider, notifiable, scheduled-factory, and webhook aliases | Yes | `nvl:mail-notifications:doctor` |
| `media` | Package/application via `media.migrations.enabled` | Media conversions | Required host `nvl:media:multipart:prune` entry when multipart is enabled | `MediaAuthorization`, `MediaContentScanner`, `MultipartUploadGateway` | Media owner/collection integration aliases | Yes | `nvl:media:doctor` |
| `comments` | Package/application via `comments.migrations.enabled` | None required | None | `CommentAuthorization`, query scope, actor and author presentation | Target aliases | Yes | `nvl:comments:doctor` |
| `content` | Package/application via `content.migrations.enabled` | None required | None | `ContentAuthorization`, owner/reference contracts | Owner, reference, field type, and preset aliases | Yes | `nvl:content:doctor` |
| `metafields` | Package/application via `metafields.migrations.enabled` | None required | None | `MetafieldAuthorization`, `MetafieldReferenceAuthorization` | Owner and reference aliases | Yes | `nvl:metafields:doctor` |
| `primitives` | None | None | None | Exchange-rate provider when conversion is used | None | Yes | N/A |
| `seo` | Package/application via `seo.migrations.enabled` | None required | Optional host `nvl:seo:sitemap:warm` and `nvl:seo:redirects:prune` entries | `SeoAuthorization`, `SeoImageResolver`, `SitemapArtifactStore` | Owner, sitemap, and structured-data aliases | Yes | `nvl:seo:doctor` |
| `settings` | Package/application via `settings.migrations.enabled` | None required | None | `SettingsAuthorization`, `SettingsAuditContextProvider`, repository | Definition namespaces | Yes | `nvl:settings:doctor` |
| `taxonomy` | Package/application via `taxonomy.migrations.enabled` | None required | None | Explicit owner/vocabulary definitions | Owner and taxonomy aliases | Yes | `nvl:taxonomy:doctor` |
| `templates` | Package/application via `templates.migrations.enabled` | Template rendering | Optional host `nvl:templates:renders:recover` | `TemplateAuthorization`, renderer and owner contracts | Owner, renderer, definition, and asset aliases | Yes | `nvl:templates:doctor` |
| `translations` | Package/application via `translations.migrations.enabled` | None required | None | `TranslationsAuthorization`, source/export profiles | Source scopes and translation resources | Yes | `nvl:translations:doctor` |
| `forms` | Package/application via `forms.migrations.enabled` | Host-selected submission callbacks | None | Rate limiter, spam detector, deletion and privacy policies | Handler, callback, render-data, and error-mapper aliases | Yes | `nvl:forms:doctor` |
| `pages` | Package/application via `pages.migrations.enabled` | None required | None | `PageAuthorization`, `PageRequestContextResolver`, `PageUrlGenerator` | Page resource and shared owner aliases | Yes | `nvl:pages:doctor` |

## Reading the effective report

The configuration command intentionally exposes only allowlisted operational
metadata:

- requested, dependency-enabled, and disabled modules;
- loaded provider classes;
- migration ownership mode and the ownership flag name;
- resolved boundary implementation class names;
- registry alias names and Eloquent morph aliases;
- queue responsibilities, scheduler conditions, and registration status;
- TypeScript participation and Doctor command names.

It never dumps the configuration repository, callback values, credentials,
tokens, encryption keys, webhook secrets, mail payloads, or stored metadata.
