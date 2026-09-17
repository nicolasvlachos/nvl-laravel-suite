# Upgrading NVL SEO

## Optional tenancy adoption

Deploy nullable tenant columns before the tenancy flag is enabled. Under
maintenance, provide reviewed redirect/profile mappings, backfill and recompute
tenant-inclusive source hashes, verify canonical owner and translation
equality, then activate tenant-leading uniqueness and non-null constraints.
Delete old global sitemap namespaces only after the captured version cutover.
Drain old jobs and restart workers so no retained source or cache object can
write with pre-adoption identity. The flag is not reversible after activation.

Outside HTTP, install a host-verified `TenantSiteContext` while inside
`TenantRunner`. Configured sitemap sources must implement
`TenantSafeSitemapSource`; object instances are rejected in adopted mode.

## Unreleased hardening

Run the new `create_seo_redirect_locks_table` migration before redirect writes.
It adds a stable database mutex that keeps loop validation and graph updates
serialized through the enclosing transaction. Installations maintaining copied
migrations should copy this new migration through their existing migration
ownership workflow; do not enable both vendor and copied migration sources.

Owner packages may now register exclusive SEO sitemap ownership. Pages uses
this to enforce its publication state and SEO exclusions together. Other
profile owners retain the existing standalone sitemap behavior.

## Upgrading to 1.0

Version 1.0 is headless, uses dedicated translation rows, and has no application-specific importer.

1. Set `seo.migrations.enabled=false` for an existing adopted schema. Clean
   package migrations now fail loudly if their tables already exist instead of
   silently recording a migration that rollback could later destroy.
2. Run `php artisan nvl:seo:doctor --strict --format=json`.
3. Implement `SeoImportSource` in an application-owned bridge and import pages in stable batches.
4. Register unique owner aliases and bind `SeoAuthorization`. HTTP/import
   payloads now use `ownerAlias`; raw `ownerType` inputs were removed.
5. Supply revision `0` for profile/redirect creates and exact positive
   revisions for subsequent management writes.
6. Resolve path conflicts before enabling profiles.
7. Enable management, sitemap, robots, and redirect routes independently.
8. Choose `seo.structured_data.mode`; register resource providers for generated
   schemas and retain persisted nodes only for editor-owned exceptions.
9. Replace `seo.management.prefix` with `seo.management.path`, configure
   `seo.management.name`, and configure `seo.routes.name` for public routes.
10. Use an atomic-lock-capable cache store when sitemap caching is enabled,
    configure a durable private sitemap artifact disk/directory, and set both
    URL and uncompressed-byte limits.
11. Run the query-index hardening migration. It replaces write-heavy legacy
    indexes with the composite profile sitemap scan index expected by the
    doctor.
12. Treat the `seo.profiles` centralized translation resource as discoverable
    and reportable but read-only. All mutations must use
    `SyncSeoProfileAction`.

Verify localized paths, canonical and hreflang output, redirects, sitemap artifacts, row counts, and rollback before removing old SEO code.
