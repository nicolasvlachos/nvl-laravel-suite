---
name: nvl-seo
description: Implement, integrate, test, or review nvl/seo in Laravel 12–13. Use for polymorphic SEO profiles, localized metadata through nvl/translatable, canonical and hreflang URLs, Open Graph and Twitter cards, media-backed social images, safe JSON-LD, robots directives, sitemap sources/routes, path uniqueness, Blade head rendering, or SEO package architecture.
---

# NVL SEO

Treat SEO as a resolved discoverability contract, not a collection of arbitrary meta-tag columns. Keep storage, locale fallback, URL identity, rendering, and crawl discovery consistent.

## Attach a profile

Use `SyncSeoProfileAction` with a persisted Eloquent owner and `SeoProfilePayload`. Pass translations as a locale-keyed map. Use patch mode by default; require explicit replace mode before deleting omitted locale rows.

Add `HasSeo` to an owner only for convenient relations. Never write `seo_profiles` or `seo_profiles_i18n` directly.

Polymorphic owners cannot use database-level cascading foreign keys. Keep owner
deletion policy in the host application's Action: remove profiles through
`DeleteSeoProfileAction` before hard deletion, or deliberately retain/archive
them for soft-deleted owners. Do not add a hidden model observer.

## Localize content

- Store titles, descriptions, paths, social overrides, image copy, and structured data in dedicated translation rows.
- Resolve them through `nvl/translatable`; do not use insertion order or Laravel's UI locale as an implicit content fallback.
- Keep profile scope, crawl directives, and sitemap policy on the owner row.
- Preserve intentional empty translated values; fall back only under `nvl/translatable` semantics.
- Treat the central `seo.profiles` resource as read-only discovery/reporting.
  Its domain-action-only policy protects revision, conflict, event, and sitemap
  invariants; write through `SyncSeoProfileAction`.

## Keep URLs consistent

- Normalize paths through `SeoPath`.
- Treat percent-encoded unreserved characters as their canonical literal form;
  reject malformed, nested, or encoded-separator path identities.
- Rely on the database uniqueness key for site scope + locale + normalized path.
- Render absolute canonical URLs.
- Include self-referential canonicals and reciprocal localized alternates.
- Use `SeoRouteResolver` when resolving content from an incoming localized path.

## Render metadata

Resolve with `SeoMetadataResolver`; render with `SeoHeadRenderer`, `SeoManager`, or `@seo($owner)`.

- Escape every attribute and title.
- Encode JSON-LD with all HTML-sensitive JSON flags.
- Do not accept pre-rendered tags or raw script strings.
- Build one-off schema.org objects with `StructuredDataBuilder`.
- Use `StructuredDataProvider` and `StructuredDataRegistry` for repeatable,
  resource-aware nodes; providers receive `StructuredDataContextData`.
- Return `StructuredDataNodeData` with stable `@id` values so nodes connect in
  one deterministic graph.
- Accept schema.org extension types as safe strings; do not treat
  `StructuredDataType` as a closed allowlist.
- Keep generated facts accurate to visible resource content. Treat structural
  validation and search-engine rich-result eligibility as separate concerns.
- Keep Open Graph/Twitter overrides optional so primary metadata remains the fallback.

## Integrate media

Direct URLs work through `DirectSeoImageResolver`. For `nvl/media`, bind `SeoImageResolver` in the host application and resolve `SeoImageContext::reference` through `MediaAssetService`; the context already contains field-level fallback values. Do not add a hard media dependency to this package or assemble media storage paths in SEO code.

## Publish discovery files

Enable package routes only when the application does not already own `/sitemap.xml` or `/robots.txt`.

- Register additional domain sources through `SitemapSource`.
- Yield entries lazily; do not load an entire catalog into memory.
- Keep chunks within configured URL and uncompressed-byte limits and expose a
  sitemap index when multiple chunks exist.
- Store completed XML artifacts on a durable private filesystem disk. Keep only
  small manifests and versions in the cache.
- Use an atomic-lock-capable cache store, unique source keys, completed
  manifests, ETags, and after-commit version invalidation.
- List every public non-default scope in `seo.routes.sitemap_scopes`; never
  accept arbitrary public scope keys that can create unbounded cache namespaces.
- Preserve scope on sitemap-index chunk links and rebuild once when a completed
  manifest points to a missing filesystem artifact.
- Enforce fragment-free, same-origin URL identity and sitemap path scope.
- Omit valid external canonicals from the built-in site sitemap; keep strict
  rejection for invalid custom-source locations.
- Emit `lastmod` only when the source owns an accurate content-modification
  timestamp.
- Use robots meta `noindex` to prevent indexing; do not treat `robots.txt` disallow as an indexing control.
- Invalidate sitemap cache only after committed profile mutations.

## Manage and adopt

- Keep the API disabled by default and configure both
  `seo.management.path` and `seo.management.name`.
- Register stable aliases in `seo.owners`; never accept model class names at
  HTTP/import boundaries.
- Normalize both integer and string owner identifiers into the package's stable
  string storage boundary.
- Authorize with the complete `SeoAuthorizationContext`, including source and
  target owners during duplicate operations.
- Use revision `0` for race-safe creates and exact positive revisions for
  updates, archive/restore, and delete.
- Use `ownerAlias` in `SeoImportRecordData`.
- Preserve safe redirect query strings/fragments and reject network-path or
  non-HTTP external targets.
- Detect redirect loops through decorated and same-site absolute targets, and
  preserve omitted metadata during redirect updates.
- Prefer exact-locale redirects, then locale-neutral fallbacks. Prune retained
  soft-deleted redirects with `nvl:seo:redirects:prune`.

## Verify

Test management Actions, exact locale and fallback reads, patch/replace writes,
expected revisions, path normalization and races, scope isolation, canonical
and hreflang parity, social-image fallbacks, redirects and loops, XSS-safe
output, provider priority and resource matching, graph identity/merge rules,
JSON-LD limits, sitemap indexes, cache invalidation, authorization, query
counts, and cascade deletion. Run `nvl:seo:doctor --strict --format=json`, the
package Pest suite, Pint, PHPStan at maximum strictness, dependency audit, and
Laravel 12/13 gates.
