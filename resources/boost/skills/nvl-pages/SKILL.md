---
name: nvl-pages
description: Implement and review hierarchical pages, Content composition, dynamic resource handlers, SEO, Metafields, translations, and sitemap integration with nvl/pages.
---

# NVL Pages

Use this skill when application work creates, resolves, translates, composes, or extends Pages.

## Required approach

1. Use `CreatePageAction`, `UpdatePageAction`, `MovePageAction`, `DeletePageAction`, and `RestorePageAction`; do not write package tables directly.
2. Keep the page tree at or below the configured four-level maximum.
3. Put localized title, navigation label, and summary values in Translatable locale rows.
4. Put page sections in the Page model’s `content` Content group through
   `HasContent`; consume the injected `Nvl\Content\Content` application
   surface and adapt the actor with `PageActorData::contentActor()`. Keep
   discoverability data in SEO and custom fields in Metafields.
5. Implement `PageResourceHandler` for dynamic routes. Its query must include every publication, tenancy, policy, and eager-load condition.
6. Return only a sanitized `PageResourceData`; never serialize the resolved Eloquent model.
7. Stream absolute `SitemapEntry` values from a handler in bounded chunks when dynamic resources belong in a sitemap.
8. Supply exact revisions through the update, move, delete, and restore DTOs; handle stale and uniqueness conflicts.
9. Use `PublicPageData` for public output and `PageData` only for authorized management or preview output.
10. Resolve public site and locale through `PageRequestContextResolver`; never trust a caller-supplied site query directly.
11. Use `GetNavigationAction` for localized navigation and `PreviewPageAction` for authorized non-public rendering.
12. Keep public and management routes disabled unless the application explicitly secures and enables them.
13. Run `nvl:pages:doctor --strict` after configuration or schema changes.

Use `ResolvePageAction` for headless delivery. Its `ResolvedPageData` combines a localized redacted Page projection, Content, SEO, and the optional dynamic resource projection.
