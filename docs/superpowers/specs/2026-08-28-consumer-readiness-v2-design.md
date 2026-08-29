# Consumer Readiness v2 Design

**Status:** Proposed design with an implementation plan authored on 2026-08-28

**Evidence base:** `nvl/laravel-suite` v1.0.7 and the KPO consumer at
`/Users/nicolasvlachos/Herd/kpo`

## Problem

The suite is a strong modular core: twenty packages share dependency ordering,
migration ownership, authorization boundaries, diagnostics, generated
contracts, and package-level tests. KPO successfully consumes seventeen of the
twenty modules and demonstrates that the suite can support a substantial
application spanning Auth, Pages, Content, Media, Settings, Activity, SEO,
Translations, Templates, Comments, and Mail Notifications.

The deeper KPO audit also proves that runtime correctness is not the same as
consumer ergonomics. KPO's Auth and Comments Doctors are healthy and its focused
Auth/candidacy suite passes, yet it publishes thirteen large package config
files, defines seventeen Auth gate bridges, reloads package Invitation/Challenge
rows in queued listeners, and queries Comments JSON/hash internals for workflow
notes. These are integration taxes that a second consumer would otherwise copy.

Fresh closeout verification also found a release-tooling blocker: Auth's own
PHPStan configuration reports 24 errors in one checksum-locked anonymous
migration, while its 132 runtime tests pass. Comments' isolated run reports 193
tests (187 passed, six skipped), but concurrent Auth/Comments quality commands
can collide in shared temp state. The program therefore starts with a sequential,
isolated root quality runner and an explicit immutable-migration verification
boundary.

KPO also exposes a recurring consumer cost. Mutation workflows are generally
well bounded, but several application-shaped reads and composed workflows still
require KPO to query package models, navigate package relations, or reproduce
package invariants. The most serious example is KPO's
`HasSingleDocumentMedia` concern, which reimplements Media staging ownership,
slot constraints, replacement, copying, and cleanup. Auth picker endpoints,
page editor bootstrap reads, and a handful of administrative aggregates show
the same issue at smaller scale.

The suite is ready for more controlled consumers today, but it should complete
the work below before it is positioned as a low-friction application platform.

## Goals

1. Make the supported consumer boundary explicit and mechanically auditable.
2. Supply bounded, authorized DTO-first read APIs for workflows proven by KPO.
3. Move cross-record lifecycle invariants into the package that owns them.
4. Make module adoption and future upgrades fail-safe and explicit.
5. Prove the contracts with clean consumer fixtures and KPO migration tests.
6. Preserve 1.x compatibility while creating a clear, measured path to 2.0.

## Non-goals

- A global suite facade that hides package ownership.
- Consumer-specific controllers, Inertia responses, route names, or UI copy.
- Domain role catalogs, page taxonomies, application settings, or other KPO
  business vocabulary inside generic packages.
- Splitting the monorepo or changing its release topology.
- Making package models private in the 1.x line.
- Optional Composer profile packages before installation weight is shown to be
  a material problem in multiple consumers.

## Binding constraints

- PHP 8.4 is the minimum runtime for this program.
- Laravel 13 is the primary framework target; the release matrix also covers
  the suite's declared Laravel 12 compatibility where applicable.
- All 1.x work is additive and preserves documented model APIs.
- New application-facing reads return bounded package DTOs or result objects.
- Every list, suggestion, history, and aggregate has a package-owned hard limit.
- Every management read and mutation passes through the package authorization
  boundary before data is returned or changed.
- Consumers may receive package models as documented identity or route-binding
  types in 1.x, but do not initiate package persistence queries or writes.
- Owner traits may expose declared relationships; lifecycle mutations remain in
  the package's Actions and services.
- Package-owned migrations remain immutable after release.
- No package or application dependency is added without separate approval.
- Each implementation task follows TDD and updates its public-contract evidence,
  generated TypeScript when applicable, and relevant documentation.
- KPO changes are made only after the corresponding suite API is released or
  available through the repository path used by KPO.

## Consumer boundary doctrine

The canonical boundary is package-owned and use-case shaped:

- **Allowed:** Actions, facades, explicit services, contracts, immutable value
  objects, DTOs, enums, owner traits, and models returned by those APIs where the
  package documents the model as an identity/result type.
- **Compatibility-only in 1.x:** initiating a query through a package model,
  relation-based aggregate reads, and route-model binding that is not followed
  immediately by a package Action.
- **Forbidden:** consumer writes through package models, package builders, raw
  package tables, package pivots, or storage paths; duplicating package
  authorization, transaction, revision, idempotency, or file-effect rules.
- **Explicit exceptions:** Filterable builders on consumer-owned models,
  Translatable query scopes on opted-in domain models, adoption migrations, and
  package-documented legacy import bridges.

The rendered `docs/consumer-readiness.md` and the machine-readable catalog in
`tools/consumer-readiness.php` must use this same language.

## Workstream A: consumer audit and adoption safety

Add `php artisan nvl:suite:consumer-audit {path?} --strict --format=table|json`.
The command scans the host application's PHP source, configuration, migrations,
routes, console schedule, and generated artifacts without reading secrets or
mutating the host.

Stable finding codes:

- `consumer.package_model_query`
- `consumer.package_model_write`
- `consumer.package_table_reference`
- `consumer.duplicate_package_migration`
- `consumer.implicit_module_decision`
- `consumer.missing_auth_binding`
- `consumer.unsafe_management_route`
- `consumer.missing_required_schedule`
- `consumer.stale_generated_contract`
- `consumer.stale_suite_skill`

Every finding reports code, severity, package, relative file, line, message,
and remediation. Strict mode fails on errors and on explicit-module warnings.
Known exceptions live in a versioned `config/nvl-suite.php` allowlist with code,
path, line-independent symbol, and reason; arbitrary regex suppression is not
supported.

Adoption becomes explicit in stages:

- 1.x adds `adoption.require_explicit_module_decisions`, defaulting to `false`.
- Suite Doctor and consumer audit warn for omitted module keys by default and
  fail in strict mode when the flag is `true`.
- `nvl:suite:configure --profile=<name>` and `--add=<module>` print or write a
  dependency-complete configuration only with an explicit `--write` option.
- `nvl:suite:upgrade:check` compares installed/published configuration with the
  current catalog and reports new modules and changed operational requirements.
- 2.0 treats missing module flags as disabled.

## Workstream B: Auth consumer reads

Auth adds authorized, bounded application APIs for the KPO picker and validation
workflows:

```php
SuggestRolesAction::execute(Authenticatable $actor, string $search, ?int $limit = null): Collection;
SuggestPermissionsAction::execute(Authenticatable $actor, string $search, ?string $group = null, ?int $limit = null): Collection;
ListRoleOptionsAction::execute(Authenticatable $actor, ?string $search = null, ?int $limit = null): Collection;
ListPermissionOptionsAction::execute(Authenticatable $actor, ?string $search = null, ?string $group = null, ?int $limit = null): Collection;
ListPermissionGroupsAction::execute(Authenticatable $actor): Collection;
ListRoleCatalogAction::execute(Authenticatable $actor, RoleIndexQueryData $query): LengthAwarePaginator;
ListPermissionCatalogAction::execute(Authenticatable $actor, PermissionIndexQueryData $query): LengthAwarePaginator;
CheckRoleNameAvailabilityAction::execute(Authenticatable $actor, string $name, ?string $exceptId = null): RoleNameAvailabilityData;
ResolveRoleIdentifiersAction::execute(Authenticatable $actor, array $identifiers): Collection;
ResolvePermissionIdentifiersAction::execute(Authenticatable $actor, array $identifiers): Collection;
AddRolePermissionsAction::execute(Authenticatable $actor, Role|string $role, array $permissionIdentifiers): Role;
SyncRolePermissionsAction::execute(Authenticatable $actor, Role|string $role, array $permissionIdentifiers): Role;
CreatePermissionWithRolesAction::execute(Authenticatable $actor, StorePermissionData $data, array $roleIdentifiers): Permission;
```

Catalogs and options are DTOs, not Eloquent models. Catalog filters and sort
aliases are allowlisted and never expose related user identities. Identifiers
accept UUIDs or canonical names, reject duplicates and unknown values, preserve
caller order, and impose a hard maximum. Package assignment Actions remove the
need for consumers to reload full role state merely to add/sync permission IDs.
`ShowRbacAnalyticsAction` remains the catalog-wide aggregate boundary.
`ShowRoleAnalyticsAction` adds the per-role user, permission-group, and
hierarchy projection KPO currently assembles; Activity history remains composed
through `ActivityReadService` so Auth does not depend on Activity.

## Workstream C: Pages and Content editor composition

Pages owns page identity, hierarchy, publication, and page-level composition.
Content owns blocks, definitions, placements, and placement mutation invariants.
The packages expose their own lower-level DTO reads; Pages composes them into an
editor bootstrap without teaching Content about pages.

Pages APIs:

```php
FindPageByKeyAction::execute(string $site, string $key, PageActorData $actor): PageData;
CheckPageKeyAvailabilityAction::execute(string $site, string $key, PageActorData $actor, ?string $exceptId = null): PageKeyAvailabilityData;
ListPublicChildPagesAction::execute(string $parentId, PageRequestContextData $context, int $limit = 50): Collection;
ListPageOptionsAction::execute(string $site, string $locale, PageActorData $actor, ?string $search = null, int $limit = 50): Collection;
ListPageEditorSummariesAction::execute(string $site, string $locale, PageActorData $actor, int $perPage = 25): LengthAwarePaginator;
GetPageEditorBootstrapAction::execute(string $pageId, string $locale, PageActorData $actor): PageEditorBootstrapData;
GetPagePublicationProjectionAction::execute(string $pageId, string $locale, PageActorData $actor): ResolvedPageData;
```

Content APIs:

```php
GetOwnerContentEditorAction::execute(Model&ContentOwner $owner, string $group, ContentActorData $actor): ContentEditorData;
ListOwnerContentPlacementSummariesAction::execute(iterable $owners, string $group, ContentActorData $actor): array;
FindContentPlacementAction::execute(Model&ContentOwner $owner, string $group, string $idOrKey, ContentActorData $actor): ContentPlacementData;
FindContentBlockByKeyAction::execute(string $key, ContentActorData $actor): ContentBlockData;
ReplaceContentPlacementAction::execute(Model&ContentOwner $owner, string $group, string $placementId, string $blockId, int $expectedRevision, ContentActorData $actor): ContentPlacementData;
ReorderContentPlacementsAction::execute(Model&ContentOwner $owner, string $group, ReorderContentPlacementsData $data, ContentActorData $actor): ContentEditorData;
```

`PageEditorSummaryData` contains one page, its localized label, placed Content
DTOs, and SEO projection for bounded editor indexes. `PageEditorBootstrapData`
contains `PageData`, locale/status/kind option values, resource aliases,
Content's complete `ContentEditorData`, SEO's owner projection, and Metafields'
owner projection. Pages uses a new authorized Metafields wrapper Action rather
than invoking the existing storage-focused owner list directly. Neither API
returns builders or lazy Eloquent relations. Query-count tests compare one and
twenty-five pages/blocks. Actor-specific button capabilities remain owned by
the consumer's authorization/UI layer; package Actions enforce authorization
at execution time.

## Workstream D: Media owner-slot workflows

Media adds an application-level owner-slot API so consumers do not implement
association and file lifecycle rules themselves:

```php
GetOwnerMediaSlotAction::execute(MediaActorData $actor, Model&HasMedia $owner, string $slot): ?MediaLibraryItem;
ReplaceOwnerMediaSlotAction::execute(MediaActorData $actor, Model&HasMedia $owner, string $slot, string $mediaId, ?string $idempotencyKey = null): MediaLibraryItem;
ClearOwnerMediaSlotAction::execute(MediaActorData $actor, Model&HasMedia $owner, string $slot, ?string $idempotencyKey = null): void;
CopyOwnerMediaSlotAction::execute(MediaActorData $actor, Model&HasMedia $owner, string $slot, string $sourceMediaId, ?string $idempotencyKey = null): MediaLibraryItem;
```

The package resolves configured slots and media identities, authorizes both the
actor and owner operation, validates MIME and size constraints, accepts only an
unassociated asset or an actor-owned staging association, performs one-to-one
replacement transactionally, removes only eligible staging associations,
schedules file effects after commit, and returns a safe DTO/URL projection.
Copy preserves approved metadata and attribution but creates a new exclusive
asset when the destination slot requires exclusivity. Repeated idempotency keys
return the completed result or reject a mismatched payload.

## Workstream E: smaller package seams

- **Activity:** `ActivityIndexFilter` accepts a normalized list of at most ten
  events; timeline history APIs own a configurable hard maximum and can read a
  bounded set of value-only subject references without consumer model queries.
- **Mail Notifications:** statistics include bounded mailer/category aggregates;
  failed inboxes use `ListMailNotificationsAction(failedOnly: true)`; the
  `MailTrackingStarted` event carries an approved, redacted correlation map so
  listeners do not reload `MailNotification`.
- **Translations:** add total, missing, changed, and conflict statistics using a
  package DTO and one bounded aggregate query.
- **Comments:** add an authorized latest-matching-target read that returns one
  DTO and never exposes a builder. The selector is package-owned and later
  accepts only registered metadata aliases, never raw JSON paths.
- **Settings:** `SettingChanged` carries a stable, value-free subject reference
  suitable for Activity mapping.
- **SEO:** add owner/profile lookup and revision projection Actions so consumers
  do not navigate the `HasSeo` relation to assemble editor data.

## Workstream F: proof consumers and KPO migration

Two clean fixtures become release gates:

1. `auth-production-consumer`: Auth, Settings, Activity, and Mail Notifications.
2. `content-production-consumer`: Pages, Content, Media, SEO, Translatable,
   Translations, and Metafields.

Each fixture proves fresh install, package-owned and application-owned migration
modes, config cache, route cache, strict Doctor, strict consumer audit, generated
types, and zero unallowlisted direct package-model queries or writes.

KPO then migrates in bounded waves:

1. Auth picker/search/identifier controllers use Auth Actions and DTOs.
2. Failed-mail inbox uses the existing bounded Mail Notifications Action.
3. Page and Content editor reads use the composed bootstrap APIs.
4. Document models replace `HasSingleDocumentMedia` lifecycle code with Media
   owner-slot Actions; the remaining consumer concern only declares slot policy
   and domain-specific convenience accessors.
5. Cross-package listeners consume stable event context rather than re-querying
   package persistence.

KPO's full suite, strict consumer audit, and a focused set of integration tests
must pass after every wave.

## Workstream G: Auth delivery and invitation seams

Delivery events carry privacy-bounded value context so queued host listeners do
not reload Challenge or Invitation. Challenge delivery includes a subject
reference; invitation delivery includes ID, recipient, type/purpose, inviter,
roles/permissions, approved metadata, expiry, resend count, and the current
delivery message ID. Invitation acceptance includes the committed acceptance
timestamp.

Auth adds authorized DTO list/active-lookup Actions, ID-based lifecycle entry
points, and a delivery-outcome Action. A forward migration stores the current
message ID, pending/delivered/failed status, attempted/delivered/failed times,
and a bounded failure code in dedicated columns. Application metadata is not
used as an operational status store. A stale outcome from an older resend may
be audited but cannot overwrite the current attempt.

KPO keeps its mailables and onboarding rules while replacing Challenge/
Invitation listener reloads, direct status writes, recipient-hash list/pending
queries, and redundant acceptance re-verification with these package facts.

## Workstream H: consumer configuration and Auth ergonomics

The suite adds one reliable root-level package-quality runner because package
Composer aliases are not consistently executable from the monorepo. That
runner becomes a prerequisite for every detailed workstream.

Package configuration remains Laravel-native and recursively merged, but the
merge rule becomes explicit: maps merge recursively and host lists replace
default lists atomically. The shared implementation lives in `nvl/support`; its
adoption by packages not already depending on Support requires separate
dependency approval. The catalog gains default-file, open-map, deprecation, and
merge-strategy metadata.
`nvl:suite:upgrade:check` compares structural key/type trees without outputting
values. It reports unknown and deprecated keys deterministically; expanded
snapshot-shaped overlays also report missing-current paths. Small intentional
overlays do not warn for omitted defaults. Commands never rewrite a consumer
file without explicit `--write` and `--force` plus a diff.

Runtime module selection accepts a profile and explicit include/exclude roots,
then closes dependencies transitively. Existing 1.x boolean maps remain
authoritative when present; mixed legacy/new input fails diagnostics. This lets
a consumer express capabilities rather than maintain twenty copied decisions.

Auth adds an `embedded-application` preset for the architecture KPO already
uses: package services and persistence, host HTTP/UI, host delivery, a custom
User model, and host policies. A configurable `AuthManagementAccess` binding and
catalog-backed policy mapping replace repetitive `nvl-auth.*` bridge gates while
remaining deny-by-default. KPO routes, controllers, policies, invitation
metadata providers, mailables, and business feature choices stay in KPO.

## Workstream I: Comments metadata and rich mentions

Existing Comments metadata remains accepted and internal in 1.x. Consumers may
register metadata schemas containing stable namespaces, scalar field types,
storage keys, mutability, queryability, bounds, and visible audiences. Only
registered fields can appear in projections or safe equality selectors; clients
cannot provide JSON paths. Strict rejection of unregistered keys is opt-in.

Rich mentions use a versioned, bounded document with paragraph, text,
hard-break, and mention nodes. The package derives the legacy/searchable `body`,
stores the document in Comments/Revisions, and normalizes current references in
a `comment_mentions` table. Mention rows identify a server-registered resource
alias and opaque ID, not a consumer model foreign key.

Simple resources can be configured with a model class plus allowlisted search,
label, and exposed fields and an authorization class. Sensitive resources use a
custom resolver. In both cases the client sends only alias/query/ID; it never
chooses PHP classes or fields. Suggestions and resolution are bounded and
batch-oriented. Projections expose current fields/URLs only after authorization,
fall back to safe label snapshots for missing/restricted history, and prohibit
actor-specific data in public response caches.

After-commit mention diff events let hosts build notifications without parsing
text or querying Comments. Revisions, restore, anonymization, idempotency,
concurrency, Doctor, reconciliation, generated types, and all supported database
engines are part of the contract. KPO registers candidacy workflow metadata and
initial User/Organization/Event/Candidacy mention resolvers, then removes its
direct metadata/status-hash queries.

## Release sequence

- **1.1:** consumer doctrine, consumer audit, explicit module decisions, configure
  and upgrade-check commands, Auth reads, and smaller package seams.
- **1.2:** Content owner-editor APIs, Pages editor/public/options APIs, SEO owner
  projection, and generated TypeScript contracts.
- **1.3:** Media owner-slot workflows, proof consumers, golden journey docs, and
  completed KPO migration.
- **1.4:** Auth delivery/invitation context and KPO migration, structural config
  diagnostics, runtime minimal overlays, embedded Auth integration, registered
  Comments metadata, rich mentions, and KPO proof.
- **2.0:** missing module flags default disabled; compatibility-only model query
  examples are removed; deprecations are enforced only after 1.x telemetry and
  migration evidence exist.

## Completion criteria

The program is complete when:

- all stable finding codes are documented and tested;
- all new APIs are bounded, authorized, DTO-first, and TypeScript-covered where
  the package participates in generated types;
- no clean fixture needs an unallowlisted package model query or write;
- KPO no longer implements package-owned Media invariants or Auth/Page/Content
  read queries;
- KPO Auth delivery listeners and invitation indexes do not reload/write package
  persistence, while KPO retains ownership of Auth routes/UI/business policy;
- clean consumers and KPO use intentional configuration overlays and pass
  value-free structural upgrade diagnostics under config cache;
- Comments registered metadata and rich mentions pass authorization, privacy,
  revision/restore/anonymization, concurrency, reconciliation, query-bound, and
  public-cache tests, and KPO has no direct Comments JSON/hash query;
- SQLite, PostgreSQL, MySQL, and MariaDB portability gates pass where currently
  supported by the package;
- Laravel 12/13 and PHP 8.4/8.5 CI matrices pass as declared by each release;
- config/route caching, previous-minor upgrade, package archive, and clean
  consumer rehearsals pass;
- the strict suite Doctor and strict consumer audit are both green.
