# Workflow implementation report

Scope: Tasks W2-W6 from `docs/superpowers/plans/2026-09-16-tenancy-workflows.md`.

The user required implementation-first delivery. Tests, consumers, matrices, reviews, PHPStan, Pint, package contracts, package validation, and broad verification were explicitly deferred. A checked implementation item below means the code/test/documentation surface was authored; it does not claim that its deferred command passed.

## W2 — Forms ownership and public resolution

- [x] Authored canonical-model and public-flow coverage for foreign/preloaded forms, entries, submissions, analytics, origins, tokens, throttles, spam checks, callbacks, and identical tenant-local handles/idempotency keys.
- [x] Added canonical tenant ownership and compound identities across Forms roots and inherited records.
- [x] Bound public site/host resolution, OPTIONS, token verification, origin checks, availability, throttle, spam, and submission actions to the resolved tenant before form lookup.
- [x] Captured callback tenant identity and restored it before queued handling; replay remains tenant-local.
- [x] Added real registrar/adopter, docs, upgrading notes, changelog, skill guidance, package metadata, and adoption registration coverage.
- [ ] Focused Forms, public submission/idempotency/privacy, callback A→B→missing, and competing claim commands — **UNRUN (deferred by user)**.
- [x] Commit milestone: `00d9d41 feat(forms): bind public submissions and callbacks to tenant ownership`.

## W3 — Templates and catalog copy graph

- [x] Authored ownership and import coverage for templates, versions, assignments, renders, grants, stale revisions, revocation, retries, source deletion, and tenant isolation.
- [x] Added tenant-owned Template graph identities and render/output path isolation.
- [x] Added the Content-owned snapshot/copy seam so consumers do not write Content tables directly.
- [x] Added tenant-local grants and copies independent of the platform source, including absent asset grants and source deletion after successful copy.
- [x] Added `MediaCatalogImport` staging, checksum verification, promotion, root rollback, and exact cleanup semantics.
- [x] Added real registrar/adopter, docs, upgrading notes, changelog, skill guidance, package metadata, and adoption registration coverage.
- [ ] Focused template/catalog tests, real render workers, retry/failure/recovery, PDF asset copy, Redis/storage, and revocation-race commands — **UNRUN (deferred by user)**.
- [x] Commit milestones: `12ebbf1 feat(content): add tenant catalog snapshot copy seam` and `1be8894 feat(templates): import isolated tenant template graphs`.

## W4 — Comments graph and mentions

- [x] Authored ownership coverage for targets, parents/replies, reactions, attachments, revisions, tombstones, idempotency, concurrency, and lifecycle boundaries.
- [x] Added inherited tenant ownership, compound identities, canonical target resolution, tenant-qualified locks, and ownership-first reads/writes.
- [x] Added tenant-safe mention projections through a host/Auth adapter, without a Comments→Auth dependency or cross-tenant principal disclosure.
- [x] Added real registrar/adopter, docs, upgrading notes, changelog, skill guidance, package metadata, and adoption registration coverage.
- [ ] Focused Comments, mention/projection/idempotency/concurrency/lifecycle, and same-key race commands — **UNRUN (deferred by user)**.
- [x] Commit milestone: `9c31a39 feat(comments): isolate tenant discussion graphs and mentions`.

## W5 — Mail scheduling and provider lifecycle

- [x] Authored scheduled/queued/provider coverage for same aliases and recipients across tenants, fresh factories, foreign notifiables/schedules/tracking, failures, recovery, suspension, and ambiguous provider identities.
- [x] Added tenant ownership to scheduled messages, notifications, and events, with scoped claims, terminal updates, cancellation, recovery, reads, and tracking.
- [x] Persisted a scalar tenant envelope with scheduled work and restored it before factory lookup and message handling.
- [x] Changed factory resolution to fresh class-based construction for every operation.
- [x] Added tenant SMTP selection through U3 Settings while keeping provider/platform credentials deployment-managed and avoiding global Config mutation.
- [x] Added stored-identity provider callback location and TenantRunner restoration, including suspended-tenant denial.
- [x] Added real registrar/adopter, docs, upgrading notes, changelog, skill guidance, package metadata, and adoption registration coverage.
- [ ] Focused Mail/factory tests, scheduled/queued failure/SMTP/provider callback suites, and A→B→missing worker commands — **UNRUN (deferred by user)**.
- [x] Commit milestone: `09ad3ce feat(mail-notifications): retain tenant identity across delivery lifecycle`.

## W6 — retention, adoption, and workflow evidence

- [x] Authored adoption registration tests for Forms, Templates, Comments, and Mail plus the root workflow ownership graph and explicit worklist assertions.
- [x] Authored the serialized Activity purge envelope proof and updated existing direct job tests for the injected tenant boundary.
- [x] Partitioned Activity purge candidates, deletions, counts, locks, and jobs through `TenantBoundary`; platform commands use an explicit configured UUID worklist and one TenantRunner scope per tenant.
- [x] Partitioned Mail prune/anonymize candidates and mutations; scheduled workers, recovery, and retention commands use an explicit configured UUID worklist and one TenantRunner scope per tenant.
- [x] Added mail tenancy diagnostics for ownership schema and worklist readiness.
- [x] Kept deletion/recovery behavior package-owned, resumable and bounded; package cleanup does not delete global principals or enumerate tenant tables implicitly.
- [x] Updated production-consumer configuration, package-family dependency and analysis inventories, CI workflow composition, README/UPGRADING/CHANGELOG content, and package skills.
- [x] Preserved suspended-tenant denial via TenantRunner, exact persisted ownership recovery, disabled compatibility, and standalone package dependency declarations.
- [ ] Full public Form → callback → Template → Mail → Comment/Activity behavioral journey, suspension/deletion/revocation/export/legacy-queue runtime rehearsal — **UNRUN (deferred by user)**.
- [ ] Actual purge job execution, package regressions, Pint, PHPStan, contracts, package validation, database matrix, Redis/storage/SMTP/workers, races, cached boot/routes, Composer/archive installs, and independent review — **UNRUN (deferred by user)**.
- [ ] `tools/package-contracts.json` refresh — **UNRUN and intentionally deferred by user**.

## Deferred command inventory

No test, consumer, matrix, independent review, PHPStan, Pint, contract, package-validation, or broad verification command was executed during W2-W6 delivery. Only PHP syntax parsing and diff whitespace checks may be recorded after implementation as non-runtime hygiene.
