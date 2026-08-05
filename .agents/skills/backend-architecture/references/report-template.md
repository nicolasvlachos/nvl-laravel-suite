# Audit Report Template

Use this template for Phase 5 report production.

```markdown
# Module Audit Report: {ModuleName}

Date: {YYYY-MM-DD}
Auditor: backend-architecture v1.0

## Executive Summary

| Metric | Value |
|--------|-------|
| Module | {ModuleName} |
| Backend files | {count} |
| Frontend files | {count} |
| Recent commits analyzed | {count} |
| Total findings | {count} |
| CRITICAL | {count} |
| HIGH | {count} |
| MODERATE | {count} |
| LOW | {count} |
| Overall health | HEALTHY / NEEDS ATTENTION / CRITICAL ISSUES |

**Git Context:** {1-sentence summary of last 2-3 commits — what was the recent intent?}

## Phase 1: Strategic Findings

### 1.1 Business Logic & Intent
{Business logic stress test results}
{Intent drift findings or "No drift detected — module aligns with documented purpose"}

### 1.2 Architectural Chain of Command
{Layer violation findings or "Clean chain of command — no cross-layer leakage"}

### 1.3 Models & Builder Specialization
{Builder extraction candidates or "Models are lean Data Maps with appropriate query composition"}

### 1.4 Orchestration & Side Effects
{Side-effect chain map}
{Deep Nesting findings}
{or "Orchestration is clean and predictable — all side effects are explicit"}

### 1.5 Cross-Module Boundaries
{Dependency classification table}
{Parity Problems or "Module boundaries are well-defined with stable contracts"}

## Phase 2: Pattern Compliance

### Backend ({N} findings)
{findings grouped by scan category}

### Frontend ({N} findings)
{findings grouped by scan category}

### Cross-Stack ({N} findings)
{parity check results}

## Phase 3: Spatie Data & Full-Stack Parity
{DTO separation assessment}
{Type parity results}
{Display State evaluation score (if applicable)}

## Phase 4: Quality Gates

| Gate | Result |
|------|--------|
| PHPStan level 4 | {N} errors |
| PHPStan level 5 | {N} errors (or "Deferred — level 4 not clean") |
| Module tests | {pass/fail} ({N} tests) |
| Test coverage | {actions covered}/{total actions} |
| TypeScript | {N} errors |
| Pint formatting | {clean/N issues} |

## All Findings (sorted by severity)

### CRITICAL

{MA-001 through MA-NNN}

### HIGH

{MA-NNN through MA-NNN}

### MODERATE

{MA-NNN through MA-NNN}

### LOW

{MA-NNN through MA-NNN}

## Fix Plan

### Execution Order
1. CRITICAL — {list}
2. HIGH/STRATEGIC — {list}
3. HIGH/COMPLIANCE — {list}
4. MODERATE — {list}
5. LOW — {list}

### Fix Groups
{Architectural fixes bundle}
{Translation fixes bundle (atomic: EN + BG + TS + FE)}
{DTO fixes bundle (PHP + typescript:transform + FE)}
{Cross-module fixes (coordinate with other module)}

### Verification Checklist
- [ ] PHPStan level 4 — 0 errors
- [ ] Module tests — all pass
- [ ] EN/BG translation parity
- [ ] Pint formatting — clean
- [ ] TypeScript — 0 new errors
- [ ] typescript:transform — if DTOs changed
- [ ] No new cross-layer violations
- [ ] No new untyped cross-module dependencies
- [ ] Side-effect chains documented
```

## Finding Format

Each finding follows this exact structure:

```
ID: MA-{NNN}
Phase: STRATEGIC | COMPLIANCE | PARITY | QUALITY
Title: {concise description}
Severity: CRITICAL | HIGH | MODERATE | LOW
Category: Intent Drift | Impossible State | Layer Violation | Boundary Leak |
          Side-Effect Chain | Parity Problem | Deep Nesting | Dead Code |
          Pattern Violation | Missing Test | God Object | Leaky Abstraction
File: {path}:{line}
What: {exact violation with evidence — quote the offending code}
Should be: {correct pattern — show what the code should look like}
Fix: {specific instruction — or "Needs clarification: [reason]" if pattern might be deliberate}
```

## Severity Decision Guide

| Signal | Severity |
|--------|----------|
| Data can be corrupted or lost | CRITICAL |
| Security/auth check missing | CRITICAL |
| Entity can reach impossible state | CRITICAL |
| Foreign module table written directly | CRITICAL |
| User sees wrong data/translation | HIGH |
| Layer boundary crossed (controller does DB work) | HIGH |
| Cross-module contract is unstable | HIGH |
| Side-effect chain is unpredictable | HIGH |
| Builder not extracted but should be | MODERATE |
| Service has generic name | MODERATE |
| Import path is legacy | MODERATE |
| Dead code exists but causes no harm | LOW |
| Minor naming inconsistency | LOW |
| Looks non-standard but tests cover it | QUESTION |
| Recent commit introduced it deliberately | QUESTION |
| Domain might justify the deviation | QUESTION |

## Health Assessment Criteria

| Health | Criteria |
|--------|----------|
| **HEALTHY** | 0 CRITICAL, 0-2 HIGH, module aligns with intent, clean chain of command |
| **NEEDS ATTENTION** | 0 CRITICAL, 3+ HIGH or significant pattern debt, some drift |
| **CRITICAL ISSUES** | 1+ CRITICAL, data integrity at risk, or major architectural violations |

**QUESTION-severity findings are NOT counted toward health assessment.** They are presented separately as "Items for Discussion" requiring human judgment.
