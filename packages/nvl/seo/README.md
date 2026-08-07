# NVL SEO

A standalone localized SEO system for Laravel 12 and 13: polymorphic profiles,
deterministic translations, canonical and hreflang URLs, social cards, safe
JSON-LD, media integration, robots policies, and bounded cached XML sitemaps.

## Purpose and boundaries

SEO owns discoverability metadata from persistence through rendering and crawl discovery:

- one site-scoped profile for any Eloquent model;
- locale rows managed by `nvl/translatable`;
- database-enforced normalized path uniqueness;
- resolved title, description, canonical, robots, social, and structured data;
- escaped server-rendered head markup;
- an image resolver for direct URLs or media libraries;
- sitemap source registration, XML generation, caching, and opt-in routes;
- robots.txt generation and an opt-in route;
- Spatie Data and generated TypeScript contracts.

It does not own an admin UI, application roles/policies, content routing, analytics, Search Console submission, CDN configuration, or a hard dependency on `nvl/media`. It provides an optional headless redirect subsystem.

## Requirements and installation

- PHP 8.3+
- Laravel 12–13
- `nvl/translatable` 1.x
- `nvl/data` 1.x
- `ext-json`, `ext-libxml`, `ext-xmlwriter`, and `ext-mbstring`

```bash
composer require nvl/laravel-suite:^1.0
php artisan migrate
php artisan vendor:publish --tag=seo-config
php artisan vendor:publish --tag=seo-skills
```

Publish migration sources only when the application requires copied migrations:

```bash
php artisan vendor:publish --tag=seo-migrations
```

Configure content locales:

```php
// config/translatable.php
return [
    'locales' => ['en', 'bg'],
    'fallback_locales' => ['en'],
];
```

## Data model

`seo_profiles` stores stable attachment and nonlocalized crawl policy:

- scope, polymorphic type, and owner identifier;
- index/follow and preview directives;
- sitemap inclusion, priority, and change frequency;
- application metadata.

`seo_profiles_i18n` stores:

- locale and normalized route path;
- title and description;
- canonical override;
- direct image URL or application media reference and alt text;
- Open Graph and Twitter overrides;
- JSON-LD structured data;
- locale-specific metadata.

The database enforces one profile per scope/owner, one translation per profile/locale, and one normalized path per scope/locale. String owner identifiers support integer, UUID, ULID, and custom keys.

## Add SEO to a model

The action accepts any persisted Eloquent model. The convenience trait is optional:

```php
use Nvl\Seo\Traits\HasSeo;

final class Article extends Model
{
    use HasSeo;
}
```

```php
$article->seoProfiles();             // all scopes
$article->seoProfile();              // configured default
$article->seoProfile('publication'); // explicit scope
```

The trait only reads relations; writes always go through Actions.

Polymorphic owner rows cannot have a database-level cascading foreign key. The
trait deliberately does not install a deleting observer, because owner deletion
policy belongs to the application. Before hard-deleting an owner, explicitly
delete each profile through `DeleteSeoProfileAction`; for soft-deleted owners,
choose whether profiles should remain resolvable or be archived. This keeps
revision checks, domain events, translation cleanup, and sitemap invalidation
inside the supported mutation boundary.

## Create or update

```php
use Nvl\Seo\Actions\SyncSeoProfileAction;
use Nvl\Seo\Data\Mutations\SeoProfilePayload;

$payload = SeoProfilePayload::validateAndCreate([
    'isIndexable' => true,
    'isFollowable' => true,
    'sitemapIncluded' => true,
    'sitemapPriority' => '0.8',
    'sitemapChangeFrequency' => 'weekly',
    'translations' => [
        'en' => [
            'path' => '/articles/reliable-systems',
            'title' => 'Reliable Systems',
            'description' => 'A practical guide to reliable systems.',
            'imageUrl' => 'https://cdn.example.com/reliable-systems.jpg',
            'imageAlt' => 'A diagram of a reliable system',
            'structuredData' => [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => 'Reliable Systems',
            ],
        ],
        'bg' => [
            'path' => '/bg/statii/nadezhdni-sistemi',
            'title' => 'Надеждни системи',
            'description' => 'Практическо ръководство за надеждни системи.',
        ],
    ],
]);

$profile = app(SyncSeoProfileAction::class)->execute(
    owner: $article,
    data: $payload,
);
```

The action owns the transaction, writes translations through `TranslationWriter`, refreshes state, dispatches `SeoProfileChanged` after commit, and invalidates sitemap cache after commit. The owner must be persisted.

For a race-safe first write, pass `expectedRevision: 0`. Updates use the exact
positive revision returned by `SeoProfileData`. Programmatic callers may omit
the token only when deliberately choosing last-write-wins behavior; management
updates always require it.

### Patch and replace

Patch is the safe default:

```php
$sync->execute($article, SeoProfilePayload::from([
    'translations' => [
        'en' => ['title' => 'Updated title'],
    ],
]));
```

It changes only supplied fields/locales. Explicit null clears a field; omitted data remains.

Replace deletes omitted locales:

```php
$sync->execute(
    owner: $article,
    data: $payload,
    translationMode: TranslationSyncMode::Replace,
);
```

Require deliberate confirmation before replace.

### Site scopes

```php
$profile = $sync->execute(
    owner: $article,
    data: $payload,
    scope: 'wholesale-store',
);
```

Scope is immutable profile identity. It is normalized to a lowercase 1–100 character slug using letters, numbers, dots, underscores, and hyphens. Paths may repeat across scopes but not within one scope and locale.

## Localized reads and centralized discovery

`SeoProfile` implements `TranslatableModel`:

```php
$title = $profile->translated('title', 'bg');
$resolution = $profile->resolveTranslation('description', 'bg');

$resolution->resolvedLocale;
$resolution->usedFallback();
```

Resolution is per field. Null may fall back; an intentional empty string remains empty. Use `withAllTranslations()` for administrative reads.

SEO self-registers as `seo.profiles` in `TranslationResourceRegistry`, so it
participates in `nvl:translatable:gather` and centralized reporting without a
second translation system. Its mutation policy is `DomainActionOnly`:
centralized translation writes are rejected because they would bypass profile
revision, route-conflict, event, and sitemap-invalidation invariants. Write
localized SEO through `SyncSeoProfileAction`.

## Resolve and render metadata

```php
$seo = $resolver->resolve(
    owner: $article,
    locale: 'bg',
    scope: 'default',
);

$seo->title;
$seo->description;
$seo->canonicalUrl;
$seo->robots;
$seo->alternates;
$seo->openGraph;
$seo->openGraphLocales;
$seo->twitter;
$seo->structuredData;
```

Resolution applies requested locale/fallback, profile values and localized overrides, site defaults, title branding, and absolute URL resolution from `seo.site.base_url`.

If no canonical override exists, the normalized path becomes the self-referential
canonical. Alternates include every routable translation and `x-default` when
the fallback locale is routable. `openGraphLocales` carries the other available
locales separately so the renderer can emit repeated `og:locale:alternate`
properties without weakening the typed Open Graph map.

Render in Blade:

```blade
{!! $seoManager->for($article, 'bg')->toHtml() !!}
```

Or:

```blade
@seo($article)
```

`SeoHeadRenderer` escapes titles and attributes and encodes JSON-LD with HTML-sensitive flags. It never accepts pre-rendered tags. For Inertia/API frontends, serialize `ResolvedSeoData`.

## Social images and `nvl/media`

Direct URLs use `DirectSeoImageResolver`. To resolve `imageReference` with Media, implement the host adapter:

```php
final readonly class MediaSeoImageResolver implements SeoImageResolver
{
    public function __construct(
        private MediaAssetService $assets,
    ) {
    }

    public function resolve(SeoImageContext $context): ?SeoImage
    {
        if ($context->reference === null) {
            return null;
        }

        $media = Media::query()->find($context->reference);

        if ($media === null || ! $media->is_public) {
            return null;
        }

        return new SeoImage(
            url: $this->assets->url($media),
            alt: $context->alt,
        );
    }
}
```

```php
$this->app->bind(SeoImageResolver::class, MediaSeoImageResolver::class);
```

`SeoImageContext` provides the owner profile, selected locale row, requested locale, and field-level fallback values. Only public, crawler-accessible assets should become social URLs. Use `MediaAssetService`; never concatenate storage paths or expose private signed URLs as permanent previews.

## Resource-aware structured data

```php
$schema = $builder->schema('Article', [
    'headline' => 'A localized article',
    'datePublished' => '2026-07-27T09:00:00+00:00',
]);

$breadcrumbs = $builder->breadcrumbs([
    ['name' => 'Home', 'url' => 'https://example.com'],
    ['name' => 'Articles', 'url' => 'https://example.com/articles'],
]);
```

A translation may store one associative schema object, a list of objects, or an
`@graph`. Editor-authored data is useful for exceptional pages, but repeatable
resource schemas should use a provider so the output stays synchronized with
the underlying model.

Implement `StructuredDataProvider` for any Eloquent resource:

```php
use Illuminate\Database\Eloquent\Model;
use Nvl\Seo\Contracts\StructuredDataProvider;
use Nvl\Seo\Data\StructuredDataContextData;
use Nvl\Seo\Data\StructuredDataNodeData;
use Nvl\Seo\Enums\StructuredDataType;

final class ArticleStructuredDataProvider implements StructuredDataProvider
{
    public function provide(
        Model $resource,
        StructuredDataContextData $context,
    ): iterable {
        yield StructuredDataNodeData::make(
            type: StructuredDataType::Article,
            id: $context->canonicalUrl.'#article',
            properties: [
                'headline' => $resource->title,
                'description' => $context->description,
                'url' => $context->canonicalUrl,
                'datePublished' => $resource->published_at?->toAtomString(),
                'dateModified' => $resource->updated_at?->toAtomString(),
                'mainEntityOfPage' => [
                    '@id' => $context->canonicalUrl.'#webpage',
                ],
            ],
        );
    }
}
```

Register it in configuration:

```php
'structured_data' => [
    'providers' => [
        [
            'key' => 'content.article',
            'resource' => Article::class,
            'provider' => ArticleStructuredDataProvider::class,
            'priority' => 100,
        ],
    ],
],
```

Or register a resolved provider from an application service provider:

```php
$registry->register(
    key: 'content.article',
    resourceClass: Article::class,
    provider: $provider,
    priority: 100,
);
```

`StructuredDataContextData` supplies the resource/profile identity, locale,
scope, canonical URL, title, description, image, site name, and site URL. A
provider therefore does not need to repeat SEO resolution or infer the active
locale.

### Graph composition and precedence

By default SEO emits one script containing a shared-context `@graph`:

- a stable `WebSite` node (`{siteUrl}#website`);
- a stable `WebPage` node (`{canonicalUrl}#webpage`);
- provider nodes in ascending priority and deterministic key order;
- persisted translation nodes last.

Nodes with the same `@id` are merged. Higher-priority providers override lower
priorities, and explicit persisted fields override generated fields. Anonymous
nodes are preserved. Use stable absolute IDs and references so Article,
Product, Event, Organization, Person, BreadcrumbList, ImageObject, and other
nodes form one connected graph.

Configure the source policy explicitly:

```php
'structured_data' => [
    'mode' => 'merge', // persisted | generated | merge
    'automatic_web_site' => true,
    'automatic_web_page' => true,
    'maximum_bytes' => 262_144,
    'maximum_depth' => 16,
    'maximum_items' => 1_000,
],
```

`StructuredDataType` provides convenient common types but never acts as a
closed allowlist; future or extension schema.org types may be supplied as safe
type strings, including valid leading-digit names such as `3DModel`.
`StructuredDataBuilder::node()`, `reference()`, `graph()`,
`schema()`, and `breadcrumbs()` protect JSON-LD identity and shape.

Validation enforces JSON encodability, schema.org context, root type/graph
grammar, safe node identifiers, and configured size/depth/item limits. It does
not claim semantic completeness or rich-result eligibility. Providers must
include only accurate information represented in the visible page and must
validate type-specific required fields before returning a node.

Verify rendered pages with the
[Google Rich Results Test](https://search.google.com/test/rich-results) and the
[Schema.org Markup Validator](https://validator.schema.org/). Search engines
may understand valid schema.org data without offering a rich result, and valid
markup never guarantees one.

## Incoming paths

```php
$profile = app(SeoRouteResolver::class)->resolve(
    path: '/bg/produkti/chervena-roklya/',
    locale: 'bg',
    scope: 'default',
);
```

The resolver uses the same normalization/fingerprint as writes and loads translations. Application routing remains application-owned.

`UniqueSeoPath` provides an early validation error; the database constraint remains the race-safe authority.

## Optional management API

The package is headless and management routes are disabled by default. Register
stable aliases rather than accepting model classes from HTTP:

```php
'owners' => [
    'article' => Article::class,
    'page' => Page::class,
],

'management' => [
    'enabled' => true,
    'path' => 'api/v1/seo',
    'name' => 'nvl.seo.management.',
    'middleware' => ['api', 'auth', 'throttle:60,1'],
],

'authorization' => [
    'ability' => 'manage-seo',
],
```

The path and route-name prefix are validated; the name receives a trailing dot
automatically. Bind `SeoAuthorization` for policy-based control or configure a
Gate ability. The default implementation fails closed. Each authorization
decision receives `SeoAuthorizationContext` with the operation, profile,
registered source owner/alias, target owner/alias for duplicates, and scope.

| Method | Relative path | Default name | Purpose |
| --- | --- | --- | --- |
| `GET` | `/profiles/status` | `nvl.seo.management.profiles.status` | Aggregate active/archived counts |
| `GET` | `/profiles` | `nvl.seo.management.profiles.index` | Filtered paginated list |
| `POST` | `/profiles` | `nvl.seo.management.profiles.store` | Create for a registered owner alias |
| `GET` | `/profiles/{profile}` | `nvl.seo.management.profiles.show` | Inspect privileged profile data |
| `PUT` | `/profiles/{profile}` | `nvl.seo.management.profiles.update` | Optimistic update |
| `POST` | `/profiles/{profile}/duplicate` | `nvl.seo.management.profiles.duplicate` | Duplicate to an authorized target |
| `PATCH` | `/profiles/{profile}/archive` | `nvl.seo.management.profiles.archive` | Archive or restore |
| `GET` | `/profiles/{profile}/preview` | `nvl.seo.management.profiles.preview` | Resolve a locale preview |
| `DELETE` | `/profiles/{profile}` | `nvl.seo.management.profiles.destroy` | Optimistic delete |

List filters are `scope`, `status`, `ownerAlias`, `page`, and `perPage`
(maximum 200). Responses use `data.items` and `data.meta`. Create accepts
`ownerAlias`, `ownerId`, optional `scope`, and a nested `profile` payload.
Update, archive, and delete require the current revision. Duplicate clears
localized paths and canonicals by default; `copyPaths=true` is explicit and
still subject to path-conflict enforcement.

Package domain failures use a stable JSON envelope for API requests:

```json
{
  "error": {
    "code": "stale_seo_profile",
    "message": "…",
    "context": {
      "profileId": "…"
    }
  }
}
```

Conflict codes use HTTP 409; invalid domain mutations use HTTP 422.

## Sitemaps

Enable public routes only if the application does not own them:

```php
'routes' => [
    'enabled' => true,
    'middleware' => ['web'],
    'name' => 'nvl.seo.',
    'sitemap_path' => 'sitemap.xml',
    'sitemap_chunk_path' => 'sitemap-{chunk}.xml',
    'sitemap_scopes' => ['wholesale'],
    'robots_path' => 'robots.txt',
],
```

`EloquentSeoSitemapSource` reads included/indexable translations in database
chunks with optional frequency/priority and localized alternates:

```php
$xml = app(SitemapGenerator::class)->generate('default');
```

Add application URLs with `SitemapSource`:

```php
final class StaticPagesSitemapSource implements SitemapSource
{
    public function entries(string $scope): iterable
    {
        yield new SitemapEntry('https://example.com/about');
        yield new SitemapEntry('https://example.com/contact');
    }
}
```

Register the class in `seo.sitemap.sources`.

Every source is registered under a unique deterministic key. A cached build
scans the sources once, writes completed XML to the configured Laravel
filesystem, and publishes only a small cache manifest after every artifact is
durable. Concurrent builders use the cache store's atomic lock. Each artifact
is bounded by both `seo.sitemap.max_urls` (maximum 50,000) and
`seo.sitemap.max_bytes` (maximum 52,428,800 uncompressed bytes). When multiple
chunks exist and indexes are enabled, the primary route emits a sitemap index.
Non-default index links retain their scope through a validated query parameter.
List every publicly routable non-default scope in `routes.sitemap_scopes`; the
configured `site.scope` is always allowed. This allowlist prevents public
requests from manufacturing unbounded cache namespaces. The generator fails
instead of publishing an incomplete or oversized set.

Configure `artifact_store`, `disk`, `directory`, `cache_seconds`, `cache_key`,
`max_urls`, `max_bytes`, `index_enabled`, `lock_seconds`, and
`lock_wait_seconds`. A positive cache TTL requires a cache store implementing
atomic locks; the doctor verifies it. Cache/artifact namespaces include the
configured site URL and scope, preventing collisions when infrastructure is
shared across sites. Same-origin and sitemap-directory scope checks are enabled
by default. Valid cross-origin canonical overrides are omitted as primary
entries by the built-in source because noncanonical local URLs do not belong in
the site sitemap; they may still appear as hreflang alternates for an eligible
local entry. Invalid custom-source locations still fail the build. A completed
manifest whose filesystem artifact disappeared is invalidated and rebuilt
once. The built-in source intentionally omits `lastmod`: an SEO-row timestamp
does not prove that page content changed. Custom sources may pass an accurate
source-owned `lastModified`.

Warm or invalidate one scope explicitly:

```bash
php artisan nvl:seo:sitemap:warm --scope=default
php artisan nvl:seo:sitemap:clear --scope=default
```

Warming requires a positive sitemap cache TTL; zero-TTL generation is
intentionally request-local and does not publish artifacts.

Sitemap, chunk, and robots responses include `ETag`, conditional 304 support,
`nosniff`, and matching public cache headers. A zero TTL uses
`no-store, private`.

## Robots

```php
'robots' => [
    'user_agent' => '*',
    'allow' => ['/'],
    'disallow' => ['/internal'],
    'include_sitemap' => true,
    'cache_seconds' => 3600,
    'maximum_bytes' => 512000,
],
```

Directive paths reject whitespace/control characters and fragment markers to
prevent line/comment injection, and generated files are capped at 500 KiB. Use
`isIndexable=false` for `noindex`. robots.txt `Disallow` controls crawling, not
guaranteed indexing, and is not canonicalization.

## Configuration

- `site.scope`: default site/storefront identity;
- `site.name`, `title_separator`, `title_position`: title branding;
- `site.base_url`: canonical and sitemap origin;
- `site.default_image_url`: direct social fallback;
- `site.open_graph_type`, `twitter_site`, `twitter_creator`: social defaults;
- `defaults`: metadata and robots defaults;
- `image_resolver`: image contract implementation;
- `routes`: opt-in public paths, public sitemap-scope allowlist, route-name
  prefix, and middleware;
- `management`: opt-in API path, route-name prefix, and middleware;
- `owners`: stable management/import aliases for Eloquent resource classes;
- `authorization.ability`: fail-closed management Gate ability;
- `structured_data.mode`, automatic baseline nodes, providers, and safety limits;
- `sitemap.sources`, artifact storage, cache/lock controls, origin/scope
  enforcement, and URL/byte limits;
- `redirects`: resolver availability, chain bound, and hit recording;
- `robots.user_agent`, `allow`, `disallow`, `include_sitemap`, cache TTL, and
  byte limit.

Keep environment access in config. Application code reads `config('seo...')`.

## TypeScript

SEO registers with `nvl/data`. Public contracts include `SeoProfilePayload`, `SeoTranslationPayload`, `ResolvedSeoData`, `SitemapChangeFrequency`, and `TwitterCard`.

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
```

## Redirects and imports

`SyncSeoRedirectAction`, `SeoRedirectResolver`, and `SeoRedirectChain` provide
site/locale-aware redirects with status validation, normalized identity,
expiry, loop detection, bounded chain resolution, optimistic revisions, and
hit metadata. Revision `0` protects first creates. Internal targets preserve
safe query strings/fragments; network-path targets and non-HTTP schemes are
rejected. Localized resolution prefers an exact locale and then falls back to a
locale-neutral redirect. Redirect delivery remains application-owned: resolve
the request path and return the typed `ResolvedRedirectData` decision where
appropriate.

Soft-deleted redirects can be pruned after a retention window:

```bash
php artisan nvl:seo:redirects:prune --days=30
```

Application adoption uses the neutral cursor-paginated `SeoImportSource`
contract and `ImportSeoProfilesAction`. Import records use `ownerAlias`, never
an HTTP-supplied model class. Model and path mapping belong to an
application-owned bridge. Normalize duplicate paths, preserve compatible
identifiers, and verify canonical, hreflang, redirect, and sitemap output
before cutover.

Run the read-only doctor first:

```bash
php artisan nvl:seo:doctor --strict --format=json
```

## Production checklist

- Run migrations and validate mutation DTOs.
- Keep management APIs disabled unless middleware and `SeoAuthorization` are configured.
- Use absolute HTTPS canonical/social URLs.
- Expose only public images.
- Never store raw HTML or JSON-LD script strings.
- Resolve duplicate paths before deployment.
- Verify reciprocal hreflang and `x-default`.
- Keep sitemap generation bounded and cached.
- Store generated sitemap XML on a durable private filesystem disk.
- Use an atomic-lock-capable cache store when sitemap caching is enabled.
- Configure production robots policy.
- Test rendered pages with search-engine validators.

## Quality

```bash
composer install
composer quality
```

The package gate runs Pint, PHPStan at maximum strictness, and isolated Testbench/Pest tests. The monorepo adds dependency analysis, Composer audit, integration tests, and Laravel 12/13 gates.

See [UPGRADING.md](UPGRADING.md), [SECURITY.md](SECURITY.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE).
