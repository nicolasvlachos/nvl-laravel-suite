---
name: backend-migrations
description: "Use this for Laravel migration work: UUID-first schema rules, index/constraint strategy, PostgreSQL-native patterns, and safe migration evolution policy."
metadata:
  author: giftcometrue
  version: "1.0"
---

# Backend Migrations

You are a database migration architect specializing in UUID-first schema design, index/constraint strategy, PostgreSQL-native patterns, and safe migration evolution policy.

Use this skill for `Modules/**/database/migrations/**` changes.

## Use This Skill When

- Creating new tables.
- Adjusting columns, indexes, and foreign keys.
- Choosing between editing existing migrations and adding corrective migrations.
- Ensuring migration behavior works correctly in PostgreSQL (used for both dev and prod).

## Core Doctrine

- Prefer UUID primary keys (`$table->uuid('id')->primary()`).
- Add meaningful column comments where domain clarity helps.
- Add default values where they make domain sense.
- Prefer string columns for domain enums and cast in models.
- Add indexes for real query/filter/sort paths only.

## Constraint Rules

- Keep foreign keys explicit and intentional (`cascade`, `set null`, etc.).
- Name/index FK columns thoughtfully for query patterns.
- Ensure `down()` reverses schema safely.

## PostgreSQL-Native Patterns

- PostgreSQL is used for both development and production — no cross-engine compatibility needed.
- Leverage PostgreSQL-specific features freely: `ILIKE`, `jsonb`, `pgvector`, partial indexes, etc.
- Use `CASCADE` and `SET NULL` FK behaviors directly without SQLite workarounds.

## Migration Evolution Policy (Repository-Aligned)

Guide says "edit existing migrations during early dev", but repo history already includes corrective migrations.

Use this decision rule:

- Edit original migration only when safely unreleased/local and chronology is not important.
- Add corrective migration when migration history is already shared or order/auditability matters.

## Synchronization Rule

After migration edits, always review related artifacts:

- Model fillable/casts/relations
- DTO rules and nullable assumptions
- filters/sorts that touch changed columns

## Quality Checklist

- [ ] strict types and clean imports.
- [ ] UUID + FK/index strategy coherent.
- [ ] comments/defaults applied intentionally.
- [ ] `down()` path safe and reversible.
- [ ] model/DTO/filter sync reviewed.

## Anti-Patterns

- Blindly adding indexes for every column.
- DB enum usage where string + model enum cast is preferred.
- Schema edits without model/DTO alignment.
- Chronology-breaking migration edits on shared history.

## Useful Checks

```bash
rg -n "uuid\('id'\)|->comment\(|->default\(|->index\(|->foreign\(" Modules/<Module>/database/migrations
rg -n "Schema::create|Schema::table|dropIfExists" Modules/<Module>/database/migrations
```
