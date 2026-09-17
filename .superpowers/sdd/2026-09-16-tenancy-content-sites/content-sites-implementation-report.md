# Content/Sites C1-C6 implementation report

Date: 2026-09-17

Scope: implementation-first delivery from clean tenancy HEAD `9fed904`.
No readiness claim is made. Every command checkbox in the source plan is
deferred and recorded as **UNRUN** by explicit instruction.

## C1 — Content ownership and platform definitions

- [x] Authored the adopted two-tenant Action fixture using one platform-synced
  `hero` definition and identical `site/default` block keys. Added denial for
  unresolved context and kept ownership fields out of client payloads.
- [ ] **UNRUN:** focused Content expected-red test.
- [x] Implemented platform `content.definitions`, tenant block roots, inherited
  translations/revisions/placements, registrar/adopter, tenant-leading schema,
  Action/query guards, explicit platform sync, and Doctor checks.
- [ ] **UNRUN:** `TenantContentTest.php`, definition migration and boundary
  contract commands.
- [x] Milestone committed as `1c9707d` (coherent C1/C2 equivalent).

## C2 — Content composition, snapshots, and owner lifecycle

- [x] Authored public-API composition coverage for canonical owner/block
  placement, format-2 capture, foreign snapshot denial, and tenant identity.
  Cross-package publication coverage injects a foreign Media override.
- [ ] **UNRUN:** composition expected-red command.
- [x] Implemented canonical owner/block/placement reload, tenant-scoped locks,
  inherited tree equality, Media/reference fail-closed resolution, owner
  lifecycle cleanup, tenant-inclusive format-2 hash, server-only TypeScript
  tenant field, and explicit `Content::adoptSnapshot()` legacy conversion.
- [ ] **UNRUN:** Content publication/Media/snapshot regressions and PostgreSQL
  competing placement/deletion commands.
- [x] Milestone committed as `1c9707d`.

## C3 — Page trees, exact identity, and handler ownership

- [x] Authored two-tenant Page Action cases with identical key/site/path and a
  foreign-parent denial.
- [ ] **UNRUN:** `TenantPagesTest.php` expected-red command.
- [x] Implemented tenant-leading key/site/path identity, root/translation and
  parent constraints, tenant/site tree locks, canonical subtree boundaries,
  handler query scoping before fetch, exact returned-model checks,
  `TenantSafePageResourceHandler`, and 100-owner editor boundaries.
- [ ] **UNRUN:** Page tree/options/key/editor/HTTP and PostgreSQL same-slug race
  commands.
- [x] Milestone committed as `fe4a62b` (coherent C3/C4 equivalent).

## C4 — One verified public tenant/site context

- [x] Authored real A/B host resolver, public Page route journeys, unknown-host
  denial, sequential same-worker requests, and shared Page/SEO identity cases.
- [ ] **UNRUN:** Page HTTP/SEO expected-red command.
- [x] Implemented `ResolvePublicTenant` ordering, server-only
  `PageRequestContextData::tenantSite`, active-tenant equality, verified site
  scope, canonical-origin Page/SEO URLs, and outside-HTTP fixture adapters
  without Config mutation. Real SEO schema/registrar/adopter was pulled forward;
  no fake marker exists.
- [ ] **UNRUN:** A/B public journeys, reused-worker, route-cache and config-cache
  runtime commands.
- [x] Milestone committed as `fe4a62b`.

## C5 — SEO ownership, redirects, artifacts, and invalidation

- [x] Authored identical A/B profile/path/redirect cases, tenant-inclusive
  source hashes, locale-neutral local resolution, foreign canonical owner
  denial, and independent cache identities.
- [ ] **UNRUN:** `TenantSeoTest.php` expected-red command.
- [x] Implemented SEO root/inherited schema, reviewed redirect/profile adoption,
  tenant graph locks/hashes, canonical owner hydration, verified `SeoScope`,
  fresh class-based `TenantSafeSitemapSource` resolution, resource declaration
  validation, `SitemapCacheIdentity`, connection/tenant/site/origin/version
  namespaces, and captured after-commit invalidation.
- [ ] **UNRUN:** SEO hardening/consumer/sitemap, Page sitemap, Redis simultaneous
  build/invalidation, and PostgreSQL redirect race commands.
- [x] Milestone committed as `78a6fa7`.

## C6 — Adoption and complete publication graph

- [x] Authored package-local coordinator adoption cases and the root Page
  publication case using real Actions/files for Content image override,
  Metafields, locales, SEO redirect, sitemap, and foreign Media rejection.
  Added resumable adapter checkpoints and explicit legacy snapshot conversion
  API surfaces; corruption remains bounded in verification reports.
- [ ] **UNRUN:** focused integration and adoption test commands.
- [x] Implemented bounded mappings/checkpoints, constraint replacement ordering,
  tenant hash/artifact cutover, immutable captured provenance, schema-owner
  checks, maintenance/adoption runbooks, drain/restart guidance, and
  post-activation no-disable policy.
- [ ] **UNRUN:** all Content/Pages/SEO suites; Pint; PHPStan; package contracts;
  DTO/TypeScript generation/contracts; SQLite/PostgreSQL/MySQL/MariaDB matrices;
  Redis/filesystem/concurrency; sequential worker; archive and clean consumer.
- [x] Implemented the final proof surfaces: package fixtures/tests, catalog
  dependencies, canonical/package skills, README/UPGRADING/CHANGELOG, adoption
  matrix/readiness notes, CI gates, and sealed no-dev independent consumer.
  `tools/package-contracts.json` was intentionally not refreshed.

## Deferred commands — all UNRUN

The following command families were deliberately not invoked: `vendor/bin/pest`,
`php artisan test`, package consumer scripts, database matrices, Redis/filesystem
fixtures, concurrency workers, `vendor/bin/pint`, PHPStan/Larastan, Composer
package validation, contract checks/update, dependency audit, TypeScript/DTO
generation/checks, archive creation/inspection, independent consumer execution,
and external/independent review. Only PHP parser checks and Git diff hygiene are
permitted before the final commit; their results are not release readiness.

## Narrow integration note

Pages now registers its SEO and Metafield owner declarations through their
package registries instead of mutating global configuration. Metafields gained
only the corresponding in-memory registrar method/provider singleton. No Auth,
Media, Taxonomy, or unrelated package behavior was changed.
