# Comments Metadata and Rich Mentions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give consumers typed, queryable, audience-safe comment metadata and first-class rich mentions of registered application resources without exposing package persistence or accepting client-selected models/columns.

**Architecture:** Existing JSON metadata remains backward compatible and internal by default. Hosts register metadata schemas to opt selected fields into validation, safe selectors, and audience projections; queryable scalar fields receive a normalized hash-only index while JSON remains the revisioned source of truth. Rich comments use a bounded versioned document with text, hard-break, and mention nodes; current mentions are normalized into a package-owned table, while `body` remains a server-derived plain-text/search/export projection. A registry supports declarative Eloquent resources for simple cases and custom resolver classes for policy-sensitive domains.

**Tech Stack:** PHP 8.4, Laravel 13 Eloquent/events/transactions/config, Spatie Laravel Data/TypeScript Transformer, Pest 4, SQLite/PostgreSQL/MySQL/MariaDB.

**Spec:** `docs/superpowers/specs/2026-08-28-consumer-readiness-v2-design.md`

## KPO evidence this plan must remove

- KPO already writes `workflow_event`, `recipient_user_id`, and `initiated_by_user_id` into comment metadata.
- KPO directly queries `metadata->workflow_event`, `tags`, `status_hash`, and package `CommentIdentity` to find/delete workflow notes.
- Raw metadata is stored and revisioned but deliberately omitted from every public/member/management/revision DTO.
- The package README currently classifies mentions as consumer-owned, leaving each application to invent parsing, lookup, authorization, persistence, and notifications.
- KPO's Comments configuration has only four meaningful overrides among 52 copied defaults and exposes one target alias, `candidacy`.

## Binding design decisions

- HTTP clients submit a registered resource alias and opaque resource ID. They never submit a model class, table, column, query operator, route name, or resolver class.
- Mention resources are server-registered. A declarative Eloquent definition is convenience only; complex or sensitive domains use a resolver contract.
- The first rich-document version permits `paragraph`, `text`, `hard_break`, and `mention` nodes only. It accepts no HTML, embedded media, arbitrary marks, nested lists, or executable payloads.
- A mention node is `{type, tokenId, resource, id}` on input. Labels, exposed fields, and URLs are resolved and authorized server-side.
- `body` remains non-null for compatibility, search, exports, Activity, and mail. For rich comments it is derived by the package from normalized text and server-resolved mention labels.
- Current mention rows are normalized; historical revisions keep a normalized document snapshot and label snapshots. They do not live-resolve old resource fields by default.
- Resource models have no foreign keys from Comments. Deletion or access loss produces a tombstone/snapshot, never cross-package cascade failure.
- Unregistered legacy metadata remains accepted and internal in 1.x. Strict schema mode is opt-in and becomes eligible for a 2.0 default only after migration evidence.
- Mention notifications are after-commit facts about added/removed references. Delivery channels and notification copy remain consumer-owned.
- Public mention projection must be viewer-independent. Actor-specific resource data is limited to member/management projections and must not enter public response caches.

## Hard bounds

| Surface | Default | Maximum |
|---|---:|---:|
| Metadata encoded bytes | 16,384 | 65,536 |
| Registered metadata fields per comment | 50 | 100 |
| Rich document encoded bytes | 32,768 | 131,072 |
| Blocks | 100 | 250 |
| Nodes | 500 | 1,000 |
| Mention resource aliases per comment | 10 | 20 |
| Mentions per comment | 25 | 100 |
| Suggestion query length | 160 chars | 160 chars |
| Suggestions per request | 10 | 20 |
| Batch resource resolution | 100 IDs | 100 IDs |

---

### Task 1 (CR-31): Register metadata schemas and add safe selectors/projections

**Files:**
- Create: `packages/nvl/comments/database/migrations/2026_08_28_000000_create_comment_metadata_values_table.php`
- Create: `packages/nvl/comments/src/Contracts/CommentMetadataSchema.php`
- Create: `packages/nvl/comments/src/Definitions/CommentMetadataField.php`
- Create: `packages/nvl/comments/src/Enums/CommentMetadataValueType.php`
- Create: `packages/nvl/comments/src/Services/CommentMetadataRegistry.php`
- Create: `packages/nvl/comments/src/Services/CommentMetadataGuard.php`
- Create: `packages/nvl/comments/src/Services/CommentMetadataIndexWriter.php`
- Create: `packages/nvl/comments/src/Models/CommentMetadataValue.php`
- Create: `packages/nvl/comments/src/Data/CommentMetadataProjectionData.php`
- Modify: `packages/nvl/comments/src/Data/Queries/CommentSelectorData.php`
- Modify: `packages/nvl/comments/src/Actions/FindLatestTargetCommentAction.php`
- Create: `packages/nvl/comments/src/Actions/DeleteLatestTargetCommentAction.php`
- Modify: `packages/nvl/comments/config/comments.php`
- Modify: `packages/nvl/comments/src/Definitions/Tables/CommentsTables.php`
- Modify: `packages/nvl/comments/src/Providers/CommentsServiceProvider.php`
- Modify: `packages/nvl/comments/src/Services/CommentContentGuard.php`
- Modify: `packages/nvl/comments/src/Services/CommentProjectionFactory.php`
- Modify: `packages/nvl/comments/src/Data/PublicCommentData.php`
- Modify: `packages/nvl/comments/src/Data/MemberCommentData.php`
- Modify: `packages/nvl/comments/src/Data/CommentManagementData.php`
- Modify: `packages/nvl/comments/src/Data/CommentRevisionData.php`
- Modify: `packages/nvl/comments/src/Actions/CreateCommentAction.php`
- Modify: `packages/nvl/comments/src/Actions/UpdateCommentAction.php`
- Modify: `packages/nvl/comments/src/Actions/RestoreCommentRevisionAction.php`
- Modify: `packages/nvl/comments/src/Actions/AnonymizeCommentAction.php`
- Modify: `packages/nvl/comments/src/Services/CommentStateReconciler.php`
- Modify: `packages/nvl/comments/src/Console/CommentsDoctorCommand.php`
- Modify: `packages/nvl/comments/src/Console/CommentsReconcileCommand.php`
- Modify: `packages/nvl/comments/src/Data/CommentReconciliationResultData.php`
- Create: `packages/nvl/comments/tests/Feature/CommentMetadataContractsTest.php`
- Modify: `packages/nvl/comments/tests/Feature/CommentsV1ApiProjectionTest.php`
- Modify: `packages/nvl/comments/tests/Feature/CommentsDoctorCommandTest.php`
- Modify: `packages/nvl/comments/tests/Feature/CommentsModerationReconciliationTest.php`
- Modify: `packages/nvl/comments/README.md`

**Interfaces:**
- Consumes: existing metadata JSON, revisions, target/actor/audience authorization, and CR-12's planned latest-target read.
- Produces: registered schema validation, audience-safe metadata DTOs, and package-owned tag/metadata selectors.

- [ ] **Step 1: Write failing compatibility, validation, and privacy tests**

Cover fresh/upgrade/application-owned storage, an unregistered legacy key, a registered scalar/null field, wrong types,
unknown registered fields, duplicate storage keys across schemas, encoded size,
field count, per-audience visibility, tombstones, revisions, anonymization, and
strict mode. Run equality selectors against string/integer/boolean/null values on
the supported database matrix. Assert raw/unregistered metadata never appears in any projection,
event serialization, log context, or exception message.

- [ ] **Step 2: Define the metadata schema contract**

Each container-resolvable schema returns a stable snake/dot namespace and
`list<CommentMetadataField>`. A field defines:

```php
public readonly string $name;
public readonly string $storageKey;
public readonly CommentMetadataValueType $type;
public readonly bool $nullable;
public readonly bool $mutable;
public readonly bool $queryable;
/** @var list<CommentAudience> */
public readonly array $visibleTo;
public readonly ?int $maximumStringLength;
```

Allowed registered types are string, integer, boolean, UUID, and nullability.
No object, model, date parser, arbitrary callback, nested path, or secret type is
accepted. Registry boot rejects duplicate namespace/field/storage-key ownership.

- [ ] **Step 3: Add explicit metadata configuration and guarding**

Add:

```php
'metadata' => [
    'strict' => false,
    'maximum_bytes' => 16_384,
    'maximum_registered_fields' => 50,
    'digest_key' => env('COMMENTS_METADATA_DIGEST_KEY'),
    'schemas' => [],
],
```

Stop reusing `content.maximum_bytes` for metadata. In compatibility mode,
registered fields are validated and unknown keys stay internal; in strict mode,
unknown keys fail mutation. Update idempotency digests and revision snapshots to
use the normalized metadata order.

- [ ] **Step 4: Add a portable queryable-metadata index**

Do not edit released migrations. Add the configured Metadata Values table with
UUID ID, Comment foreign key/cascade, schema namespace, field alias, value type,
and a canonical HMAC/hash of type plus normalized scalar value. Add a unique
index on comment/schema/field and lookup index on schema/field/value hash. Store
no duplicate plaintext value. Create/update/restore synchronize registered
queryable fields inside the comment transaction; removal/anonymization deletes
their rows. Unregistered and non-queryable metadata never enters the index.
Use a domain-separated HMAC with `metadata.digest_key`, falling back to the
existing Comments idempotency key/application key. Doctor fails when no stable
key exists; changing the key requires the documented reconciliation rebuild.

- [ ] **Step 5: Add safe projections**

`CommentMetadataProjectionData` contains `namespace` and an allowlisted scalar
`values` record. Projection selects fields by audience; unregistered metadata
and fields not visible to that audience are omitted. Add an Optional list to the
four existing DTOs so unchanged comments preserve their serialized shape when
no visible schema data exists. Management does not automatically see fields
unless the schema explicitly includes Management.

- [ ] **Step 6: Replace CR-12's generic FilterSet with a safe selector**

`CommentSelectorData` contains:

```php
/** @var list<string> */
array $tags = [];
/** @var array<string, string|int|bool|null> */
array $metadataEquals = [];
?CommentStatus $status = null;
```

Metadata keys are `<namespace>.<field>` aliases. The registry normalizes and
hashes values, then applies bounded `whereExists` predicates against the
package-owned metadata index only when `queryable` is true; callers cannot pass
a JSON path. Cap tags at 20 and metadata criteria at 10. Implement
`FindLatestTargetCommentAction` through `CommentReadService`, authorization,
deterministic `created_at`/`id` ordering, and `CommentProjectionFactory`. It
returns one DTO/null and never exposes `CommentIdentity`, a model query, or a
builder.

`DeleteLatestTargetCommentAction::execute(Model $target, CommentSelectorData
$selector, CommentActorData $actor, CommentAudience $audience): bool` resolves
and authorizes the same selector, locks the deterministic newest match, applies
the package's delete lifecycle/actor rules at its current revision, and returns
false when no match exists. Lookup and deletion happen in one package-owned
transaction so a consumer never needs a Comment model just to delete a matched
workflow note.

- [ ] **Step 7: Extend Doctor and documentation**

Doctor validates schema resolution, names, type/bounds, unique storage keys,
queryability, audience declarations, Metadata Values schema/indexes, and
strict-mode compatibility. Reconciliation reports missing/stale index hashes
without displaying values. README
documents internal legacy metadata, registered safe metadata, selectors, and
why secrets/mentions do not belong in metadata.

- [ ] **Step 8: Verify CR-31**

```bash
php tools/run-package-quality.php comments
php artisan nvl:data:types:generate --fail-on-warning
composer types:check
php artisan test --compact tests/Contract/ConsumerReadinessTest.php
```

Expected: Comments passes; generated contracts include only the safe metadata
projection/selector shapes.

- [ ] **Step 9: Commit CR-31**

```bash
git add packages/nvl/comments tools/consumer-readiness.php tests/Contract/ConsumerReadinessTest.php resources/js/types
git commit -m "feat(comments): add registered metadata contracts"
```

---

### Task 2 (CR-32): Persist bounded rich documents and normalized mention references

**Files:**
- Create: `packages/nvl/comments/database/migrations/2026_08_28_000001_add_comment_documents.php`
- Create: `packages/nvl/comments/database/migrations/2026_08_28_000002_create_comment_mentions_table.php`
- Create: `packages/nvl/comments/src/Models/CommentMention.php`
- Create: `packages/nvl/comments/src/Data/Mutations/CommentDocumentData.php`
- Create: `packages/nvl/comments/src/Data/Mutations/CreateRichCommentData.php`
- Create: `packages/nvl/comments/src/Data/Mutations/UpdateRichCommentData.php`
- Create: `packages/nvl/comments/src/Data/CommentMentionReferenceData.php`
- Create: `packages/nvl/comments/src/Services/CommentDocumentNormalizer.php`
- Create: `packages/nvl/comments/src/Services/CommentMentionWriter.php`
- Create: `packages/nvl/comments/src/Actions/CreateRichCommentAction.php`
- Create: `packages/nvl/comments/src/Actions/UpdateRichCommentAction.php`
- Modify: `packages/nvl/comments/src/Definitions/Tables/CommentsTables.php`
- Modify: `packages/nvl/comments/config/comments.php`
- Modify: `packages/nvl/comments/src/Enums/CommentFormat.php`
- Modify: `packages/nvl/comments/src/Models/Comment.php`
- Modify: `packages/nvl/comments/src/Models/CommentRevision.php`
- Modify: `packages/nvl/comments/src/Actions/RestoreCommentRevisionAction.php`
- Modify: `packages/nvl/comments/src/Actions/AnonymizeCommentAction.php`
- Modify: `packages/nvl/comments/src/Services/CommentIdempotencyDigest.php`
- Create: `packages/nvl/comments/tests/Feature/CommentRichDocumentLifecycleTest.php`
- Modify: `packages/nvl/comments/tests/Feature/CommentsLifecycleV1Test.php`
- Modify: `packages/nvl/comments/tests/Feature/CommentsBehaviorRegressionTest.php`
- Modify: `packages/nvl/comments/tests/Feature/CommentsDatabaseConcurrencyTest.php`

**Interfaces:**
- Consumes: existing transactional mutation/locking/revision/idempotency lifecycle.
- Produces: `CommentFormat::RichText`, versioned document storage, and current normalized mention rows.

- [ ] **Step 1: Write failing migration and document-contract tests**

Cover fresh migration, upgrade from the four existing Comments migrations,
application-owned table names, rollback safety, allowed nodes, unknown node/type,
invalid UUID token, duplicate token, unknown resource alias, excessive depth/
blocks/nodes/bytes/mentions/resources, empty documents, and Unicode plain-text
derivation. Run database-portability cases where package CI supports them.

- [ ] **Step 2: Add forward-only schema evolution**

Do not edit the four released migrations. The first new migration adds nullable
JSON `document` to Comments and Revisions. The second creates the configured
Mentions table:

```text
id uuid primary
comment_id uuid foreign -> comments.id cascade delete
token_id uuid
resource_alias varchar(100)
resource_id varchar(255)
resource_identity_hash char(64)
label_snapshot varchar(255)
position unsigned small integer
created_at timestamp
updated_at timestamp
unique(comment_id, token_id)
index(resource_alias, resource_identity_hash)
index(comment_id, position)
```

Add `CommentsTables::Mentions` and require application-owned migrations to
declare the same logical columns. There is deliberately no foreign key to a
consumer resource. Hide raw `document` on Comment/Revision model serialization
and `resource_identity_hash` on CommentMention; public DTO construction is the
only supported transport boundary.

- [ ] **Step 3: Define document version 1 exactly**

`CommentDocumentData` carries `version: 1` and a list of blocks. The normalized
JSON schema is:

```json
{
  "version": 1,
  "blocks": [
    {
      "type": "paragraph",
      "children": [
        {"type": "text", "text": "Contact "},
        {"type": "mention", "tokenId": "uuid", "resource": "organization", "id": "opaque-id"},
        {"type": "hard_break"}
      ]
    }
  ]
}
```

Reject unknown keys, HTML, URLs, nested children, client labels/fields, and
non-scalar IDs. Normalize Unicode/newlines, preserve node order, and compute a
canonical JSON representation before idempotency hashing. After authorized
resolution, the stored normalized mention node adds server-owned
`labelSnapshot`; input validation rejects that key from clients. Revision
documents therefore retain the exact historical text needed to rebuild mention
rows even when the live resource has disappeared. Derive `body` deterministically:
text nodes are literal normalized text, mention nodes are `@` plus the resolved
label, hard breaks are `\n`, and paragraphs are separated by `\n\n`.

- [ ] **Step 4: Add dedicated rich mutation Actions**

Keep existing `CreateCommentAction`, `UpdateCommentAction`, and DTO signatures
unchanged for plain/Markdown comments. `CreateRichCommentAction` and
`UpdateRichCommentAction` accept the new DTOs, resolve mentions through CR-33's
registry contract, derive `body`, persist `format=rich_text`, `document`, and
mention rows inside the existing lock/transaction, and create one consistent
revision. Until CR-33 lands, tests bind a deterministic fake registry.

- [ ] **Step 5: Preserve lifecycle semantics**

Restore rebuilds current mention rows from the validated historical document in
the same locked transaction. Anonymization removes current mention rows and
scrubs mention IDs/labels from current and revision documents while preserving
non-identifying text according to the existing anonymization policy. Soft delete
does not destroy history. Hard/cascade deletion removes current mention rows.

- [ ] **Step 6: Prove concurrency and idempotency**

Two updates with the same expected revision yield one winner; an idempotent
retry returns the same rich comment; a reused idempotency key with a changed
document fails; rollback leaves no partial document/mention/revision state.

- [ ] **Step 7: Verify CR-32**

```bash
php tools/run-package-quality.php comments
php artisan test --compact tests/Feature/Integration/CrossPackageIntegrationTest.php
```

Expected: old plain/Markdown payloads and projections remain compatible, while
rich lifecycle tests pass on the package's supported database matrix.

- [ ] **Step 8: Commit CR-32**

```bash
git add packages/nvl/comments
git commit -m "feat(comments): add rich document persistence"
```

---

### Task 3 (CR-33): Add mention resource registry, suggestions, projections, and events

**Files:**
- Create: `packages/nvl/comments/src/Contracts/CommentMentionResourceResolver.php`
- Create: `packages/nvl/comments/src/Contracts/CommentMentionResourceAuthorization.php`
- Create: `packages/nvl/comments/src/Contracts/CommentMentionUrlResolver.php`
- Create: `packages/nvl/comments/src/Contracts/ViewerIndependentCommentMentionResource.php`
- Create: `packages/nvl/comments/src/ValueObjects/CommentMentionContext.php`
- Create: `packages/nvl/comments/src/Data/CommentMentionResourceData.php`
- Create: `packages/nvl/comments/src/Data/CommentMentionSuggestionData.php`
- Create: `packages/nvl/comments/src/Data/CommentMentionData.php`
- Create: `packages/nvl/comments/src/Data/CommentMentionChangeData.php`
- Create: `packages/nvl/comments/src/Enums/CommentMentionState.php`
- Create: `packages/nvl/comments/src/Services/CommentMentionResourceRegistry.php`
- Create: `packages/nvl/comments/src/Services/EloquentCommentMentionResourceResolver.php`
- Create: `packages/nvl/comments/src/Services/CommentMentionProjectionFactory.php`
- Create: `packages/nvl/comments/src/Actions/SuggestCommentMentionResourcesAction.php`
- Create: `packages/nvl/comments/src/Actions/ResolveCommentMentionsAction.php`
- Create: `packages/nvl/comments/src/Events/CommentMentionsChanged.php`
- Modify: `packages/nvl/comments/config/comments.php`
- Modify: `packages/nvl/comments/src/Providers/CommentsServiceProvider.php`
- Modify: `packages/nvl/comments/src/Services/CommentProjectionFactory.php`
- Modify: `packages/nvl/comments/src/Services/CommentStateReconciler.php`
- Modify: `packages/nvl/comments/src/Console/CommentsDoctorCommand.php`
- Modify: `packages/nvl/comments/src/Console/CommentsReconcileCommand.php`
- Modify: `packages/nvl/comments/src/Http/Controllers/MemberCommentsController.php`
- Modify: `packages/nvl/comments/src/Http/Controllers/CommentsManagementController.php`
- Modify: `packages/nvl/comments/routes/api.php`
- Modify: `packages/nvl/comments/tests/Feature/CommentsV1ApiProjectionTest.php`
- Create: `packages/nvl/comments/tests/Feature/CommentMentionResourceTest.php`
- Create: `packages/nvl/comments/tests/Feature/CommentMentionSecurityTest.php`
- Create: `packages/nvl/comments/tests/Feature/CommentMentionEventTest.php`
- Modify: `packages/nvl/comments/tests/Feature/CommentsDoctorCommandTest.php`
- Modify: `packages/nvl/comments/tests/Feature/CommentsModerationReconciliationTest.php`
- Modify: `packages/nvl/comments/tests/Feature/CommentsHttpContractTest.php`
- Modify: `packages/nvl/comments/tests/Feature/CommentsTypeScriptContractTest.php`
- Modify: `packages/nvl/comments/README.md`

**Interfaces:**
- Consumes: CR-32 mention inputs/rows, target/actor/audience context, and registered host resource definitions.
- Produces: bounded suggestions, batch resolution, viewer-safe projections, and after-commit mention diffs.

- [ ] **Step 1: Write failing registry and attack-surface tests**

Cover declarative Eloquent and custom resolvers; alias collision; unresolvable
classes; nonexistent fields; hidden/guarded sensitive fields; SQL metacharacters;
wildcard escaping; client-supplied class/column/URL/label; unauthorized target or
resource; missing/deleted resource; duplicate IDs; cross-tenant IDs; public cache
safety; max query/limit/batch/mention bounds; and constant query count for one
versus 25 comments.

- [ ] **Step 2: Define declarative and custom registration**

Add disabled-by-default configuration:

```php
'mentions' => [
    'enabled' => false,
    'maximum_per_comment' => 25,
    'maximum_resource_types_per_comment' => 10,
    'suggestion_limit' => 10,
    'maximum_suggestion_limit' => 20,
    'maximum_query_length' => 160,
    'maximum_batch_size' => 100,
    'resources' => [
        'organization' => [
            'model' => Organization::class,
            'searchable_fields' => ['name', 'registration_number'],
            'exposed_fields' => ['name', 'registration_number'],
            'label_field' => 'name',
            'authorization' => OrganizationMentionAuthorization::class,
            'url_resolver' => OrganizationMentionUrlResolver::class,
            'public' => false,
        ],
        'candidacy' => [
            'resolver' => CandidacyMentionResourceResolver::class,
        ],
    ],
],
```

All aliases and fields are server configuration. The Eloquent resolver selects
only ID, label, and exposed fields; escapes wildcard search; uses deterministic
label/ID ordering; applies the resource authorization boundary before returning
anything; and caps every query. A custom resolver must return the same DTOs and
enforce the same bounds.

- [ ] **Step 3: Implement suggestion and batch-resolution Actions**

Signatures:

```php
public function execute(
    Model $target,
    CommentActorData $actor,
    CommentAudience $audience,
    string $resource,
    string $query,
    int $limit = 10,
): Collection;

public function execute(
    Comment|string $comment,
    CommentActorData $actor,
    CommentAudience $audience,
): Collection;
```

Both authorize target/comment access before resource work. The projection
factory groups references by alias and resolves each alias in one bounded batch.
No resolver may return a builder/model. Duplicate IDs are de-duplicated for
lookup and expanded back to token order.

- [ ] **Step 4: Define safe projection/tombstone behavior**

`CommentMentionData` contains token ID, alias, state, label snapshot, nullable
authorized resource ID/current label, allowlisted scalar fields, and nullable
package-produced URL. States are `resolved`, `missing`, and `restricted`.
Restricted/missing resources expose no live ID, fields, or URL. Public DTOs only
resolve a resource whose resolver implements
`ViewerIndependentCommentMentionResource`; otherwise they expose the immutable
text snapshot only. The projection factory always constructs a viewer-shaped
document from normalized nodes and mention DTOs; it never serializes the raw
stored document because that contains opaque resource IDs. Add Optional
document/mention collections to existing comment DTOs and omit both on
tombstones.

- [ ] **Step 5: Emit after-commit mention diffs**

`CommentMentionsChanged` contains comment ID, target alias/ID reference,
revision, and bounded added/removed `CommentMentionChangeData` lists with alias,
opaque ID, and token ID only. It implements after-commit dispatch. Updates diff
by alias/resource identity, ignore token reordering and unchanged mentions, and
emit no duplicate event for an idempotent retry. Notification channels, user
lookup, and copy remain consumer-owned.

- [ ] **Step 6: Add package HTTP seams without forcing them on consumers**

Member/management controllers add rich store/update methods and suggestion
endpoints under their existing disabled-by-default route groups. Routes use the
existing auth middleware plus configured throttle. There is no public suggestion
endpoint. Hosts with package routes disabled call Actions from their controllers.
Update OpenAPI contracts and generated TypeScript.

- [ ] **Step 7: Extend Doctor and reconciliation**

Doctor validates resource aliases, resolver/model exclusivity, model/table/ID,
field allowlists, label membership, authorization/URL resolver container
resolution, public marker, bounds, route middleware, and Mentions schema.
Reconcile detects document/row token drift, duplicate resource identities,
invalid snapshots, orphan rows, and body projection drift; `--repair` rebuilds
current rows/body only after revalidation and never resolves unauthorized live
data into historical revisions.

- [ ] **Step 8: Verify CR-33**

```bash
php tools/run-package-quality.php comments
php artisan nvl:data:types:generate --fail-on-warning
composer contracts:check
composer types:check
php artisan test --compact tests/Contract/CommentsConsumerWorkflowTest.php tests/Contract/ConsumerReadinessTest.php
```

Expected: package/API/security/event/reconcile tests pass; public cached
projections contain no actor-specific resource data.

- [ ] **Step 9: Commit CR-33**

```bash
git add packages/nvl/comments tools/consumer-readiness.php tests/Contract resources/js/types
git commit -m "feat(comments): add registered rich mentions"
```

---

### Task 4 (CR-34): Adopt metadata schemas and mention resources in KPO

**Files (KPO repository):**
- Modify: `config/comments.php`
- Create: `app/Support/Comments/Metadata/CandidacyWorkflowMetadataSchema.php`
- Create: `app/Support/Comments/Mentions/KpoUserMentionResourceResolver.php`
- Create: `app/Support/Comments/Mentions/OrganizationMentionResourceResolver.php`
- Create: `app/Support/Comments/Mentions/EventMentionResourceResolver.php`
- Create: `app/Support/Comments/Mentions/CandidacyMentionResourceResolver.php`
- Create: `app/Support/Comments/Mentions/KpoMentionResourceAuthorization.php`
- Modify: `Modules/Kpo/app/Services/Candidacy/CandidacyCommentService.php`
- Modify: `Modules/Kpo/app/Actions/Candidacy/SendCandidateCommentAction.php`
- Modify: `Modules/Kpo/app/Data/Mutations/SendCandidateCommentMutationData.php`
- Modify: `Modules/Kpo/tests/Feature/CandidacyLifecycleActionsTest.php`
- Create: `tests/Feature/Package/NvlCommentMetadataAndMentionsTest.php`

**Interfaces:**
- Consumes: CR-31 through CR-33 and KPO's existing Comment target/authorization boundary.
- Produces: no KPO query of Comments internals and initial policy-scoped User/Organization/Event/Candidacy mention resources.

- [ ] **Step 1: Freeze current candidacy workflow behavior**

Extend tests for administrative/system actor selection, private visibility,
approved creation status, latest deterministic body, event-specific removal,
missing note, loaded/unloaded relations, revision conflicts, and denial. Record
the current direct `Comment::query`, relation JSON predicates, `status_hash`, and
`CommentIdentity` imports as expected audit findings before migration.

- [ ] **Step 2: Register the existing KPO workflow metadata**

`CandidacyWorkflowMetadataSchema` owns the three existing storage keys. Make
`workflow_event` a bounded queryable string visible to Management; keep the two
user IDs internal unless a concrete KPO projection requires them. Enable strict
metadata only after a data audit proves no other KPO keys exist.

- [ ] **Step 3: Replace direct Comments persistence reads**

Use `FindLatestTargetCommentAction` with tags/status for `latestBody`, and
`DeleteLatestTargetCommentAction` with the registered `workflow_event` selector
for deletion. Delete KPO imports of `CommentStatus`, `CommentIdentity`, and
package query internals that are no longer needed.

- [ ] **Step 4: Register initial KPO mention resources**

Use custom resolver classes for User and Candidacy because their visibility is
role/tenant sensitive. Use the declarative Eloquent resolver for Organization
or Event only if their existing policies can be expressed by the authorization
contract and every exposed field is already safe in the corresponding KPO UI;
otherwise keep custom resolvers. Prefer adapting KPO's existing
`SearchOrganizationsAction` and `GetEventSuggestionsAction` instead of writing a
second query. Search by existing KPO business fields, return only
ID/label/approved display fields, use named routes for URLs, and cap results
through package configuration.

- [ ] **Step 5: Add one end-to-end rich mention proof**

Create and edit a private candidacy comment containing two resource types.
Assert server-derived body, normalized rows, member/management projections,
unauthorized tombstones, missing-resource history, after-commit added/removed
event payloads, revision restore, anonymization, and constant query counts. KPO
may keep its current plain comment UI; the proof can call package Actions until
the product chooses an editor.

- [ ] **Step 6: Run KPO's focused gate**

```bash
php artisan config:cache
php artisan nvl:comments:doctor --strict --format=json
php artisan nvl:comments:reconcile --dry-run --format=json
php artisan nvl:suite:consumer-audit --strict --format=json
php artisan test --compact Modules/Kpo/tests/Feature/CandidacyLifecycleActionsTest.php tests/Feature/Package/NvlCommentMetadataAndMentionsTest.php
php artisan config:clear
```

Expected: no unallowlisted KPO Comments model query/write/table/hash finding and
no raw metadata or unauthorized resource field in serialized output.

- [ ] **Step 7: Commit CR-34 in reversible KPO waves**

Commit metadata schema/direct-query removal separately from mention registration
and the rich proof. Do not enable a user-facing editor in the same change.

### Workstream acceptance gate

- [ ] Existing plain/Markdown comments remain wire-compatible and pass their full package tests.
- [ ] KPO can query workflow metadata only through registered package selectors.
- [ ] Consumers can register a simple resource with model/search/display fields or a complex resource resolver without exposing either choice to clients.
- [ ] Rich mentions survive create, update, revision, restore, delete, anonymization, missing resources, and access loss.
- [ ] Suggestion, resolution, projection, event, and reconciliation paths are authorized, bounded, batch-safe, and database-portable.
- [ ] Public cached responses never vary by actor or expose protected resource IDs/fields.
