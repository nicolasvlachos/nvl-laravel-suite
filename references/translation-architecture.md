# Translation Architecture and Rollout

This monorepo uses `nvl/translatable` as the only runtime for model-backed content translations. Laravel language files remain responsible for interface strings, validation messages, command output, and other application-owned copy.

## Ownership matrix

| Package | Canonical owner data | Translated fields | Translation table |
|---|---|---|---|
| Translatable | locale configuration and resolution policy | package-agnostic | consumer-owned `<owner_table>_i18n` |
| Metafields | namespace, key, type, assignments, raw/reference value | definition title/description/hint/default/properties; eligible owner values | `metafields_definitions_i18n`, `metafields_i18n` |
| Media | filename, digest, disk, path, MIME, visibility, tags | title, alt, caption, description | `px_media_i18n` |
| Taxonomy | taxonomy, parent, slug, position, meta | name, description | `terms_i18n` |
| Forms | handle, behavior, availability, security, redirects, counters | name, description, submit/success copy, arbitrary nested content | `forms_i18n` |
| SEO | owner, lifecycle, indexability, sitemap policy | route, title, description, canonical URL, social copy, structured data | `seo_profiles_i18n` |

## Invariants

1. Every model-localized table is named `<owner_table>_i18n` and has one row per owner and locale, enforced by a unique constraint.
2. Locale columns accept up to 35 characters.
3. Supported locales come from `LocaleRegistry`; payload keys are validated before persistence.
4. Content locale comes from request-scoped `ContentLocale`, not hidden global state.
5. Resolution is requested locale followed by configured fallbacks.
6. Fallback happens per field on `null`; empty strings are intentional.
7. Public/list queries eager-load only the resolution chain.
8. Administrative editors load all translation rows.
9. Writes occur through `TranslationWriter` inside Action-owned transactions.
10. Patch preserves omitted locales; replace removes them.
11. Canonical slugs, handles, hashes, namespaces, keys, and storage paths are never translated.
12. Domain events that expose committed mutations dispatch after commit.

## Deployment sequence

1. Deploy database migrations.
2. Publish and review `translatable.php`.
3. Configure the full supported/fallback locale set.
4. Deploy dual-read/dual-write compatible application code.
5. Run dry-run backfills for Taxonomy and Forms.
6. Run backfills without `--dry-run`.
7. Compare owner and translation counts per locale.
8. Exercise representative public reads in every supported locale.
9. Monitor invalid-locale errors, missing translations, query counts, and backfill failures.
10. Remove legacy JSON/base-column compatibility only in a later breaking release after all consumers stop using it.

## Release gates

- All package and cross-package tests pass.
- Pint reports no formatting changes.
- Composer manifests validate.
- Package discovery registers every provider.
- A clean application can migrate from zero.
- An existing database can migrate forward without data loss.
- Backfills are chunked, dry-runnable, idempotent, and observable.
- Public reads issue bounded queries with no translation N+1.
- Patch, replace, clear, fallback, and intentional-empty behavior are tested.
- READMEs match public classes, commands, tags, routes, and payloads.
- Published agent skills validate and contain no historical package names or deprecated architecture.
