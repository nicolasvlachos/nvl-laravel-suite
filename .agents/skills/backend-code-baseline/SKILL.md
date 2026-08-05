---
name: backend-code-baseline
description: 'DEPRECATED — content merged into `backend-architecture`. Load `backend-architecture` instead. This skill no longer carries authoritative rules and should not be loaded directly; it exists only as a redirect for legacy references.'
metadata:
    author: giftcometrue
    version: '2.0-deprecated'
    deprecated: true
    superseded-by: backend-architecture
---

# Deprecated — Superseded by `backend-architecture`

This skill has been merged into the canonical `backend-architecture` skill.

- **Project-wide PHP 8.4 / Laravel 12 baseline rules** (strict types, import hygiene, layer ownership, translations, activity logging, dead code, service-locator ban) → `backend-architecture/SKILL.md` (Tier 1).
- **Inertia controller baseline** → `backend-architecture/SKILL.md` (Tier 2.1) + `backend-controllers` skill for full depth.

**Action required:** Load `backend-architecture` for all backend PHP work. Do not load this skill. Citations in old code reviews should be migrated to point at `backend-architecture/SKILL.md`.
