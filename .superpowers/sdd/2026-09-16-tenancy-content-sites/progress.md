# SDD ledger — plan: docs/superpowers/plans/2026-09-16-tenancy-content-sites.md

Implementation complete through C1-C6 surfaces on the isolated tenancy branch.
Per the implementation-first execution instruction, runtime verification remains
explicitly deferred: no Pest suite, consumer runner, database/cache matrix,
PHPStan, Pint, package validation, contract check, or independent review was
run. Frozen contracts and the rulings below remained authoritative.

## Preflight: task internal agreement

| Task | Checked | Finding / resolution |
|---|---|---|
| C1 | Catalog platform kind vs content tenant roots, migration/registrar/Action proof | Fixed Platform vocabulary shares a family with mutable roots without becoming tenant-owned. Content root registration includes all inherited children and configured canonical owner resolver; minimum empty-graph adapter must actually activate constraints. |
| C2 | Snapshots/owner lifecycle vs complete canonical graph | Format2 must include tenant in hash and validate canonical owner/external refs; successful capture must use public APIs, not hand-authored final snapshots. Legacy conversion belongs adapter, not runtime fallback. |
| C3 | Page tree/schema/handler registry and editor batching | Handler's query boundary precedes count/pagination; returned-model checks alone insufficient. Include root/translation and concrete parent constraints, preserve 100-owner budget. |
| C4 | Verified public site/SEO identity tests vs SEO schema first introduced C5 | Adopted SEO fixture cannot exist before adapter; pull minimal empty schema/registrar prerequisite into C4 when its actual site identity guard needs it. C5 completes graph/cache/lifecycle behavior. |
| C5 | Profile/redirect/artifact/captured invalidation interfaces | Internal SitemapCacheIdentity is required despite named after file list. Fixture installs verified site context for non-HTTP generator; tenant directory UUID alone cannot invent origin. |
| C6 | Existing-data adoption and publication release proof | Earlier tasks require empty adapter paths; extend same adapters with historical mapping/snapshot backfills here. Actual concurrency/cache/archive/matrix gates mandatory, not implied by SQLite. |

## Preflight: shared files/interfaces

| Tasks | Producer / consumer | Finding / ordering |
|---|---|---|
| C1/C2 | Block/placement ownership, owner registry and schema | All child rows declared in C1, C2 guards complete composition; unsupported interim writer paths fail closed. |
| C1/C3 | Content code definitions/block owners vs Pages editor | Page services consume Content API only; no foreign table writes. |
| C1/C6 | Content adopter/Doctor/schema | C1 real empty path, C6 existing data and format2 conversion reuse checkpoints. |
| C2/C3 | Canonical owner/batched Content composition | Page returns sanitized DTO only after ownerresource/connection checks. |
| C2/C4 | Snapshot media/URL rendering and public tenant/site | Site resolver admission precedes rendering, including cache/error early returns. |
| C2/C5 | External Media/reference and SEO owner/image checks | Media port remains authority; preloaded IDs do not authorize. |
| C2/C6 | Snapshot provenance/hash/lifecycle | Deterministic adoption maps canonical owner, rehashes, conserves source version/provenance. |
| C3/C4 | Pages provider/HTTP fixture/routes/context | C3 schema active, C4 installs trusted host resolver before binding; site is still tenant-local business identity. |
| C3/C5 | PageSitemapSource/listeners and SEO registry | Source objects resolved afresh under active scope; retained singleton runtime source objects rejected. |
| C3/C6 | Page adapter/tree/redirect invariants | Full subtree equality and same effective connection before mutation; old path keys mapped explicitly. |
| C4/C5 | AbsoluteUrl/SeoScope/SitemapLocationPolicy/fixtures | Resolve same verified origin, no Config mutation; schema prerequisite may move into C4, behavior remains C5. |
| C4/C6 | Cached boots/reused request worker/public graph | Actual host/content/URL outputs in A/B and failure cleanup, not only internal context facts. |
| C5/C6 | SEO adoption and artifacts/invalidation | Capture actual tenant/site/origin/version/connection identity at mutation; cleanup only obsolete captured namespace. |

## Rulings

Ruling: If C4's adopted SEO site-identity test requires installation markers, introduce the minimal real SEO registrar/schema/empty-adapter path there, then extend those same files in C5 — the plan's shared fixture activates SEO but otherwise introduces its adapter only in the following task — if wrong, task boundaries shift; no fake marker or disabled fallback is allowed.

The same native TenantRunner transaction-lifetime constraint applies to captured sitemap invalidation as recorded for U3/R3: no production transaction can outlive its runner. Controlled unit fixtures can stress immutable callback identity separately; native lifecycle denial remains tested.

Outside HTTP, verified TenantSiteContext must come from the configured host site adapter, be checked against active tenant, and remain scope-bound; caller-selected arbitrary origin is never promoted to authority. Global Content definitions are approved code vocabulary and never a shared mutable tenant catalog. Keep all package readiness claims deferred until C6 proof.

The F6-prepared global/shared setup and reviewed dependency/API handoffs were
applied to C1-C6. The implementation ledger below supersedes the earlier
preflight-only state without changing the frozen rulings.

## Implementation ledger

| Task | Implementation status | Evidence surface | Deferred verification |
|---|---|---|---|
| C1 | Implemented | Content registrar/adopter, platform definitions, tenant block graph, expansion/final migrations, Doctor, tenant fixture | All named C1 Pest and contract commands UNRUN |
| C2 | Implemented | Canonical owner/composition checks, tenant locks, Media/reference checks, format-2 snapshots, explicit legacy conversion, lifecycle cleanup | Composition, snapshot, Media, PostgreSQL concurrency commands UNRUN |
| C3 | Implemented | Tenant Page trees/translations/locks, composite keys, resource-handler query boundary/capability, editor ownership guards | Page tree/editor/HTTP/concurrency suites UNRUN |
| C4 | Implemented | Real `TenantSiteResolver` fixture, public middleware ordering, server-only Page context, verified canonical origin shared with SEO | A/B HTTP, worker, config/route cache commands UNRUN |
| C5 | Implemented | SEO registrar/adopter, profile/redirect graph, class-based tenant-safe sitemap sources, captured cache/artifact identity, after-commit invalidation | SEO/Page sitemap, Redis, PostgreSQL race commands UNRUN |
| C6 | Implemented | Bounded backfills/final constraints, package adoption cases, cross-package publication case, sealed no-dev consumer, package/catalog/docs/skills/CI surfaces | All release, archive, matrix, consumer, formatting, static-analysis and contract commands UNRUN |

Commits recorded for the implementation milestones:

- `1c9707d feat(content): enforce tenant content composition boundaries`
- `fe4a62b feat(pages): bind page trees to verified tenant sites`
- `78a6fa7 feat(seo): isolate site identity and cache ownership`
- C6 proof-surface commit is the commit containing this ledger and the implementation report.
