# Changelog

All notable changes to `nvl/seo` are documented here.

## [Unreleased]

## [1.0.7] - 2026-08-22

### Changed

- Aligned the documented runtime requirement with the PHP 8.4+ package
  baseline.

## [1.0.5] - 2026-08-12

### Changed

- Released unchanged under the suite's shared version.

## [1.0.2] - 2026-08-12

- Exclude test and static-analysis infrastructure from production release archives.
- Expand consumer, management API, diagnostics, structured-data, and failure-boundary coverage to enforce the package CI thresholds.

- Preserved non-default scopes across sitemap index/chunk routes behind an
  explicit public scope allowlist.
- Added one-shot sitemap artifact self-healing and non-throwing, reported
  after-commit cache invalidation so durable profile writes and events remain
  truthful during cache outages.
- Excluded valid external canonicals from the built-in site sitemap while
  retaining strict custom-source location enforcement.
- Hardened redirect loop detection across queries, fragments, and same-site
  absolute hops, and preserved omitted redirect metadata on updates.
- Accepted integer management owner identifiers, tightened sitemap priority
  lexical validation, and made runtime-only owner aliases advisory in doctor
  output until management is enabled.
- Canonicalized unreserved path percent encoding, rejected malformed or nested
  encoded separators, required non-empty sitemap registry keys, and enforced
  importer-requested page bounds.
- Rejected enabled management routes without middleware and invalid doctor
  output formats instead of silently accepting unsafe configuration.
- Rejected unknown management and localized mutation fields instead of
  silently discarding misspelled input.

## [1.0.0] - 2026-08-08

- Added localized SEO profiles, owner and route resolution, canonical and hreflang output.
- Added Open Graph, Twitter cards, bounded JSON-LD, robots, sitemap indexes, and cache invalidation.
- Added authorized management Actions, revisions, conflict detection, redirects, and neutral paginated import.
- Added central `seo.profiles` Translatable registration.
- Added resource-aware structured-data providers, stable graph identities,
  deterministic composition, persisted-node precedence, and bounded validation.
- Added stable owner aliases, complete authorization context, independent
  public/management routes, mandatory API revisions, stable domain-error
  envelopes, and bounded pagination.
- Added transport-independent mutation validation, strict canonical/path/redirect
  URL semantics, database constraint mapping, and fail-loud migrations.
- Added localized-first redirect fallback, retention pruning, expiry-aware chain
  handling, and portable query indexes.
- Added byte-bounded filesystem sitemap artifacts, atomic cache locks,
  manifest-last publication, versioned invalidation, origin/path-scope checks,
  site-isolated namespaces, ETags, and warm/clear commands.
- Added strict bounded robots generation, configurable social defaults,
  repeated typed Open Graph alternate locales, and expanded doctor checks for
  schema, indexes, bindings, routes, authorization, artifact storage, and cache
  capabilities.
- Marked centralized SEO translation mutation as `DomainActionOnly` so package
  lifecycle invariants cannot be bypassed.
