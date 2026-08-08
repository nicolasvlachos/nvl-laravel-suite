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

The package is intended for Laravel applications that need a stable front-end content entry point without adopting an admin UI or a monolithic CMS. It supports PHP 8.3–8.4 and Laravel 12–13.

## Requirements and installation

Install the package in a clean Laravel application:

```bash
composer require nvl/laravel-suite:^1.0
php artisan vendor:publish --tag=pages-config
php artisan vendor:publish --tag=pages-migrations
php artisan vendor:publish --tag=pages-skills
php artisan migrate
php artisan nvl:pages:doctor --strict
```

Laravel package discovery registers the provider. Composer installs Content, Data, Filterable, Metafields, SEO, and Translatable automatically. The default tables are `pages`, `pages_i18n`, and `page_tree_locks`; their names and the database connection are configurable.

Routes are disabled by default. Publishing migrations is optional because package migrations load automatically while `pages.migrations.enabled` is true.

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
