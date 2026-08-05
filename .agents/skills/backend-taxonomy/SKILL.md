---
name: backend-taxonomy
description: Use when implementing, querying, translating, attaching, moving, merging, pruning, or reviewing nvl/taxonomy vocabularies and hierarchical terms, including adjacency trees, canonical slugs, and localized term copy.
---

# Backend Taxonomy

Treat taxonomy, parent, slug, position, and owner attachments as structural data. Treat name and description as translated display copy.

## Create and update terms

- Use `MutateTermPayload` with `CreateTermAction` or `UpdateTermAction`.
- Keep slugs canonical and nonlocalized.
- Persist name and description through dedicated `term_translations`.
- Use patch semantics by default and replacement only when explicit.
- Preserve legacy JSON only during the compatibility/backfill window.
- Enforce the unique taxonomy/parent/slug boundary.

## Query trees

- Use the adjacency-list `parent` and ordered `children` relationships.
- Use the category tree service for recursive public payloads.
- Eager-load resolved translations at every requested tree depth.
- Use `displayName($locale)` and `displayDescription($locale)`.
- Avoid per-node lazy queries and arbitrary first-translation fallbacks.

## Attach and maintain

- Register vocabularies through `TaxonomyRegistry`.
- Use package attachment APIs/traits instead of raw pivot mutation.
- Preserve taxonomy and pivot position metadata.
- Use `taxonomy:merge`, `taxonomy:prune-orphans`, and `taxonomy:rebuild-tree` for maintenance.
- Run `taxonomy:backfill-translations --dry-run` before migrating legacy name JSON, then run it without `--dry-run`.

## Verify

Test root and child uniqueness, canonical slug stability across locales, deterministic order, ancestor/descendant traversal, attachment isolation, localized tree payloads, field fallback, patch/replace writes, backfill idempotency, dry-run safety, chunking, and deletion behavior.
