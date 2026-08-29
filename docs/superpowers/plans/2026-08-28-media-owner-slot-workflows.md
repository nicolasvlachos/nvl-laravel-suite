# Media Owner-Slot Workflows Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move KPO's staged document attachment, one-to-one replacement, clearing, and copying invariants into Media-owned APIs.

**Architecture:** Four actor-aware Actions delegate to a shared owner-slot workflow service. Existing Media locks, association Actions, lifecycle deletion, local materialization, URL projection, and after-commit file effects remain authoritative; a small forward-only operation ledger provides optional idempotency.

**Tech Stack:** PHP 8.4, Laravel 13 Eloquent/filesystem/cache locks, Spatie Laravel Data, Pest 4, SQLite/PostgreSQL/MySQL/MariaDB.

**Spec:** `docs/superpowers/specs/2026-08-28-consumer-readiness-v2-design.md`

## Global Constraints

- Owner parameters are persisted `Model&HasMedia` instances with scalar keys.
- Slot names must resolve through `HasMedia::getMediaSlot()`; arbitrary collections are rejected.
- Read requires `MediaAbility::View`; replace/clear/copy require `Associate`; adopting another actor's staging association additionally requires `ManageStaging`.
- Existing media is validated against slot MIME, size, visibility, sharing, single-file, and availability rules before mutation.
- A slot with a custom `fileAcceptor` cannot accept an existing staged record because the original `UploadedFile` is unavailable; callers upload directly into that slot.
- Shared media is detached, never deleted by slot replacement; orphaned exclusive media is deleted through `DeleteMediaAction` after the durable association transition.
- File deletion/copy cleanup follows existing after-commit/after-rollback scheduling.
- Idempotency keys are optional UUIDs; the same key plus same request returns the completed result, while a different request hash is rejected.

---

### Task 1 (CR-17a): Add owner-slot operation identity and projection support

**Files:**
- Create: `packages/nvl/media/database/migrations/2026_08_28_000000_create_media_owner_slot_operations_table.php`
- Create: `packages/nvl/media/src/Models/MediaOwnerSlotOperation.php`
- Create: `packages/nvl/media/src/Enums/MediaOwnerSlotOperationType.php`
- Create: `packages/nvl/media/src/Enums/MediaOwnerSlotOperationStatus.php`
- Create: `packages/nvl/media/src/Services/MediaOwnerSlotIdempotency.php`
- Modify: `packages/nvl/media/src/Definitions/Tables/MediaTables.php`
- Modify: `packages/nvl/media/src/Enums/MediaAbility.php`
- Modify: `packages/nvl/media/src/Services/MediaLibraryItemDataFactory.php`
- Create: `packages/nvl/media/tests/Feature/MediaOwnerSlotWorkflowTest.php`
- Modify: `packages/nvl/media/tests/Feature/MediaDoctorTest.php`

**Interfaces:**
- Consumes: existing Media table configuration, UUID conventions, model factories, and `MediaLibraryItem`.
- Produces: idempotency begin/complete/fail boundary, `ManageStaging` ability, and a projection method that accepts a slot association.

- [x] **Step 1: Write failing schema and idempotency tests**

```php
$claim = app(MediaOwnerSlotIdempotency::class)->begin(
    key: $key,
    actor: $actor,
    owner: $owner,
    slot: 'document',
    operation: MediaOwnerSlotOperationType::Replace,
    payload: ['media_id' => $media->id],
);

expect($claim->replayed)->toBeFalse();
```

Add assertions for exact replay, payload mismatch, owner/actor mismatch,
in-progress contention, failed retry, nullable clear results, expired-operation
pruning, configurable table/connection, and Doctor detection.

- [x] **Step 2: Run the new workflow test and verify missing schema/classes fail**

Run: `vendor/bin/pest --configuration=packages/nvl/media/phpunit.xml.dist --compact packages/nvl/media/tests/Feature/MediaOwnerSlotWorkflowTest.php packages/nvl/media/tests/Feature/MediaDoctorTest.php`

Expected: FAIL because the operation ledger does not exist.

- [x] **Step 3: Add the forward migration and model**

The table contains UUID `id`, UUID `idempotency_key`, actor type/ID, owner type/ID,
slot, operation, SHA-256 `request_hash`, status, nullable result media ID, nullable
immutable result projection, nullable failure code, completed/failed/created/updated
timestamps, and a unique index on `idempotency_key`. Add lookup indexes for
owner+slot and created time. The model uses the Media configured connection/table
and exposes casts only; it contains no workflow logic.

- [x] **Step 4: Implement idempotency claims**

`begin()` validates a UUID key, canonicalizes the scalar payload with recursive
key sorting, hashes actor/owner/slot/operation/payload, and uses insert-or-lock
semantics portable across all supported databases. Return a readonly claim with
operation ID, replay flag, nullable result media ID, and nullable immutable
result projection. `complete()` and `fail()` require the claimed request hash
and transition once. Store stable failure codes and completed result projections,
never request payloads or exception messages. Retention defaults to seven days
and pruning uses bounded chunks.

- [x] **Step 5: Add `ManageStaging` and slot-aware projection support**

Append `MediaAbility::ManageStaging`. Add a factory method that receives a
loaded Media plus the selected `MediaAssociation`, sets the DTO `collection`
from that association, and still uses the existing safe URL resolver. Existing
library projection behavior remains unchanged.

- [x] **Step 6: Run migration, Doctor, and portability-focused tests**

Run: `vendor/bin/pest --configuration=packages/nvl/media/phpunit.xml.dist --compact packages/nvl/media/tests/Feature/MediaOwnerSlotWorkflowTest.php packages/nvl/media/tests/Feature/MediaDoctorTest.php packages/nvl/media/tests/Feature/MediaArchitectureTest.php`

Expected: PASS.

Run: `php artisan nvl:data:types:generate --fail-on-warning`

Run: `composer types:check`

Expected: PASS with `MediaAbility::ManageStaging` generated.

- [x] **Step 7: Commit CR-17a**

```bash
git add packages/nvl/media/database/migrations/2026_08_28_000000_create_media_owner_slot_operations_table.php packages/nvl/media/src/Models/MediaOwnerSlotOperation.php packages/nvl/media/src/Enums packages/nvl/media/src/Services/MediaOwnerSlotIdempotency.php packages/nvl/media/src/Definitions/Tables/MediaTables.php packages/nvl/media/src/Services/MediaLibraryItemDataFactory.php packages/nvl/media/tests/Feature/MediaOwnerSlotWorkflowTest.php packages/nvl/media/tests/Feature/MediaDoctorTest.php resources/js/types
git commit -m "feat(media): add owner-slot operation identity"
```

### Task 2 (CR-17b): Add owner-slot reads and atomic replacement

**Files:**
- Create: `packages/nvl/media/src/Services/MediaOwnerSlotResolver.php`
- Create: `packages/nvl/media/src/Services/MediaStagingPolicy.php`
- Create: `packages/nvl/media/src/Services/MediaOwnerSlotWorkflow.php`
- Create: `packages/nvl/media/src/Actions/GetOwnerMediaSlotAction.php`
- Create: `packages/nvl/media/src/Actions/ReplaceOwnerMediaSlotAction.php`
- Modify: `packages/nvl/media/tests/Feature/MediaOwnerSlotWorkflowTest.php`
- Modify: `packages/nvl/media/tests/Feature/MediaEventContractTest.php`

**Interfaces:**
- Consumes: CR-17a idempotency/projection, `MediaAuthorization`, `AttachMediaAction`, `DetachMediaAction`, `DeleteMediaAction`, `MediaMutationLock`, and registered `MediaSlot` definitions.
- Produces: the read and replace APIs from the design spec.

- [x] **Step 1: Write failing authorization and replacement tests**

```php
$result = app(ReplaceOwnerMediaSlotAction::class)->execute(
    actor: $actor,
    owner: $owner,
    slot: 'document',
    mediaId: $staged->id,
    idempotencyKey: (string) Str::uuid(),
);

expect($result)->toBeInstanceOf(MediaLibraryItem::class)
    ->and($result->id)->toBe($staged->id)
    ->and($owner->fresh()->getFirstMedia('document')->id)->toBe($staged->id);
```

Add tests for unknown slots, unsaved owners, unavailable/deleted media,
MIME/size rejection, custom acceptor rejection, denied actor, uploader mismatch,
actor-owned staging association, administrative `ManageStaging`, same-media
no-op, single-file replacement, shared old media detach, exclusive orphan
deletion, non-orphan preservation, rollback, event ordering, and exact replay.

- [x] **Step 2: Run the workflow test and verify missing Actions fail**

Run: `vendor/bin/pest --configuration=packages/nvl/media/phpunit.xml.dist --compact packages/nvl/media/tests/Feature/MediaOwnerSlotWorkflowTest.php`

Expected: FAIL because the resolver/workflow/Actions do not exist.

- [x] **Step 3: Implement slot and staging policy resolution**

`MediaOwnerSlotResolver` validates owner persistence, resolves the registered
slot, canonical owner type/ID, and current association(s). It rejects multiple
rows in a single-file slot as corrupt state. `MediaStagingPolicy` accepts:

- an available asset with no associations whose uploader type/ID equals actor;
- an asset whose every association belongs to actor type/ID;
- a public shared asset when `Reuse` is allowed;
- another actor's staged asset only when `ManageStaging` is allowed.

System actors require `system: true`; anonymous actor data cannot adopt staged
private media. Compare all identifiers as normalized strings.

- [x] **Step 4: Implement read and replace workflow**

Read signature:

```php
public function execute(MediaActorData $actor, Model&HasMedia $owner, string $slot): ?MediaLibraryItem
```

Authorize `View`, load the exact association plus translations, variations, and
association count, then use the slot-aware factory.

Replace begins optional idempotency, authorizes `Associate`, validates candidate
against the slot, acquires mutation locks in sorted media-ID order, and opens one
transaction. Lock candidate/current Media rows and owner-slot associations;
attach candidate with metadata `['slot' => $slot]`; detach only candidate
associations approved as staging; detach the previous slot association; and
delete a now-orphaned exclusive previous asset through the existing lifecycle
after the durable transition. On success, complete idempotency and return the
fresh projection; on exception, mark the claim failed and rethrow.

- [x] **Step 5: Prove concurrency and file-effect safety**

Add two-worker tests for competing replacements on PostgreSQL and the package's
available MySQL concurrency job. Assert one final association, no missing file
for the winner, no deletion before outer commit, and rollback preservation of
both database rows and files.

- [x] **Step 6: Run focused Media gates**

Run: `vendor/bin/pest --configuration=packages/nvl/media/phpunit.xml.dist --compact packages/nvl/media/tests/Feature/MediaOwnerSlotWorkflowTest.php packages/nvl/media/tests/Feature/MediaEventContractTest.php packages/nvl/media/tests/Unit/MediaSlotModeTest.php`

Expected: PASS.

- [x] **Step 7: Commit CR-17b**

```bash
git add packages/nvl/media/src/Services/MediaOwnerSlotResolver.php packages/nvl/media/src/Services/MediaStagingPolicy.php packages/nvl/media/src/Services/MediaOwnerSlotWorkflow.php packages/nvl/media/src/Actions/GetOwnerMediaSlotAction.php packages/nvl/media/src/Actions/ReplaceOwnerMediaSlotAction.php packages/nvl/media/tests/Feature/MediaOwnerSlotWorkflowTest.php packages/nvl/media/tests/Feature/MediaEventContractTest.php
git commit -m "feat(media): replace owner media slots safely"
```

### Task 3 (CR-18a): Add slot clearing and copying

**Files:**
- Create: `packages/nvl/media/src/Actions/ClearOwnerMediaSlotAction.php`
- Create: `packages/nvl/media/src/Actions/CopyOwnerMediaSlotAction.php`
- Modify: `packages/nvl/media/src/Services/MediaOwnerSlotWorkflow.php`
- Modify: `packages/nvl/media/tests/Feature/MediaOwnerSlotWorkflowTest.php`

**Interfaces:**
- Consumes: CR-17 workflow, `MediaLocalFileMaterializer`, Media ingestion/upload pipeline, and operation ledger.
- Produces: clear and copy APIs from the design spec.

- [x] **Step 1: Write failing clear/copy lifecycle tests**

```php
$copy = app(CopyOwnerMediaSlotAction::class)->execute(
    actor: $actor,
    owner: $destination,
    slot: 'document',
    sourceMediaId: $source->id,
    idempotencyKey: (string) Str::uuid(),
);

expect($copy->id)->not->toBe($source->id)
    ->and($copy->filename)->toBe($source->filename);
```

Add tests for clear-empty no-op, clear replay, shared detach, exclusive delete,
source view denial, missing source object, source checksum mismatch, private
signed URL non-use, metadata/tag allowlist, uploader attribution, exclusive
destination identity, copy rollback cleanup, and copy replay.

- [x] **Step 2: Run the workflow test and verify missing Actions fail**

Run: `vendor/bin/pest --configuration=packages/nvl/media/phpunit.xml.dist --compact packages/nvl/media/tests/Feature/MediaOwnerSlotWorkflowTest.php`

Expected: FAIL because clear/copy Actions do not exist.

- [x] **Step 3: Implement slot clear**

Signature:

```php
public function execute(
    MediaActorData $actor,
    Model&HasMedia $owner,
    string $slot,
    ?string $idempotencyKey = null,
): void
```

Resolve/authorize, begin optional idempotency, lock owner-slot associations,
detach the exact association, and route orphan exclusive media through
`DeleteMediaAction`. A missing attachment is a successful no-op. Complete the
ledger with a null result.

- [x] **Step 4: Implement slot copy through canonical ingestion**

Authorize `View` on source and `Associate` on destination. Materialize the
source through `MediaLocalFileMaterializer`, verify its existing digest and
size, create an `UploadedFile`, then call the existing upload/association path
with the destination slot. Preserve filename, safe scalar metadata keys, and
tags; never preserve provider payloads, redaction metadata, storage hash/path,
association metadata, or visibility contrary to the destination slot. Attribute
the copy to the actor. Release the materialized local file in `finally`.

- [x] **Step 5: Run Media quality**

Run: `php tools/run-package-quality.php media`

Expected: PASS, including dependency analysis and security audit.

- [x] **Step 6: Commit CR-18a**

```bash
git add packages/nvl/media/src/Actions/ClearOwnerMediaSlotAction.php packages/nvl/media/src/Actions/CopyOwnerMediaSlotAction.php packages/nvl/media/src/Services/MediaOwnerSlotWorkflow.php packages/nvl/media/tests/Feature/MediaOwnerSlotWorkflowTest.php
git commit -m "feat(media): clear and copy owner media slots"
```

Completed in `ad289df`. The final implementation also covers split-ledger
immutable checkpoints, consumer-owned outer transactions, rollback recovery,
under-lock claim fencing, explicit metadata allowlisting, and displaced replay
without remutating a newer slot occupant. Independent review reported no
findings and Ready: Yes. Media quality passed 990 tests (987 passed, 3 skipped)
with 3,219 assertions, Pint, and PHPStan.

### Task 4 (CR-18b): Operationalize and document owner-slot workflows

**Files:**
- Create: `packages/nvl/media/src/Console/Commands/PruneMediaOwnerSlotOperationsCommand.php`
- Modify: `packages/nvl/media/src/Providers/MediaServiceProvider.php`
- Modify: `packages/nvl/media/src/Services/MediaDoctor.php`
- Modify: `packages/nvl/media/src/Console/Commands/MediaDoctorCommand.php`
- Modify: `packages/nvl/media/config/media.php`
- Modify: `packages/nvl/media/tests/Feature/MediaDoctorTest.php`
- Modify: `packages/nvl/media/README.md`
- Modify: `packages/nvl/media/UPGRADING.md`
- Modify: `tools/consumer-readiness.php`
- Modify: `tests/Contract/ConsumerReadinessTest.php`

**Interfaces:**
- Consumes: CR-17/18 operation ledger and Actions.
- Produces: bounded pruning, Doctor checks, consumer guidance, and release evidence.

- [ ] **Step 1: Write failing command and Doctor tests**

```php
expect(Artisan::call('nvl:media:owner-slots:prune', [
    '--days' => 7,
    '--chunk' => 100,
]))->toBe(0);
```

Assert production requires `--force` only when deleting non-expired rows,
normal pruning never exceeds chunk 1,000, and Doctor checks schema/table/index,
retention configuration, lock store, and registered owner slot integrity.

- [ ] **Step 2: Run focused operational tests and verify command/checks fail**

Run: `vendor/bin/pest --configuration=packages/nvl/media/phpunit.xml.dist --compact packages/nvl/media/tests/Feature/MediaDoctorTest.php packages/nvl/media/tests/Feature/MediaOwnerSlotWorkflowTest.php`

Expected: FAIL because pruning and Doctor checks are absent.

- [ ] **Step 3: Implement bounded pruning and diagnostics**

Add `owner_slots.idempotency.retention_days` default 7 and `prune_chunk` default
500 with hard maximum 1,000. Delete only completed/failed rows older than the
cutoff in deterministic ID chunks. Doctor reports missing table/indexes,
invalid bounds, and a cache store without atomic locks.

- [ ] **Step 4: Document the KPO replacement pattern**

Include exact slot declaration and Action calls:

```php
$this->addMediaSlot('document')
    ->oneToOne()
    ->acceptsMimeTypes(['application/pdf'])
    ->maxFileSize(4 * 1024 * 1024);

$document = $replaceOwnerMediaSlot->execute($actor, $report, 'document', $mediaId, $requestId);
```

Explain staging rules, custom acceptor limitation, shared versus exclusive
cleanup, idempotency, queue/file-effect behavior, and migration ownership.

- [ ] **Step 5: Run package and suite contracts**

Run: `php tools/run-package-quality.php media`

Run: `php artisan test --compact tests/Contract/ConsumerReadinessTest.php tests/Feature/Integration/CrossPackageIntegrationTest.php`

Run: `composer contracts:check` and `composer types:check`.

Expected: all PASS.

- [ ] **Step 6: Commit CR-18b**

```bash
git add packages/nvl/media/src/Console/Commands/PruneMediaOwnerSlotOperationsCommand.php packages/nvl/media/src/Providers/MediaServiceProvider.php packages/nvl/media/src/Services/MediaDoctor.php packages/nvl/media/src/Console/Commands/MediaDoctorCommand.php packages/nvl/media/config/media.php packages/nvl/media/tests packages/nvl/media/README.md packages/nvl/media/UPGRADING.md tools/consumer-readiness.php tests/Contract/ConsumerReadinessTest.php
git commit -m "docs(media): operationalize owner slot workflows"
```

### Workstream acceptance gate

- [ ] Run `php tools/run-package-quality.php media`.
- [ ] Run Media PostgreSQL and MySQL concurrency jobs that include owner-slot replacement.
- [ ] Run `composer contracts:check` and `composer types:check`.
- [ ] Migrate one KPO document model to the four Actions and run its full lifecycle test before touching the remaining models.
- [ ] Confirm strict consumer audit removes the finding for package-owned Media lifecycle logic without adding a suppression.
