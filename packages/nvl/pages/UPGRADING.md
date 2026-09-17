# Upgrading NVL Pages

## Optional tenancy adoption

Install the nullable Page expansion and adopt dependencies before Pages. Map
existing Page roots to reviewed tenants in bounded batches; translations derive
from Page identity. Verify every parent/child and site/path invariant before
activating non-null ownership and composite constraints. Hold maintenance,
drain old publication jobs, invalidate old global sitemap artifacts, restart
workers, and only then admit tenant traffic. Never re-disable tenancy after the
final constraints have activated.

Replace public-site configuration fallbacks with a real `TenantSiteResolver`.
Route middleware must resolve it before bindings. Update dynamic resource
handlers to `TenantSafePageResourceHandler` and register their model with the
tenancy resource registry.

## To 1.0

This is the first stable contract.

- Persist stable resource aliases rather than handler class names.
- Store structural slugs on Pages and localized copy in `pages_i18n`.
- Move page sections into the Page model’s canonical `content` group through
  `HasContent` and the model-first Content Actions or facade.
- Attach discoverability data through SEO profiles and custom fields through Metafields.
- Supply the current `revision` through the typed update, move, delete, and restore DTOs.
- Use `PublicPageData` for public delivery and reserve `PageData` for authorized management state.
- Store arbitrary custom values through Metafields and structured discoverability values through SEO; Pages has no generic metadata column.
- Bind `PageRequestContextResolver` for multi-site public HTTP delivery; the default resolver uses one configured trusted site.
- Keep application-specific route models, queries, tenant rules, DTO projection, and dynamic sitemap chunking inside registered resource handlers.
- The default navigation endpoint is `/api/v1/pages/_navigation` and the default management prefix is `/api/v1/pages/_manage`; the leading underscore keeps both outside the valid page-slug grammar.

Run `php artisan nvl:pages:doctor --strict --format=json` before and after an application adoption.
