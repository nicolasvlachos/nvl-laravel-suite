---
name: backend-actions
description: 'DEPRECATED — content merged into `backend-architecture`. Load `backend-architecture` and open `references/actions.md` for the action doctrine. This skill no longer carries authoritative rules and should not be loaded directly; it exists only as a redirect for legacy references.'
metadata:
    author: giftcometrue
    version: '2.0-deprecated'
    deprecated: true
    superseded-by: backend-architecture
---

# Deprecated — Superseded by `backend-architecture`

This skill has been merged into the canonical `backend-architecture` skill.

- **Action quick rules** → `backend-architecture/SKILL.md` (Tier 2.2).
- **Full action doctrine** (one entrypoint, typed signatures, model-or-id resolution, transaction boundaries, post-commit side effects, DTO-based writes, refreshed-return rule, activity logging) → `backend-architecture/references/actions.md`.

**Action required:** Load `backend-architecture` for all backend PHP work; open `references/actions.md` when touching `Modules/**/app/Actions/**`. Do not load this skill. Citations in old code reviews should be migrated to point at `backend-architecture/references/actions.md`.
