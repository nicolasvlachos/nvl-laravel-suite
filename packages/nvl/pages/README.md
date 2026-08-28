# NVL Pages — API and usage

[← NVL Laravel Suite](../../../README.md)

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/laravel-suite:^1.0` |
| Module identifier | `nvl/pages` |
| PHP namespace | `Nvl\Pages` |
| Service provider | `Nvl\Pages\Providers\PagesServiceProvider` |
| Configuration | `config/pages.php` |

`nvl/pages` is a headless Laravel package for structural pages, four-level navigation trees, localized editorial copy, dynamic resource-backed routes, composed Content blocks, SEO, Metafields, and sitemap discovery.

## Purpose and boundaries

Pages owns URL structure, hierarchy, lifecycle, navigation state, resource-handler registration, resolution, and sitemap participation. It deliberately does not duplicate:

- localized content fields, which belong to `nvl/translatable`;
- page sections and blocks, which belong to `nvl/content`;
- metadata and structured data, which belong to `nvl/seo`;
- application-defined custom fields, which belong to `nvl/metafields`;
- binary assets, which are referenced by Content through `nvl/media`.

The package is intended for Laravel applications that need a stable front-end content entry point without adopting an admin UI or a monolithic CMS. It supports PHP 8.4+ and Laravel 12–13.

## Requirements and installation

Install the package in a clean Laravel application:

```bash
composer require nvl/laravel-suite:^1.0
php artisan vendor:publish --tag=pages-config
php artisan vendor:publish --tag=pages-skills
php artisan migrate
php artisan nvl:pages:doctor --strict
```

Laravel package discovery registers the provider. Composer installs Content, Data, Filterable, Metafields, SEO, and Translatable automatically. The default tables are `pages`, `pages_i18n`, and `page_tree_locks`; their names and the database connection are configurable.

Routes are disabled by default. Publishing migrations is optional because package migrations load automatically while `pages.migrations.enabled` is true.

Choose exactly one migration owner. For automatic vendor loading, leave
`pages.migrations.enabled=true` and do not publish `pages-migrations`. For
host-owned migrations, run
`php artisan vendor:publish --tag=pages-migrations`, set
`pages.migrations.enabled=false` before the first migration, and maintain the
copied files as application migrations. Never run both sources; Laravel
retimestamps published migrations.

## First working page

Use the system actor only from trusted application code such as a deployment seeder or an authorized application Action:

```php
use Nvl\Pages\Actions\CreatePageAction;
use Nvl\Pages\Data\Mutations\CreatePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Pages\Models\Page;

final readonly class CreateAboutPage
{
    public function __construct(private CreatePageAction $createPage) {}

    public function execute(): Page
    {
        return $this->createPage->execute(
            new CreatePageData(
                key: 'company.about',
                slug: 'about',
                status: PageStatus::Published,
                translations: [
                    'en' => [
                        'title' => 'About',
                        'navigationLabel' => 'About',
                        'summary' => 'How the organization works.',
                    ],
                ],
            ),
            PageActorData::system(),
        );
    }
}
```

The provider automatically registers the Page model as:

- a Content placement owner using the `page` alias;
- an SEO owner using the `page` alias;
- a Metafields owner using the `page` alias;
- a Translatable resource under `pages.pages`;
- an SEO sitemap source.

The SEO and Metafields aliases are configurable and checked for collisions. The canonical Content owner alias is intentionally fixed as `page`.

## Hierarchy and paths

Pages use stable UUIDs, globally unique keys, site-scoped sibling slugs, a canonical materialized path, and a SHA-256 path identity. The hierarchy limit defaults to four and cannot be configured above four. Every structural mutation acquires one stable per-site database lock before validating the tree. Moves reject cycles and cross-site parents, update only paths that changed, increment affected revisions, and invalidate the sitemap scope once after commit.

Slugs are structural and locale-independent. Titles, navigation labels, and summaries live only in `pages_i18n` and use Translatable’s deterministic locale fallback.

`CreatePageAction`, `UpdatePageAction`, `MovePageAction`, `DeletePageAction`, and `RestorePageAction` own their transactions. Updates, moves, deletion, and restoration require an exact revision DTO. A parent cannot be deleted while it has children. Lifecycle abilities are checked only when the status actually changes; creating or entering a published/scheduled state requires `publish`, while entering archived requires `archive`.

## Dynamic resource pages

A resource page stores a stable handler alias, never an arbitrary class name from a request. Register handlers in `pages.resources`:

```php
use Domain\Site\CatalogEntryPageHandler;

'resources' => [
    'catalog.entry' => CatalogEntryPageHandler::class,
],
```

The handler implements `PageResourceHandler` or extends `AbstractPageResourceHandler`. It defines:

- a stable alias;
- a relative route pattern such as `{id}`;
- Laravel validation rules for every route parameter;
- the fully constrained Eloquent query, including tenancy, publication, policy, and eager-load conditions;
- fetching from that constrained query;
- a sanitized `PageResourceData` projection;
- optional absolute `SitemapEntry` objects streamed in bounded application-defined chunks.

If a resource page has path `pages/catalog` and its handler pattern is `{id}`, resolution accepts `pages/catalog/42`. Static paths are tested first. Dynamic candidates are prefiltered by structural path-prefix hashes, then evaluated by longest base path. Handler rule keys must exactly match route placeholders. Invalid parameters and missing resources return not-found responses rather than exposing validation or query details.

The package never serializes the resolved Eloquent model. The handler must explicitly construct its public DTO payload.

## Bounded page reads

Consumers should use the focused DTO-first reads for page selectors, key
validation, and one-level public listings instead of querying `Page` or its
translations directly:

```php
use Nvl\Pages\Actions\CheckPageKeyAvailabilityAction;
use Nvl\Pages\Actions\FindPageByKeyAction;
use Nvl\Pages\Actions\ListPageOptionsAction;
use Nvl\Pages\Actions\ListPublicChildPagesAction;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageRequestContextData;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Enums\PublicChildPageOrder;

$actor = PageActorData::fromAuthenticatable($user);
$page = app(FindPageByKeyAction::class)->execute('main', 'about', $actor);
$availability = app(CheckPageKeyAvailabilityAction::class)->execute(
    'main',
    'about',
    $actor,
    exceptId: $page->id,
);
$options = app(ListPageOptionsAction::class)->execute(
    'main',
    'bg',
    $actor,
    search: 'about',
);
$children = app(ListPublicChildPagesAction::class)->execute(
    $page->id,
    new PageRequestContextData('main', 'bg'),
    limit: 24,
    kind: PageKind::Static,
    order: PublicChildPageOrder::Newest,
);
```

`FindPageByKeyAction` trims and validates the site and globally unique key,
keeps the lookup site-scoped, authorizes `View`, and returns `PageData` with its
translation map. `CheckPageKeyAvailabilityAction` authorizes `List` before SQL
and mirrors the actual global unique index, including soft-deleted rows. A
same-site conflict exposes its ID so an update can use `exceptId`; a conflict in
another authorized site reports unavailable without disclosing that page's ID.
An `exceptId` only excludes the same-site row, so a foreign UUID cannot bypass
the write constraint.

`ListPageOptionsAction` returns `PageOptionData(id, key, label, path, kind,
status, revision)` ordered by path and ID. Labels resolve the requested locale
through Translatable fallback, then fall back to the stable key. Empty search
returns the default bounded list, one-character typeahead input returns an
empty collection without storage queries, and longer input searches key, path,
title, and navigation label case-insensitively. The requested limit is clamped
to `pages.limits.maximum_page_options` and an absolute 100-row ceiling. Search
input must be valid UTF-8 without NUL bytes so behavior remains portable across
supported databases.

`ListPublicChildPagesAction` validates the trusted site/locale context, requires
the parent itself to be publicly visible in that site, authorizes
`ViewNavigation` before child SQL, and returns only currently public children as
localized `PublicPageData`. Package-built public projections include the
optional additive `publishedAt` field, using the explicit publication time or
the persisted creation time when publication is immediate. The PHP constructor
and generated TypeScript property remain optional for 1.x source compatibility.
The default uses canonical sibling order. Consumers can allowlist one
`PageKind` and select `PublicChildPageOrder::Newest` to filter and order by the
effective publication timestamp before the requested limit—for example, a
static news-card feed. Results are clamped to
`pages.limits.maximum_public_children` plus the same absolute 100-row ceiling.
Option reads use two fixed queries and populated public-child reads use three,
whether one or 25 records are returned. These projections are uncached because
authorization, locale fallback, publication windows, and hierarchy are
request-sensitive.

## Editor and publication projections

Pages composes its neighboring package reads so applications do not have to
assemble Page, Content, SEO, and Metafields state in controllers. Inject the
smallest projection for the workflow:

```php
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Pages\Actions\GetPageEditorBootstrapAction;
use Nvl\Pages\Actions\GetPagePublicationProjectionAction;
use Nvl\Pages\Actions\ListPageEditorSummariesAction;
use Nvl\Pages\Data\PageActorData;

final readonly class PageWorkspace
{
    public function __construct(
        private ListPageEditorSummariesAction $summaries,
        private GetPageEditorBootstrapAction $editor,
        private GetPagePublicationProjectionAction $publication,
    ) {}

    public function index(string $site, string $locale, PageActorData $actor): LengthAwarePaginator
    {
        return $this->summaries->execute($site, $locale, $actor, perPage: 25);
    }

    public function edit(string $pageId, string $locale, PageActorData $actor): array
    {
        return $this->editor->execute($pageId, $locale, $actor)->toArray();
    }

    public function show(string $pageId, string $locale, PageActorData $actor): array
    {
        return $this->publication->execute($pageId, $locale, $actor)->toArray();
    }
}
```

`ListPageEditorSummariesAction` authorizes the site-level `List` ability before
SQL, clamps the page size to 100, and returns a paginator of
`PageEditorSummaryData`. Each item contains the management `PageData`, a
localized label with stable key fallback, Content placement summaries, and the
site-scoped SEO profile. SEO authorizes every owner before its batched profile
query; a denial returns no summaries and performs no SEO profile query. A
populated one- or 25-page result uses the same fixed query count and at most ten
queries. Definition and preset catalogs intentionally do not repeat on every
row, and both configured and requested page sizes remain under the absolute
100-owner ceiling.

`GetPageEditorBootstrapAction` authorizes and resolves one Page, then returns
`PageEditorBootstrapData`: Page state, the complete Content editor projection,
the site-scoped SEO profile, authorized Metafields, Page kinds and statuses,
registered resource aliases, and the configured maximum depth. Content, SEO,
and Metafields retain their own authorization boundaries; a denial propagates
and no partial bootstrap is returned. Empty optional state is represented by
empty collections or `null`, not by consumer-side fallback queries.

`GetPagePublicationProjectionAction` is the ID-based public seam for a static
Page already known to the application. It requires current public visibility,
authorizes `View`, renders public-only Content, resolves SEO, and returns the
same redacted `ResolvedPageData` shape as path delivery. Use
`ResolvePageAction` when resolving a public path or dynamic resource Page, and
`PreviewPageAction` for authorized management preview; the ID-based publication
Action rejects resource Pages because their handler parameters are path-owned.

These projections are uncached. Authorization, locale fallback, Page lifecycle,
publication windows, Content visibility, SEO, and custom values can all change
within a request-sensitive workflow.

## Content blocks

`Page` implements `ContentOwner` with `HasContent`. Its sections use the
canonical `page` morph alias and `content` composition group. Use Content
through the injected `Nvl\Content\Content` application surface, or its facade,
with the Page model itself; do not pass owner aliases or IDs through
application code. `PageActorData::contentActor()` preserves the same actor
identity at the Content authorization boundary. Public resolution calls
`Content::render()` with `publicOnly: true`, so hidden, private, unpublished,
invalid, or unauthorized references do not enter the page response.

The public `ResolvedPageData` contains:

- locale-resolved, management-redacted `PublicPageData`;
- `RenderedContentCompositionData` with ordered block trees and regions;
- `ResolvedSeoData`;
- optional sanitized `PageResourceData`.

`PageData` is reserved for authorized management reads and previews. It contains lifecycle, revision, translation-map, and sitemap state that public delivery deliberately omits.

There is no page-block pivot in this package because Content already provides placement identity, scope, region, ordering, nesting, revisions, visibility, and Media validation.

## SEO, structured data, and sitemaps

Attach SEO profiles through SEO Actions or the `HasSeo` relation. SEO profiles own localized canonical paths, social metadata, robots rules, images, and JSON-LD providers. Pages without an active indexable SEO profile are emitted by the Pages sitemap source using the page path. Pages with a qualifying SEO profile are left to SEO’s profile source, preventing duplicate responsibility.

Dynamic handlers stream their own canonical `SitemapEntry` objects because only the application knows how to chunk and constrain its resource query. Every page mutation invalidates only the changed site’s sitemap cache after commit.

Configure absolute page URLs:

```php
'urls' => [
    'base_url' => 'https://example.test',
    'locale_prefix' => true,
    'default_locale' => 'en',
],
```

Bind `PageUrlGenerator` for tenant domains, locale domains, signed previews, or another URL policy.

## Navigation, preview, and APIs

Mutation Actions fail closed unless called by a system actor or allowed by a consumer `PageAuthorization` binding. Anonymous reads are allowed only for publicly eligible pages and navigation. Scheduled pages resolve after `published_at`; expired and archived pages do not.

Public HTTP requests use `PageRequestContextResolver`. The default implementation takes the site from `pages.public.default_site` and validates the requested locale against Translatable. It never trusts a caller-supplied site. Bind the contract to a host-, domain-, or tenant-aware implementation for multi-site applications.

The public and management route groups are independent:

```php
'routes' => [
    'public' => [
        'enabled' => true,
        'prefix' => 'api/v1/pages',
        'name' => 'nvl.pages.public.',
        'middleware' => ['api', 'throttle:120,1'],
    ],
        'management' => [
            'enabled' => false,
            'prefix' => 'api/v1/pages/_manage',
        'name' => 'nvl.pages.management.',
        'middleware' => ['api', 'auth', 'throttle:60,1'],
    ],
],
```

The public endpoints resolve `GET /api/v1/pages/{path}` and `GET /api/v1/pages/_navigation`. Management endpoints default to `/api/v1/pages/_manage`, where they list one explicit site, create, inspect, replace, move, preview, soft-delete, and restore pages. The leading-underscore transport segments cannot collide with valid page slugs. Route names, prefixes, and non-empty middleware lists are validated before registration and work with route caching.

## Commands and operations

```bash
php artisan nvl:pages:doctor
php artisan nvl:pages:doctor --strict --format=json
```

The doctor is read-only. It checks all three tables, required columns, configured resource handlers, route and middleware parity, management authorization, path/hash/parent drift, cycles, orphans, depth, lifecycle dates, statuses, and resource aliases. Strict mode exits non-zero when the installation is unhealthy.

Run sitemap generation and Content/SEO/Metafields operations through those packages’ documented commands. Pages does not hide their operational boundaries behind duplicate commands.

## Extension points

- Bind `PageAuthorization` to a policy adapter.
- Bind `PageRequestContextResolver` to trusted tenant/site resolution.
- Bind `PageUrlGenerator` to a site-aware URL implementation.
- Register `PageResourceHandler` implementations in configuration.
- Use Content definitions, field adapters, renderers, and owner placements.
- Use SEO structured-data providers for resource-specific JSON-LD.
- Use Metafields definitions for page-specific custom fields.
- Listen to the after-commit `PageChanged` event.

## Failure and concurrency behavior

Database mutations are atomic on the configured Pages connection and tree mutations are serialized per site. Resource handlers do not receive unvalidated parameters. Duplicate keys, sibling slugs, and paths raise `PageConflictException`; revision mismatches raise `StalePageException`; invalid parents, cycles, excessive depth, and unsafe deletion raise `PageHierarchyException`. Package HTTP adapters render these as stable 409 responses, while invalid mutation invariants render as 422 and invalid public paths as 404.

Content, SEO, and Metafields retain their own authorization and mutation contracts. Applications coordinating writes across packages should configure the same database connection and establish one application-level transaction.

## Verification and development

From this monorepo:

```bash
vendor/bin/pest --test-directory=packages/nvl/pages/tests --configuration=packages/nvl/pages/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/pages/tests
cd packages/nvl/pages && vendor/bin/phpstan analyse -c phpstan.neon.dist --memory-limit=3G
vendor/bin/pint --format agent packages/nvl/pages
php artisan nvl:data:types:check
php tools/validate-package-family.php
```

The test suite boots Pages with only declared dependencies and covers clean migration, redacted static resolution, dynamic handler conditions, localized navigation, hierarchy limits, selective path rebuilding, site locks, lifecycle abilities, stale and duplicate mutations, site-scoped lists, preview, restoration, sitemap delegation, route defaults, and doctor output.

## License

NVL Pages is open-sourced software licensed under the MIT license.
