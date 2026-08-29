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
11. Use `FindPageByKeyAction` for exact site-scoped management lookup and
    `CheckPageKeyAvailabilityAction` for validation against the actual globally
    unique Page key. A foreign-site conflict is unavailable but does not expose
    its ID; `exceptId` only excludes the same-site row.
12. Use `ListPageOptionsAction` for localized management selectors. It searches
    key, path, title, and navigation label, returns no results for a one-character
    search, resolves Translatable fallback, orders by path/ID, and never returns
    more than the configured or absolute 100-row limit.
13. Use `ListPublicChildPagesAction` with trusted `PageRequestContextData` for a
    one-level public listing. The parent and children must be public in the same
    site; results are `PublicPageData` and capped at 100. The default uses
    sibling order. Pass an allowlisted `PageKind` and
    `PublicChildPageOrder::Newest` when a feed must filter and order by effective
    publication time before applying its limit.
    Package-built projections populate the optional `publishedAt` field from the
    publication timestamp or persisted creation fallback for public cards.
14. Use `GetNavigationAction` for localized navigation and `PreviewPageAction` for authorized non-public rendering.
15. Use `ListPageEditorSummariesAction` for bounded management indexes. It
    authorizes the site-level list before SQL and batches Page, Content
    placement, and SEO projections without repeating catalogs per row. SEO
    authorizes every owner before its batched query, and configured/requested
    page sizes cannot exceed the absolute 100-owner ceiling.
16. Use `GetPageEditorBootstrapAction` for one complete editor payload. It
    composes authorized Page, Content, SEO, and Metafields reads plus Page
    kinds, statuses, resource aliases, and maximum depth; do not rebuild that
    graph in a controller.
17. Use `GetPagePublicationProjectionAction` only for a currently public static
    Page known by ID. Use `ResolvePageAction` for paths and resource Pages, and
    `PreviewPageAction` for management preview.
18. Keep public and management routes disabled unless the application explicitly secures and enables them.
19. Run `nvl:pages:doctor --strict` after configuration or schema changes.

Use `ResolvePageAction` for headless delivery. Its `ResolvedPageData` combines a localized redacted Page projection, Content, SEO, and the optional dynamic resource projection.
