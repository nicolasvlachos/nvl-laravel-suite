# Consumer readiness

This document is the rendered companion to the authoritative machine-readable
catalog in `tools/consumer-readiness.php`. It covers every package listed by
`tools/package-family.php` and the seven cross-suite consumer recommendations.
The Contract suite rejects missing packages, unsupported symbols or commands,
broken evidence paths or anchors, unjustified exceptions, and catalog/matrix
drift.

`Pass` means the package has a bounded public contract and executable or
documented evidence. `N/A` means the concern is outside the package's ownership
and the catalog records why. There are no `QUESTION` classifications: an
uncertain claim is a finding until it becomes a tested decision or a gap.

## Consumer boundary doctrine

- **Allowed:** Actions, explicit services, contracts, DTOs, enums, owner traits, and documented identity/result models.
- **Compatibility-only in 1.x:** Consumer-initiated package model queries and relation aggregates remain supported only where already documented.
- **Forbidden:** Consumer writes through package models, builders, raw tables, pivots, or storage paths.
- **Explicit exceptions:** Filterable consumer builders, Translatable opted-in scopes, adoption migrations, and documented legacy bridges.

The package rows below identify the preferred application entry point and place
model access into this shared policy. Compatibility is a transition guarantee,
not the recommended shape for new consumer code. A package-documented facade is
an adapter to its allowed Action or explicit service, not a separate policy
class.

## Readiness matrix

| Package | Application API | Read performance | Media lifecycle | Locale fallback | Ownership boundary | Presets | Adoption and diagnostics |
|---|---|---|---|---|---|---|---|
| `activity` | Pass | Pass | N/A | N/A | N/A | N/A | Pass |
| `auth` | Pass | Pass | N/A | N/A | N/A | N/A | Pass |
| `comments` | Pass | Pass | Pass | N/A | N/A | N/A | Pass |
| `content` | Pass | Pass | Pass | Pass | Pass | Pass | Pass |
| `csv` | Pass | Pass | N/A | N/A | N/A | N/A | N/A |
| `data` | Pass | N/A | N/A | N/A | N/A | N/A | N/A |
| `filterable` | Pass | Pass | N/A | N/A | N/A | N/A | N/A |
| `forms` | Pass | Pass | N/A | Pass | Pass | N/A | Pass |
| `mail-notifications` | Pass | Pass | N/A | N/A | N/A | N/A | Pass |
| `media` | Pass | Pass | Pass | Pass | Pass | Pass | Pass |
| `metafields` | Pass | Pass | N/A | Pass | Pass | N/A | Pass |
| `pages` | Pass | Pass | N/A | Pass | Pass | N/A | Pass |
| `primitives` | Pass | N/A | N/A | N/A | N/A | N/A | N/A |
| `seo` | Pass | Pass | N/A | Pass | Pass | N/A | Pass |
| `settings` | Pass | Pass | N/A | N/A | N/A | N/A | Pass |
| `support` | Pass | N/A | N/A | N/A | N/A | N/A | N/A |
| `taxonomy` | Pass | Pass | N/A | Pass | Pass | N/A | Pass |
| `templates` | Pass | Pass | Pass | Pass | Pass | N/A | Pass |
| `translatable` | Pass | Pass | N/A | Pass | Pass | N/A | Pass |
| `translations` | Pass | Pass | N/A | N/A | Pass | N/A | Pass |

## Application APIs

Consumers use the smallest package-owned Action, service, facade, contract, or
trait that represents their use case. A global suite facade would erase package
ownership and is intentionally absent. Direct package-model queries are not a
canonical application boundary and remain compatibility-only in the 1.x line.

| Package | Canonical entry point | Direct-model policy |
|---|---|---|
| `activity` | `ActivityLog`, `ActivityReadService`, and model activity traits | Compatibility-only in 1.x: documented model queries remain supported; use read/recording services for new code. |
| `auth` | Feature Actions and `AuthManagementAccess` | Compatibility-only in 1.x: documented identity model queries remain supported; writes use Auth Actions. |
| `comments` | Comment Actions plus `HasComments`/`AcceptsComments` | Compatibility-only in 1.x: owner-trait relationships are allowed; direct Comment queries are transitional and writes use Actions. |
| `content` | `Content` and Content Actions | Compatibility-only in 1.x: documented model queries remain supported; new reads and all writes use Content contracts. |
| `csv` | `CSVImport`, `CSVExport`, and `CSVAnalyzerService` | N/A: CSV exposes no package model. |
| `data` | `DataTransform` and generated-type services | N/A: Data exposes no package model. |
| `filterable` | `FilterSet`, allowlisted schemas, and `Filterable` | Explicit exception: the allowlisted builder on a consumer-owned model is the public API. |
| `forms` | Form and FormEntry Actions/contracts | Compatibility-only in 1.x: documented model queries remain supported; new reads and all writes use Forms Actions. |
| `mail-notifications` | Administrative read Actions and `TrackingLifecycle` | Compatibility-only in 1.x: documented delivery-model queries remain supported; new reads use package Actions. |
| `media` | `MediaLibrary`, Media Actions, `MediaQueryService`, and owner traits | Compatibility-only in 1.x: owner-trait relationships are allowed; direct Media queries are transitional and lifecycle writes stay in Media. |
| `metafields` | Definition/value Actions and `HasMetafields` | Compatibility-only in 1.x: owner-trait relationships are allowed; direct package queries are transitional and writes use Actions. |
| `pages` | Page Actions and resource-handler contracts | Compatibility-only in 1.x: documented Page queries remain supported; composition and writes use package Actions/services. |
| `primitives` | Value objects, casts, rules, and reference catalogs | N/A: Primitives exposes no package model. |
| `seo` | SEO Actions, owner traits, resolver, renderer, and sitemap contracts | Compatibility-only in 1.x: owner-trait relationships are allowed; direct profile queries are transitional and writes use Actions. |
| `settings` | `SettingRepository`, typed Actions, and `Setting` facade | Compatibility-only in 1.x: documented Setting model queries remain supported; new code uses the repository, facade, or Actions. |
| `support` | `BusinessException` and `ResponseCode` | N/A: Support exposes no package model. |
| `taxonomy` | Taxonomy Actions, tree/resolver services, and owner traits | Compatibility-only in 1.x: owner-trait relationships are allowed; direct Term queries are transitional and mutations use Actions. |
| `templates` | Render/list/mutation Actions and renderer/asset contracts | Compatibility-only in 1.x: documented Template queries remain supported; new reads and all writes use package contracts. |
| `translatable` | Typed definitions, traits, query scopes, resolver, and writer | Explicit exception: opted-in domain models may use the documented Translatable scopes and helpers. |
| `translations` | Scan/import/export/update Actions and services | Compatibility-only in 1.x: documented catalog queries remain supported; new reads and writes use package Actions/services. |

Documented 1.x model APIs are not removed by this policy. They remain supported
until a separately documented breaking release, but new consumer examples use
the canonical surfaces above.

## Performance and cache policy

Every collection surface must impose its own `perPage`, row, scope, chunk, or
scan ceiling. Normalized projections eager-load only relationships used by the
DTO/serializer and include matching key columns. Consumers eager-load declared
owner traits before serializing collections. Tests compare a one-record fixture
with a larger fixture and assert the same query count plus an explicit ceiling;
the catalog points to the authoritative package or integration test.

| Package family | Eager-loading and bound | Query-budget evidence | Cache decision |
|---|---|---|---|
| Activity | Timeline services batch subject/causer relations and enforce timeline limits. | Activity timeline tests | Uncached: actor-scoped append-sensitive audit data. |
| Auth | Principal lists eager-load roles/permissions and all list Actions clamp pages. | Principal management tests | Uncached: revocations and authorization changes must be immediate. |
| Comments | Public/member/management DTO projections own selected eager loads and page limits. | Constant 1-to-25 projection test | Uncached: audience and moderation are actor-sensitive. |
| Content | Editor/scope reads load definitions, values, placements, and translations within explicit row/scope limits. | Constant editor-bootstrap test | Uncached: locale, scope, publication, and actor dimensions make invalidation ambiguous. |
| CSV | Eloquent exports use bounded chunks; imports stream bounded batches. | CSV export tests | Uncached: source streams are caller-owned and freshness is explicit. |
| Filterable | Only allowlisted criteria, sorts, relation depth, and complexity reach caller queries. | Filterable feature tests | Uncached: the caller owns result identity and invalidation. |
| Forms | Render/search/list Actions own field/translation eager loads and page/export bounds. | Forms Action tests | Uncached: admission and privacy policy are request-sensitive. |
| Mail Notifications | Administrative reads are authorized, paginated, selected, and fresh. | Presentation/read tests | Uncached: delivery state changes asynchronously. |
| Media | `MediaQueryService` selects translations/variations explicitly; owner relations are eager-loaded for collections. | Cross-package 1-to-25 owner test | File existence only: disk/path key, configured short TTL, mutation invalidation, idempotent miss policy. |
| Metafields | Owner reads load assignments, definitions, translations, and values as one bounded projection. | Consumer workflow tests | Uncached: typed values are mutation-sensitive. |
| Pages | Resolve/navigation Actions own hierarchy, translations, content, SEO, and result limits. | Pages package tests | Uncached: locale, publication, hierarchy, and dynamic resources are request-sensitive. |
| SEO | Owner projections eager-load localized profiles; sitemap sources chunk and cap output. | Cross-package owner test and sitemap tests | Sitemap only: origin/scope/version key, configured TTL, after-commit invalidation, atomic build lock. |
| Settings | Repository fetches the bounded setting catalog once and `getMany` uses one storage query. | Settings query-count tests | Cached primitive records: configured key/store, forever TTL, after-commit invalidation, bounded-miss stampede policy. |
| Taxonomy | Tree and owner reads eager-load translations and attachments; maintenance commands chunk. | Constant localized-tree test | Uncached results; cache is used only for mutation/maintenance locks. |
| Templates | Stored definition/list/render Actions load versions, assignments, translations, and assets deliberately and paginate. | Templates package tests | Uncached metadata; generated artifacts have explicit render lifecycle. |
| Translatable | Related rows use eager loads and self rows select one deterministic row per group. | Constant eager-loading test | Uncached: transactionally mutable locale rows. |
| Translations | Catalog APIs paginate; scans/imports use bounded explicit operations and process locks. | Consumer contract tests | Uncached: editable rows and generated files must reflect current source state. |

`Support`, `Data`, and `Primitives` have no database reads. Their performance and
cache classification is `N/A`, not an implied cache omission. Cache locks used
for mutation/concurrency do not turn a package into a cached read surface.

The fixture-independence checks run on SQLite in the normal package gate with
one and 25 result records. Their exact ceilings are: Activity 10; Auth 4;
Comments 8 public, 9 member, and 2 management; Content 2; Forms 4; Mail
Notifications 2; the cross-package Content/Comments/Media/Metafields/SEO/
Taxonomy owner projection 7; Metafields 7; Pages 2; Settings 1; Taxonomy 2;
Templates 3; Translatable 2; and Translations 2. The PostgreSQL package and
integration gate reruns the portable behavior against PostgreSQL; it does not
replace the explicit SQLite ceilings.

## Media lifecycle

Media owns binary and association lifecycle. `detach` removes only the selected
association; it does not delete a shared asset. Explicit delete soft-deletes the
record, removes associations after the durable database transition, retains a
diagnostic tombstone, and schedules original/variation file effects through the
Media transaction lifecycle. Shared-use checks prevent one owner from deleting
another owner's asset. Owner soft deletion preserves media; owner force deletion
uses the registered owner semantics and deletes only assets no longer shared.

`nvl:media:reconcile --orphans` inventories unreferenced originals and
variations. Cleanup is dry-run-first, age-bounded, explicitly destructive, and
requires `--force` in production. Storage health, variation regeneration, disk
migration, rollback cleanup, last-association behavior, and missing-file
tombstones are tested in the Media suite.

Content stores Media references through Content/Media contracts. Templates
resolves assets through `TemplateAssetResolver` and the first-party Media
resolver. Neither package writes Media association tables nor manipulates
storage paths directly. Comments likewise delegates attachments to Media.

## Translation determinism

Translatable is the single model-content locale runtime. Resolution order is:

1. exact requested locale;
2. progressively less-specific parents of the requested locale;
3. model-configured fallbacks;
4. globally configured fallbacks;
5. configured default locale;
6. for `AnyAvailable` only, remaining persisted locales in normalized lexical order.

`ExactOnly` stops after step 1. Missing rows and per-field `null` values continue
through the applicable chain. Empty strings, `false`, zero, and empty arrays are
intentional values and stop fallback. `resolveTranslation()` exposes requested
and resolved locale provenance. `ContentLocale` is request/job scoped and must
be reset at explicit worker boundaries. Related collections eager-load the
candidate rows; self-row queries return one deterministic row per logical group.

Content, Forms, Media metadata, Metafields definitions/values, Pages, SEO,
Taxonomy, and Templates declare their fields and register their resources, then
delegate reads and locale policy to Translatable. Their domain Actions retain
validation, synchronization, authorization, activity, and event ownership.
`nvl/translations` remains separate: it manages Laravel UI-string files and the
editable string catalog, not model-content fallback.

## Ownership boundaries

| Owner | Owns | Must delegate |
|---|---|---|
| Content | Structured blocks, field definitions, placements, compositions, publication, snapshots, semantic rendering | Binary lifecycle to Media; locale selection/storage behavior to Translatable; arbitrary custom attributes to Metafields |
| Metafields | Typed custom definitions and values attached to registered owners | Locale selection/storage behavior to Translatable; structured page composition to Content |
| Translatable | Locale catalog and scoped context, related/self storage behavior, fallback/provenance, query scopes, central resource registration | Domain validation, mutation workflow, authorization, activity, and events to the owning package |
| Translations | Laravel UI-string scanning, editable catalog, file import/export | Model-content storage and fallback to Translatable |

The Contract suite rejects literal raw writes from one package to another
package's owned table. Integrations call the owning package's Actions/services:
Content and Templates call Media APIs; localized packages call their own domain
Actions, which use Translatable inside the owning transaction. A central
translation resource registry is discoverability, not permission to bypass a
domain mutation policy.

## Capability-based presets

Only two package capabilities have reusable semantics strong enough for built-in
presets:

- Content provides semantic link, button, image, heading, and banner field
  presets. Built-ins and consumer-defined presets register in the same
  `ContentFieldPresetRegistry` and compile through the same field-definition
  validation path.
- Media provides the image variation baseline (`thumb`, `small`, `medium`, and
  `optimized`). Consumer overrides and additional variations are normalized by
  the same configured variation service used by the built-ins.

Every other package is deliberately `N/A`. Their configuration is either a
utility contract (Data, CSV, Filterable, Primitives, Support) or application
business vocabulary (roles, taxonomies, forms, templates, metafields, settings,
and similar). Shipping presets there would invent domain semantics and make
adoption less safe.

## Adoption upgrades and diagnostics

Package-owned migrations are immutable release artifacts. Consumers choose one
ownership mode: load migrations from the installed suite, or publish and own
copies while disabling automatic loading. Mixing modes is rejected or diagnosed.
Existing same-name tables must be certified by the package's documented
compatibility path; a migration never silently blesses an unknown schema. Schema
evolution after release uses a new forward migration.

Rollback removes only schema introduced safely by that migration. Adopted audit
or business history is forward-only where down-migration would destroy consumer
data. Adoption runs before application traffic switches to package APIs, then
Doctor/reconciliation runs before legacy storage is removed. Dependency order is
Translatable/Media/Content before packages that compose those capabilities.

The canonical [suite adoption matrix](adoption-matrix.md) covers migration
ownership, queues, scheduler entries, replaceable contracts, registered aliases,
generated TypeScript, and Doctor availability for all twenty modules.
`nvl:suite:configuration` renders the effective application state from the same
runtime catalog, and `nvl:suite:doctor --strict` aggregates every enabled package
Doctor.

Stateless packages (`support`, `data`, `csv`, `filterable`, and `primitives`)
have explicit `N/A` operational classifications. Their `UPGRADING.md` files
still define source-level adoption and compatibility changes. Every stateful
package has an upgrade guide and a Doctor command; a supported common legacy
format has a first-party command/API, otherwise the guide says that the
application owns an explicit fail-closed bridge and does not imply automatic
compatibility.

## Verification contract

`tests/Contract/ConsumerReadinessTest.php` verifies the catalog and rendered
matrix without booting the application. Package behavior remains owned by the
focused package tests named in the catalog. The root integration suite proves
cross-package registry composition, strict Doctor execution, and constant-query
owner reads. Distribution changes additionally require the archive and clean
consumer rehearsals described in `docs/releasing.md`.
