# Auth Delivery and Invitation Consumer Seams Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let host delivery, onboarding, and invitation-list workflows consume value-only Auth context without querying or writing Challenge/Invitation models.

**Architecture:** Auth emits privacy-bounded delivery and acceptance projections while retaining token secrecy. New invitation projection Actions own hashing, lifecycle filters, authorization, locking, and delivery outcome writes; consumers keep mail templates and onboarding business decisions.

**Tech Stack:** PHP 8.4, Laravel 13 events/queues/Eloquent, Spatie Laravel Data, TypeScript Transformer, Pest 4.

**Spec:** `docs/superpowers/specs/2026-08-28-consumer-readiness-v2-design.md`

## Global Constraints

- Existing event and value-object constructors remain compatible by appending optional parameters.
- Delivery projections never contain token hashes, active keys, recipient hashes, challenge hashes, or arbitrary objects.
- Secret delivery payloads keep their current debug redaction and size limits.
- Actorless invitation lookup requires an explicit trusted `InvitationIssuanceContext` and is never exposed by package HTTP routes.
- Invitation list and lookup results are DTOs; mutation Actions may still return models as documented identity results in 1.x.
- Delivery outcome writes use stable codes, never exception messages.

---

### Task 1 (CR-25a): Carry safe Auth delivery and acceptance context

**Files:**
- Create: `packages/nvl/auth/src/Data/Display/AuthSubjectReferenceData.php`
- Create: `packages/nvl/auth/src/Data/Display/InvitationDeliveryData.php`
- Create: `packages/nvl/auth/src/Services/InvitationDeliveryMetadataPolicy.php`
- Modify: `packages/nvl/auth/config/nvl-auth.php`
- Modify: `packages/nvl/auth/src/ValueObjects/AuthDeliveryRequest.php`
- Modify: `packages/nvl/auth/src/Events/InvitationAccepted.php`
- Modify: `packages/nvl/auth/src/Actions/Challenges/IssueChallengeAction.php`
- Modify: `packages/nvl/auth/src/Actions/Invitations/CreateInvitationAction.php`
- Modify: `packages/nvl/auth/src/Actions/Invitations/ResendInvitationAction.php`
- Modify: `packages/nvl/auth/src/Actions/Invitations/AcceptInvitationAction.php`
- Modify: `packages/nvl/auth/src/Actions/Invitations/RegisterInvitationAction.php`
- Modify: `packages/nvl/auth/tests/Unit/AuthDeliveryRequestTest.php`
- Modify: `packages/nvl/auth/tests/Feature/ChallengeLifecycleTest.php`
- Modify: `packages/nvl/auth/tests/Feature/InvitationLifecycleTest.php`

**Interfaces:**
- Consumes: existing `SubjectReference`, invitation fields, delivery payload redaction, and after-commit events.
- Produces: optional `AuthDeliveryRequest::$subject`, optional `::$invitation`, and `InvitationAccepted::$acceptedAt`.

- [x] **Step 1: Write failing event-context and privacy tests**

Assert a Magic Link request carries the challenged subject reference, an
Invitation request carries `InvitationDeliveryData`, and acceptance carries the
durable accepted timestamp. Serialize/queue round-trip each event. Assert debug
output and JSON snapshots do not contain `token`, hashes, active keys, nested
objects, rejected metadata keys, or protected values.

- [x] **Step 2: Run focused tests and verify missing context fails**

Run: `vendor/bin/pest --configuration=packages/nvl/auth/phpunit.xml.dist --compact packages/nvl/auth/tests/Unit/AuthDeliveryRequestTest.php packages/nvl/auth/tests/Feature/ChallengeLifecycleTest.php packages/nvl/auth/tests/Feature/InvitationLifecycleTest.php`

Expected: FAIL because the delivery/acceptance projections do not exist.

- [x] **Step 3: Implement the safe invitation delivery DTO**

`AuthSubjectReferenceData` is a camel-case `#[TypeScript]` Data contract with
`type` and `id` strings plus `fromReference(SubjectReference $reference)`. Use
this DTO in generated contracts instead of exposing the package's PHP-only
value object.

`InvitationDeliveryData` is a camel-case `#[TypeScript]` Data contract with:

```php
string $id;
string $type;
string $purpose;
string $recipient;
?AuthSubjectReferenceData $inviter;
array $roles;
array $permissions;
array $metadata;
CarbonImmutable $expiresAt;
int $resendCount;
```

`InvitationDeliveryMetadataPolicy` exposes only keys explicitly allowlisted in
`features.invitations.settings.delivery_metadata_keys` (default empty), capped
at 50 safe snake-case keys. Values must be scalar/null with 255-character
strings. Reject configured keys containing token, secret, password, hash,
signature, payload, or credential; reject nested arrays and objects. Build the
projection while the package-owned Invitation row is already available inside
create/resend transactions. Doctor validates the allowlist without displaying
metadata values.

- [x] **Step 4: Append compatible subject and acceptance context**

Append `?SubjectReference $subject = null` and
`?InvitationDeliveryData $invitation = null` to `AuthDeliveryRequest`. Populate
subject for challenges and invitation for create/resend. Append
`?CarbonImmutable $acceptedAt = null` to `InvitationAccepted`, populate it from
the locked accepted row, and retain null only for manually constructed legacy
events. Update `__debugInfo()` to reveal presence/type only, not projection
values.

- [x] **Step 5: Generate types and run Auth quality**

Run: `php artisan nvl:data:types:generate --fail-on-warning`

Run: `composer types:check`

Run: `php tools/run-package-quality.php auth`

Expected: all PASS.

- [x] **Step 6: Commit CR-25a**

```bash
git add packages/nvl/auth/config/nvl-auth.php packages/nvl/auth/src/Data/Display/AuthSubjectReferenceData.php packages/nvl/auth/src/Data/Display/InvitationDeliveryData.php packages/nvl/auth/src/Services/InvitationDeliveryMetadataPolicy.php packages/nvl/auth/src/ValueObjects/AuthDeliveryRequest.php packages/nvl/auth/src/Events/InvitationAccepted.php packages/nvl/auth/src/Actions/Challenges/IssueChallengeAction.php packages/nvl/auth/src/Actions/Invitations packages/nvl/auth/tests resources/js/types
git commit -m "feat(auth): carry safe delivery context"
```

**Evidence (2026-08-29):**

- Focused context, privacy, legacy-queue, malformed-grant, and Doctor tests: 36 passed, 270 assertions.
- `php tools/run-package-quality.php auth`: passed with 168 tests and 2,400 assertions.
- `composer contracts:check`, `composer types:check`, and the production-consumer PHPStan fixture: passed.
- Independent review found no critical issues; rolling-deploy defaults, grant bounds, and `active_key` rejection were hardened before commit.

### Task 2 (CR-25b): Add invitation projections, active lookup, and delivery outcomes

**Files:**
- Create: `packages/nvl/auth/database/migrations/2026_08_28_000000_add_invitation_delivery_outcomes.php`
- Create: `packages/nvl/auth/src/Enums/InvitationDeliveryStatus.php`
- Create: `packages/nvl/auth/src/Data/Display/InvitationReadData.php`
- Create: `packages/nvl/auth/src/Actions/Invitations/ListInvitationProjectionsAction.php`
- Create: `packages/nvl/auth/src/Actions/Invitations/FindActiveInvitationAction.php`
- Create: `packages/nvl/auth/src/Actions/Invitations/RecordInvitationDeliveryOutcomeAction.php`
- Modify: `packages/nvl/auth/src/Data/Queries/InvitationIndexQueryData.php`
- Modify: `packages/nvl/auth/src/Models/Invitation.php`
- Modify: `packages/nvl/auth/src/Actions/Invitations/CreateInvitationAction.php`
- Modify: `packages/nvl/auth/src/Actions/Invitations/ResendInvitationAction.php`
- Modify: `packages/nvl/auth/src/Actions/Invitations/RevokeInvitationAction.php`
- Modify: `packages/nvl/auth/tests/Feature/AuthDeliveryContextMigrationTest.php`
- Modify: `packages/nvl/auth/tests/Feature/SchemaOwnershipTest.php`
- Modify: `packages/nvl/auth/tests/Feature/InvitationLifecycleTest.php`
- Modify: `packages/nvl/auth/tests/Unit/OpenApiContractTest.php`
- Modify: `packages/nvl/auth/README.md`
- Modify: `tools/consumer-readiness.php`
- Modify: `tests/Contract/ConsumerReadinessTest.php`

**Interfaces:**
- Consumes: invitation model registry/configuration, `SecretHasher`, `ManagementAuthorizer`, issuance trust context, and CR-25a delivery projection.
- Produces: DTO list/active reads, ID-based resend/revoke, and package-owned outcome mutation.

- [x] **Step 1: Write failing projection, lookup, and outcome tests**

Cover authorized pagination, multiple type filters, lifecycle/context filters,
actorless denial, explicit actorless authorization, recipient normalization,
active expiry, no recipient enumeration in errors, one-versus-100 query counts,
ID-based resend/revoke, delivered/failed outcome transitions, concurrent duplicate
outcomes, and event/audit ordering.

- [x] **Step 2: Run invitation tests and verify APIs are missing**

Run: `vendor/bin/pest --configuration=packages/nvl/auth/phpunit.xml.dist --compact packages/nvl/auth/tests/Feature/InvitationLifecycleTest.php packages/nvl/auth/tests/Unit/OpenApiContractTest.php`

Expected: FAIL because the projection/lookup/outcome APIs do not exist.

- [x] **Step 3: Implement bounded invitation list projections**

`InvitationReadData` contains ID, recipient, type, purpose, inviter reference,
role/permission names, approved metadata, lifecycle, accepted/revoked/sent/
expires timestamps, resend count, and accepted subject reference. It excludes
all hashes and token state. Extend `InvitationIndexQueryData` with a
deduplicated `types` list capped at 20 while preserving legacy `type`; reuse the
existing hashed recipient/context and lifecycle rules.

`ListInvitationProjectionsAction::execute(Authenticatable $actor,
?InvitationIndexQueryData $filters = null, int $perPage = 25):
LengthAwarePaginator` authorizes first, builds the same bounded query as the
existing compatibility Action, and maps with paginator `through()`.

- [x] **Step 4: Implement trusted active lookup and ID-based resend/revoke**

`FindActiveInvitationAction::execute(string $recipient, string $purpose,
?array $types = null, ?string $context = null, ?Authenticatable $actor = null,
?InvitationIssuanceContext $issuance = null): ?InvitationReadData` authorizes a
management actor or requires `actorlessAuthorized: true`; it hashes normalized
inputs, caps types at 20, applies active lifecycle rules, and returns only the
newest deterministic DTO. Extend `ResendInvitationAction` and
`RevokeInvitationAction` to accept `Invitation|string` and resolve/lock the ID
internally while retaining existing model-call compatibility.

- [x] **Step 5: Implement delivery outcome mutation**

Do not overload encrypted application metadata with delivery state. Add a
forward migration (never edit the released create migration) for nullable
`current_delivery_message_id`, enum-backed string `delivery_status`,
`delivery_attempted_at`, `delivered_at`, `delivery_failed_at`, and bounded
`delivery_failure_code`, with an index on status/attempted time. Create/resend
set the current request message ID and reset the outcome to Pending in the same
transaction that dispatches delivery.

`RecordInvitationDeliveryOutcomeAction::execute(string $invitationId, string
$messageId, InvitationDeliveryStatus $status, CarbonImmutable $occurredAt,
?string $failureCode = null): void` locks the Invitation, validates the current
message ID, enforces a 1–120 safe failure code only for Failed, and updates the
dedicated columns. The same outcome is idempotent; a result for a superseded
message ID is recorded as a bounded stale audit fact and cannot overwrite a
newer resend. It never accepts an exception or arbitrary metadata.

- [x] **Step 6: Document and verify the consumer boundary**

Document delivery listeners using request projections, actorless lookup as a
trusted server-only boundary, DTO listing, ID-based resend, and outcome writes.
Add their tests to `tools/consumer-readiness.php`.

Run: `php artisan nvl:data:types:generate --fail-on-warning`

Run: `php tools/run-package-quality.php auth`

Run: `php artisan test --compact tests/Contract/ConsumerReadinessTest.php`

Run: `composer types:check`

Expected: all PASS.

- [x] **Step 7: Commit CR-25b**

```bash
git add packages/nvl/auth/database/migrations/2026_08_28_000000_add_invitation_delivery_outcomes.php packages/nvl/auth/src/Enums/InvitationDeliveryStatus.php packages/nvl/auth/src/Data/Display/InvitationReadData.php packages/nvl/auth/src/Actions/Invitations packages/nvl/auth/src/Data/Queries/InvitationIndexQueryData.php packages/nvl/auth/src/Models/Invitation.php packages/nvl/auth/tests packages/nvl/auth/README.md tools/consumer-readiness.php tests/Contract/ConsumerReadinessTest.php resources/js/types
git commit -m "feat(auth): add invitation consumer projections"
```

**Evidence (2026-08-29):**

- `php tools/run-package-quality.php auth`: passed with 176 tests, 2,481 assertions, and the process-level PostgreSQL/MySQL concurrency test explicitly skipped on SQLite.
- Consumer-readiness and production-fixture contracts: 20 tests and 1,883 assertions passed.
- Package PHPStan, production-consumer PHPStan, generated TypeScript, TypeScript compilation, package contracts, Pint, and diff checks passed.
- Independent review found no remaining Critical, Important, or Minor findings after correlation-ID privacy, generated list typing, raw input bounds, committed event ordering, and portable two-process concurrency coverage were hardened.
- Commit: `d83b303` (`feat(auth): add invitation consumer projections`).

### Task 3 (CR-26): Migrate KPO delivery and invitation reads to package seams

**Files (KPO repository):**
- Modify: `app/Listeners/Auth/DeliverMagicLink.php`
- Modify: `app/Listeners/Auth/Invitations/SendRegistrationInvitationMail.php`
- Modify: `app/Contracts/Auth/InvitationMetadataProvider.php`
- Modify: `app/Support/Auth/InvitationMetadataRegistry.php`
- Modify: `app/Support/Auth/BaseInvitationMetadataProvider.php`
- Modify: `app/Support/Auth/CandidateInvitationMetadataProvider.php`
- Modify: `app/Support/Auth/RegistrationInvitationAttributes.php`
- Modify: `app/Services/Auth/RegistrationInvitationService.php`
- Modify: `app/Mail/Auth/RegistrationInvitationMail.php`
- Modify: `app/Listeners/Kpo/StartCandidacyAttemptFromInvitation.php`
- Modify: `app/Listeners/Kpo/StartCandidacyAttemptFromRoleAssignment.php`
- Modify: `app/Actions/Auth/Relations/Invitations/ListUserInvitationsAction.php`
- Modify: `app/Actions/Auth/Registration/RequestCandidateInvitationAction.php`
- Modify: `app/Http/Controllers/Auth/Relations/UserRelationsInvitationsController.php`
- Modify: `app/Data/Auth/Invitations/UserInvitationListItemData.php`
- Modify: `tests/Feature/Auth/MagicLinkActionTest.php`
- Modify: `tests/Feature/Auth/Users/Relations/UserInvitationActionsTest.php`
- Modify: `tests/Feature/Auth/PasswordlessCodeAndCandidateInvitationTest.php`
- Modify: `Modules/Kpo/tests/Feature/CandidacyAbandonmentLifecycleTest.php`

**Interfaces:**
- Consumes: CR-25 delivery subject/invitation context, acceptance timestamp,
  DTO list/lookup, ID-based resend, and delivery outcome Action.
- Produces: KPO-owned mail/UI/onboarding behavior without Challenge/Invitation
  queries or writes in listeners, indexes, or candidate-invitation coordination.

- [ ] **Step 1: Freeze KPO delivery and invitation behavior**

Cover queued Magic Link and invitation delivery, delivery success/failure,
resend race, existing/new recipient presentation, invitation list filters,
candidate self-service cooldown, role-assignment fallback, exact acceptance
timestamp, revoke/resend authorization, and exception/retry behavior. Assert the
current listener jobs can serialize and run after the original request process.

- [ ] **Step 2: Remove delivery-listener reloads and writes**

`DeliverMagicLink` resolves the User from `AuthDeliveryRequest::$subject`
instead of reloading Challenge. `SendRegistrationInvitationMail` builds KPO's
mailable from `::$invitation` plus the one-time token payload and records
Delivered/Failed through `RecordInvitationDeliveryOutcomeAction` using the
request message ID. It never reloads or writes Invitation, and it maps Throwable
to a stable KPO-owned failure code before rethrowing for the queue retry policy.

Refactor the KPO invitation delivery presentation path—not its creation/
acceptance business hooks—to accept `InvitationDeliveryData`. The registry and
provider expose a delivery-mail method using the DTO; the mailable and signed-URL
service use type, recipient, approved metadata, expiry, inviter reference, and
token rather than an Invitation model. Keep model-accepting post-accept/list/
lifecycle methods until their corresponding package mutation boundary is
migrated. Tests must prove the queued mailable serializes no model identifier
that causes `SerializesModels` to reload package persistence.

- [ ] **Step 3: Consume acceptance event facts directly**

`StartCandidacyAttemptFromInvitation` uses `InvitationAccepted::$subject` and
`::$acceptedAt`; it may resolve the application User, but it does not reload the
Invitation to re-verify facts already guaranteed by the package transaction.
Keep the KPO role/business check before starting the attempt.

- [ ] **Step 4: Replace invitation lists and pending lookups**

Map KPO filters to `InvitationIndexQueryData` and
`ListInvitationProjectionsAction`, then build `UserInvitationListItemData` from
DTOs. Replace recipient-hash/model queries in candidate self-service and role
assignment with `FindActiveInvitationAction` under explicit trusted issuance
context. Keep KPO account-existence and business-option checks in KPO.

KPO currently accepts a `memberId` list filter but every shipped KPO metadata
provider's `applyListFilters()` is a no-op. Add a behavior test before migration:
if member context is a supported product feature, persist it through
`StoreInvitationData::$context` and map the filter to the package's hashed
`context`; otherwise remove/reject the ineffective filter and UI control. Never
preserve a silently ignored filter.

- [ ] **Step 5: Use ID-based package lifecycle Actions**

Controllers and KPO wrappers pass invitation IDs into resend/revoke/list seams
instead of route-bound package models where supported. Registration consumption
may continue receiving the documented package model result until a later
mutation-result migration; it must not initiate a new package query/write.

- [ ] **Step 6: Run the focused KPO gate**

```bash
php artisan test --compact tests/Feature/Auth/MagicLinkActionTest.php tests/Feature/Auth/Users/Relations/UserInvitationActionsTest.php tests/Feature/Auth/PasswordlessCodeAndCandidateInvitationTest.php Modules/Kpo/tests/Feature/CandidacyAbandonmentLifecycleTest.php tests/Feature/Auth/NvlSuiteIntegrationTest.php
php artisan nvl:auth:doctor --strict --format=json
php artisan nvl:suite:consumer-audit --strict --format=json
```

Expected: no unallowlisted Challenge/Invitation model query/write finding in
delivery listeners, invitation list/cooldown, or candidacy start listeners.

- [ ] **Step 7: Commit CR-26 in reversible KPO waves**

Commit Magic Link delivery, invitation delivery/outcomes, invitation reads, and
candidacy timing separately. Each commit must pass the focused gate.

### Workstream acceptance gate

- [ ] Run `php tools/run-package-quality.php auth`.
- [ ] Run `composer contracts:check` and `composer types:check`.
- [ ] Prove queued Auth delivery events contain sufficient context without a Challenge/Invitation query.
- [ ] Prove no new Action exposes hashes, secrets, builders, or unbounded metadata.
- [ ] Run KPO CR-26 before promoting the 1.4 release candidate.
