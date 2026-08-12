# Changelog

All notable changes to `nvl/pages` are documented here.

## [Unreleased]

## [1.0.2] - 2026-08-12

- Added UUID page hierarchies with a strict four-level ceiling.
- Added dedicated `pages_i18n` localized copy through Translatable.
- Added Content, SEO, Metafields, and sitemap registrations.
- Added validated dynamic resource handlers with consumer-owned Eloquent queries.
- Added optimistic Actions, after-commit events, public resolution, and disabled-by-default management routes.
- Added stable per-site tree locking, transition-aware lifecycle authorization, and one subtree-aware mutation event.
- Split locale-resolved public page delivery from the complete management projection.
- Added trusted public site/locale context, localized navigation, draft preview, and soft-delete restoration.
- Added site-scoped management listing and typed delete/restore mutation contracts.
- Added resource path-prefix prefiltering, route-rule parity checks, and stable 404/409/422 failures.
- Removed arbitrary page metadata storage in favor of SEO and Metafields ownership.
- Added the read-only doctor command and package distribution assets.
- Expanded doctor diagnostics to cover the lock table, parent/path drift, cycles, orphans, lifecycle state, resource aliases, middleware, and management authorization.
- Documented the `pages-migrations` and `pages-skills` publish tags in the installation flow.
- Kept default navigation and management transport paths disjoint from valid page slugs.
- Scoped sitemap delegation to matching SEO profiles that can emit an entry.
- Bounded management translation copy and positions for portable persistence.
- Excluded development-only files from release archives.

## [1.0.0] - 2026-08-08
