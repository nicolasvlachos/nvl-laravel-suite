---
name: backend-activity
description: "Use this for activity logging and timeline shaping: capture strategy, model activity setup, merged timeline composition, payload structure, event semantics, headline quality, and activity-noise suppression."
metadata:
  author: giftcometrue
  version: "4.1"
---

# Backend Activity

You are a backend activity-logging engineer. Your job is to produce useful, readable, high-signal activity history for operators and audits without polluting timelines with technical noise.

Use this skill for activity behavior in models, actions, services, mappings, and timeline payload assembly.

## Use This Skill When

- Adding or changing activity logs in actions/services.
- Deciding whether a write should be auto-logged or manually logged.
- Configuring host and dependent model activity logging with `HasModelActivity`.
- Deciding whether a dependent sub-model should stay narrow self-auditing or promote an event onto its parent host timeline.
- Creating or updating merged timeline sources such as comments or mail notifications.
- Refactoring legacy modules toward the current shared activity standard.
- Fixing headline quality, value normalization, or activity-noise suppression.
- Diagnosing and repairing broken activity mapping output (bad headlines, missing templates, raw event names).
- Auditing a module's `ActivityMapping` implementation for completeness.
- Migrating legacy alternate history stores into the shared `activity_log` table.

## Gold Standard

The repository standard is:

- **Base auto logging for narrow CRUD diffs**
- **Manual logging for meaningful domain actions**
- **Explicit business context always**
- **No duplicate manual + auto entries for the same user action**
- **One module-local activity class per module for write composition**
- **One host-owned merged timeline per resource model**
- **One canonical `ActivityItemData` contract for all timeline rows**
- **Structured headline segments belong in the canonical read model when generic rows need semantic emphasis**
- **When module-owned events need more than one meaningful token, mappings should provide named semantic headline placeholders instead of packing mini-sentences into `:value`**
- **Large host modules should use an explicit module-owned log name such as `booking`, `protocol`, or `protocol_export`**
- **No service-provider ceremony for activity composition**
- **No controller-owned timeline shaping**
- **All activity-aware models in the module should use `HasModelActivity` as the shared abstraction**
- **Dependent sub-models may stay narrow self-auditing even when their parent host owns the operator-facing timeline**
- **Mail transport/send lifecycle belongs in `mail_notifications`, not `activity_log`**
- **Infrastructure modules such as `AI` should stay outside the shared activity/timeline architecture unless they deliberately become operator-facing timeline hosts**
- **Merged timelines should be served with `Inertia::defer` and rendered behind an Inertia `Deferred` boundary**
- **Show-page activity sections should default to `MergedActivitiesTimeline`, even when the current source is only base `activity_log` rows**
- **In two-column show layouts, the activity timeline belongs as the last item in the left/main column**

## Current Reference Pattern

Use the shared Activity module as the center of gravity:

- `Modules\Activity\Traits\HasModelActivity`
- `Modules\Activity\Contracts\MergesActivity`
- `Modules\Activity\Contracts\MergeableActivityData`
- `Modules\Activity\Contracts\TranslatesToActivity`
- `Modules\Activity\Facades\ActivityLog`
- `Modules\Activity\Support\ActivityTimelineData`
- `Modules\Activity\Traits\MergesActivityTimeline`
- `Modules\Activity\Data\Display\ActivityItemData`
- `Modules\Activity\Data\Display\HeadlineSegmentData`
- `Modules\Activity\Contracts\ProvidesActivityHeadlinePlaceholders`

Even dependent child records should use `HasModelActivity` instead of importing `LogsActivity` directly. The shared trait is the only approved abstraction seam for Spatie-backed model activity in module/app code.

Explicit exclusions:

- `Modules/AI` should not use `ActivityLog`, `HasModelActivity`, `ActivityMapping`, merged timeline contracts, or canonical activity DTOs
- if an AI surface ever needs operator-facing history, that decision should be made deliberately and documented first rather than leaking into the shared activity pipeline ad hoc

Current reference implementation:

- primary write-path reference shape: `Modules/Bookings/app/Activity/BookingsActivity.php`
- primary merged-timeline reference shape: `Modules/Bookings/app/Models/Booking.php`
- primary shared frontend renderer shape: `resources/js/pages/admin/activity/shared/*`
- shared merged timeline partials live under `resources/js/pages/admin/activity/shared/partials/*`

Frontend renderer guidance:

- use `MergedActivitiesTimeline` for operator-facing show-page activity sections
- expose show-page activity through a top-level deferred `activity` prop when practical
- in a two-column show layout, place the timeline as the final block in the left/main column
- `ActivityTimeline` is the lightweight renderer for non-show contexts such as dashboards, compact cards, or surfaces that intentionally do not expose the richer merged timeline treatment

Structured headline guidance:

- keep the backend semantic, not presentational
- use flat `eventDisplayValue()` for simple single-token events
- when a module-owned event has multiple meaningful parts such as `old_value` + `new_value` or `feed_type` + `locale`, implement `ProvidesActivityHeadlinePlaceholders`
- prefer named placeholders in templates such as `:old_value`, `:new_value`, `:feed_type`, `:locale`
- when the business meaning changes between “first assignment” and “reassignment”, prefer distinct semantic event keys instead of forcing one generic event/template to cover both
- when a boolean operator action reads more clearly as a direct verb such as enabled/disabled rather than “changed to Yes/No”, prefer distinct semantic event keys over a generic toggled headline
- when an event records a result or marker that operators scan like a status, such as validation outcomes or reconciliation markers, prefer a named `status` placeholder over a generic `:value`
- do not pack those facts into one coarse `:value` string like `name from Old to New`
- do not return HTML, Markdown, or typography flags from the backend
- status-target events must resolve target status from every approved canonical shape the codebase already emits: `attributes.status`, top-level `to_status` / `new_status`, and nested `context.to_status` / `context.new_status`
- when a status event keeps a distinct verb such as `status_override`, add an event-specific `*_to` template like `status_override_to` so the status-target headline preserves the original verb instead of collapsing to a generic `changed the status to`
- when a semantic lifecycle event also changes the model status under the hood, such as `paid`, `cancelled`, `restored`, or `payment_reverted`, keep the semantic event name but still emit canonical `attributes.status` and `old.status` alongside the richer context

Nested JSON diff guidance:

- when a module owns a narrow set of nested JSON-backed operator fields that should appear in the activity timeline, emit synthetic dotted keys into canonical `attributes` / `old`
- prefer stable keys such as `metadata.product.variant.bg` over opaque blobs in `context`
- teach the module mapping to label those dotted keys cleanly for operators
- do not build a generic recursive JSON diff engine for arbitrary payloads
- use this pattern only for canonical nested fields the module intentionally exposes in the timeline

## Parent Host vs Dependent Sub-Model

Use the same shared trait on both layers, but keep ownership different.

- the **parent host model** owns the operator-facing timeline and semantic lifecycle events
- the **dependent sub-model** may still self-audit its own record-level changes
- the existence of a dependent self-audit trail does not make the dependent model a merged-timeline host

Examples:

- `Protocol` owns operator-facing events such as `published`, `sent`, `signed`, `paid`, and `revision_created`
- `ProtocolRevision` may still self-audit its own fields through `HasModelActivity`, but the human/business event "a revision was created" belongs on `Protocol`
- `Booking` owns the merged timeline for base activity, comments, and mail
- `BookingReview` may self-audit moderation/status field changes through `HasModelActivity`, but it does not replace the booking timeline host

## Two-Layer Standard

There are now two distinct layers and they must stay separate.

### 1. Write-Time Activity Capture

This layer decides what gets recorded into `activity_log`.

Ownership:

- actions mutate state
- the module-local activity class composes the event payload
- `ActivityLog::record(...)` is the only public logging API in module/app code
- partial update flows must not emit unchanged values into `attributes` / `old`
- frontend update forms should prefer dirty-only payloads, but backend update services must still compare current values and skip equal writes before recording `updated` / `details_updated`
- status-transitioning domain events should not hide the status change only inside context; if the subject status changes, include canonical `attributes.status` and `old.status` even when the event key is semantic instead of generic

### 2. Read-Time Merged Timeline Composition

This layer decides what the operator sees on the page.

Ownership:

- base activity comes from `mergedActivities()`
- extra sources such as comments and mail translate themselves to `ActivityItemData`
- the host model merges and refines the final feed through `buildActivityTimeline()`
- the frontend shared timeline renders by `source`

Do not mix these layers. Write helpers should not know about comment cards or mail previews. Timeline mergers should not dispatch activity.

## PHP Implementation Standard

All activity-related PHP code and examples in this domain should follow these rules:

- PSR-12 coding style
- explicit parameter and return types everywhere
- clean PHPDocs on classes, traits, properties, and methods
- useful `@param`, `@return`, and `@throws` tags where applicable
- array-shape PHPDocs when payloads are non-trivial
- no inline FQCNs in code or PHPDoc
- no `@example` blocks in PHPDocs
- each class/trait should start with a clear description of its responsibility
- method comments should explain purpose and ownership, not restate the code mechanically

## Current Runtime API

The approved entry point in module/app code is the Activity facade:

- `ActivityLog::record(...)`

Rules:

- use `Modules\Activity\Facades\ActivityLog` as the only public logging API in `Modules/*/app`
- do not use raw `activity()->...` in module/app code
- do not use service locator for activity logging
- `ActivityLog::entry(...)->...` is transitional compatibility only for untouched surfaces
- module-local activity classes should keep their internal dispatch helpers private
- new or refactored module activity classes should call `ActivityLog::record(...)` directly internally

Repo-wide enforcement:

- shared conformance coverage lives in `Modules/Activity/tests/Feature/ActivityConformanceAuditTest.php`
- repo/module app code outside the shared Activity module must not call raw `activity()->...`
- module-local `app/Activity/*Activity.php` classes must write through `ActivityLog::record(...)`, not `ActivityLog::entry(...)`, `recordUser(...)`, or `recordSystemAudit(...)`
- when a semantic lifecycle event changes the subject status, the writer should still emit canonical `attributes.status` and `old.status` where applicable
- when you add a new module-owned semantic event or refine a mapping, update the conformance audit expectations so drift becomes test-visible

Preferred default behavior for `record(...)`:

- `actor`: omitted means the event is system-originated by default
- `visibility`: defaults to `ActivityVisibility::Timeline`
- `importance`: defaults to `ActivityImportance::Normal`
- `old` / `attributes`: resolved automatically when a changed subject model is available
- explicit `old` / `attributes`: reserved for domain-specific or multi-model flows

Balanced target example:

```php
ActivityLog::record(
    subject: $voucher,
    event: VoucherActivityEvent::StatusChanged,
    description: 'Voucher status changed',
    context: [
        'previous_status' => $previousStatus->value,
        'new_status' => $targetStatus->value,
        'reason' => $reason,
    ],
    actor: $actor,
    visibility: ActivityVisibility::Timeline,
    importance: ActivityImportance::Normal,
);
```

## Module-Local Activity Class Standard

When a module has multiple activity-producing actions, centralize event composition and dispatch in one module-local class.

Preferred shape:

- `Modules/<Module>/app/Activity/<Module>Activity.php`
- `final class`
- `public static` methods when the class is stateless
- one method per canonical event or event family
- this class is the only module-local surface that calls `ActivityLog::record(...)`

This is the module-local activity factory/composer. It keeps activity decisions in one place.

### Bookings Example

```php
final class BookingsActivity
{
    public static function statusTransition(
        Booking $booking,
        ?BookingStatus $from,
        BookingStatus $to,
        string|BackedEnum $event = BookingActivityEvent::StatusTransition,
        array $context = [],
        ?Carbon $occurredAt = null,
        ?string $reason = null,
        ?string $actorId = null,
    ): void {
        $normalizedContext = self::mergeBookingTimelineContext($booking, $context);
        $normalizedContext['from_status'] = $from?->value;
        $normalizedContext['to_status'] = $to->value;

        if (is_string($reason) && trim($reason) !== '') {
            $normalizedContext['reason'] = trim($reason);
        }

        ActivityLog::record(
            subject: $booking,
            event: $event,
            description: self::descriptionForEvent($event),
            context: $normalizedContext,
            attributes: [
                'status' => $to->value,
            ],
            old: [
                'status' => $from?->value,
            ],
            actor: self::resolveActor($actorId),
            logName: 'booking',
            visibility: ActivityVisibility::Timeline,
            importance: ActivityImportance::Normal,
            resolveChanges: false,
        );

        self::syncActivityTimestamp($booking, $occurredAt);
    }

    public static function voucherAttached(
        Booking $booking,
        ?string $oldVoucherId,
        ?string $oldVoucherCode,
        ?string $oldOrderCode,
        string $voucherId,
        string $voucherCode,
        string $source,
        ?string $actorId = null,
    ): void {
        self::recordBookingEvent(
            booking: $booking,
            event: BookingActivityEvent::VoucherAttached,
            context: [
                'old_voucher_id' => $oldVoucherId,
                'old_voucher_code' => $oldVoucherCode,
                'voucher_code' => $voucherCode,
                'voucher_id' => $voucherId,
                'source' => $source,
            ],
            attributes: [
                'voucher_id' => $voucherCode,
                'order_code' => $voucherCode,
            ],
            old: [
                'voucher_id' => $oldVoucherCode,
                'order_code' => $oldOrderCode,
            ],
            actorId: $actorId,
        );
    }

    public static function recordBookingEvent(
        Booking $booking,
        string|BackedEnum $event,
        array $context = [],
        array $attributes = [],
        array $old = [],
        ?Carbon $occurredAt = null,
        ?string $reason = null,
        ?string $actorId = null,
    ): void {
        $normalizedContext = self::mergeBookingTimelineContext($booking, $context);

        if (is_string($reason) && trim($reason) !== '') {
            $normalizedContext['reason'] = trim($reason);
        }

        ActivityLog::record(
            subject: $booking,
            event: $event,
            description: self::descriptionForEvent($event),
            context: $normalizedContext,
            attributes: $attributes !== [] ? $attributes : null,
            old: $old !== [] ? $old : null,
            actor: self::resolveActor($actorId),
            logName: 'booking',
            visibility: ActivityVisibility::Timeline,
            importance: ActivityImportance::Normal,
            resolveChanges: false,
        );

        self::syncActivityTimestamp($booking, $occurredAt);
    }
}
```

### Action Usage Example

```php
final class AttachVoucherToBookingAction
{
    public function execute(Booking $booking, Voucher $voucher): Booking
    {
        $oldVoucherId = $booking->voucher_id;
        $oldVoucherCode = $booking->voucher?->code;
        $oldOrderCode = $booking->order_code;

        $booking->forceFill([
            'voucher_id' => $voucher->getKey(),
            'order_code' => $voucher->code,
        ])->saveQuietly();

        BookingsActivity::voucherAttached(
            booking: $booking->fresh(),
            oldVoucherId: $oldVoucherId,
            oldVoucherCode: $oldVoucherCode,
            oldOrderCode: $oldOrderCode,
            voucherId: (string) $voucher->getKey(),
            voucherCode: $voucher->code,
            source: 'admin_attach',
            actorId: auth()->id(),
        );

        return $booking->fresh();
    }
}
```

Rules:

- keep actions focused on orchestration and persistence, not activity payload composition
- actions or other explicit write boundaries must own the actual dispatch call
- dedicated write-orchestration services may dispatch activity through the module-local activity class when they are the real mutation boundary
- low-level reusable capability services should not dispatch activity directly
- do not return ad hoc activity payload arrays from services
- let the module-local activity class own:
  - event choice
  - description
  - `context`
  - explicit `attributes` / `old` only when needed
  - actor resolution
  - visibility and importance overrides
- major modules and host resources should pass an explicit `logName` such as `booking`, `protocol`, or `protocol_export`
- dependent sub-models should also set explicit narrow log names such as `booking_reviews` or `protocol_revisions`

Do not expose generic public escape hatches like `recordUser(...)` / `recordSystemAudit(...)` on module-local activity classes. Actions and services should call semantic module methods only, and the internal dispatch helpers should stay private.

## Transaction Boundary Rule

Activity logging (`ActivityLog::record(...)` or module-local `*Activity::*` calls) must be placed **outside** `DB::transaction()` closures. If the activity write fails, it must not roll back the business mutation. Capture any state needed for the activity payload (e.g., previous status, old values) inside the transaction and surface it through the return value.

Pattern:

```php
// Capture context needed for activity before or inside the transaction
$previousStatus = $protocol->status;

$result = DB::transaction(function () use ($protocol, $userId) {
    $this->state->publish($protocol, $userId);

    $freshProtocol = $protocol->fresh(['vendor', 'items']);
    if (! $freshProtocol instanceof Protocol) {
        throw new RuntimeException('Failed to reload protocol.');
    }

    return [
        'freshProtocol' => $freshProtocol,
        'previousStatus' => $previousStatus,
    ];
});

// Activity logging happens after the transaction commits successfully
ProtocolsActivity::published(
    protocol: $result['freshProtocol'],
    previousStatus: $result['previousStatus'],
    actor: $userId,
);

return $result['freshProtocol'];
```

Rules:

- never place `ActivityLog::record(...)` or module-local activity calls inside `DB::transaction()` closures
- if activity logging fails after the transaction, the business mutation is already committed and safe
- capture any pre-mutation state (previous status, old values, snapshot references) inside the transaction closure and return it as part of the result array
- use PHPDoc array-shape annotations on the transaction result for clarity
- this applies equally to actions, services, and any other write boundary that uses `DB::transaction()`

Anti-patterns:

- logging activity inside `DB::transaction()` — a failed activity write rolls back the entire business mutation
- relying on model state after the transaction without explicitly returning it — the model may have been refreshed or changed

## Host-Owned Merged Timeline Standard

The read model must be host-owned.

The approved host contract is:

```php
interface MergesActivity
{
    /**
     * @return array<int, ActivityItemData>
     */
    public function buildActivityTimeline(): array;
}
```

The shared trait shape is:

```php
trait MergesActivityTimeline
{
    /**
     * @return array<int, iterable<ActivityItemData>>
     */
    abstract protected function mergedActivitySources(): array;

    /**
     * @return array<string, array<int, string>>
     */
    protected function mergedActivitySupersededBaseEvents(): array
    {
        return [];
    }

    /**
     * @return array<int, ActivityItemData>
     */
    public function buildActivityTimeline(): array
    {
        return $this->applyMergedActivitySupersessionRules(ActivityTimelineData::merge(
            $this->mergedActivities(),
            ...$this->mergedActivitySources(),
        ));
    }
}
```

### Bookings Host Example

```php
final class Booking extends Model implements AcceptsComments, MergesActivity
{
    use BookingModelActivity;
    use HandlesNotifications;
    use HasComments;
    use MergesActivityTimeline;

    /**
     * @return array<int, iterable<ActivityItemData>>
     */
    protected function mergedActivitySources(): array
    {
        return [
            CommentDisplayData::collectToActivityFor($this),
            MailNotificationDisplayData::collectToActivityFor($this),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function mergedActivitySupersededBaseEvents(): array
    {
        return [
            EntrySource::Mail->value => ['email_sent', 'message_sent'],
        ];
    }
}
```

Rules:

- the host model owns which sources are merged
- `mergedActivities()` is always the base activity feed
- extra sources must already be translated to `ActivityItemData`
- exclusion / supersession logic must be declared through `mergedActivitySupersededBaseEvents()`
- do not put module-specific exclusion logic into `ActivityTimelineData::merge(...)`
- do not use global config registries for mergeables
- treat `email_sent` / `message_sent` supersession as legacy compatibility only; do not author new mail transport events into `activity_log`
- merged timelines for show pages should be exposed as top-level deferred props, not eager embedded payloads

## Canonical Merge Utility Standard

The merge helper must stay dumb:

```php
final class ActivityTimelineData
{
    /**
     * @param  iterable<ActivityItemData>  ...$sources
     * @return array<int, ActivityItemData>
     */
    public static function merge(iterable ...$sources): array
    {
        return collect($sources)
            ->flatMap(static fn (iterable $source): array => collect($source)->all())
            ->sortByDesc(static fn (ActivityItemData $item): string => $item->createdAt ?? '')
            ->values()
            ->all();
    }
}
```

It may:

- flatten
- sort
- return the final array

It may not:

- know module business rules
- exclude duplicate rows
- fetch related data
- translate source payloads

## Mergeable DTO Standard

Every extra timeline source must translate itself into the canonical item DTO.

Contracts:

```php
interface TranslatesToActivity
{
    public function toActivityItem(): ActivityItemData;
}

interface MergeableActivityData extends TranslatesToActivity
{
    /**
     * @return Collection<int, ActivityItemData>
     */
    public static function collectToActivityFor(Model $subject): Collection;
}
```

### Mail Example

```php
final class MailNotificationDisplayData extends Data implements MergeableActivityData
{
    public static function collectToActivityFor(Model $subject): Collection
    {
        if (! method_exists($subject, 'getMailNotificationHistory')) {
            return collect();
        }

        /** @var Collection<int, MailNotification> $notifications */
        $notifications = $subject->getMailNotificationHistory();

        return $notifications
            ->map(static fn (MailNotification $notification): ActivityItemData => self::fromModel($notification)->toActivityItem())
            ->values();
    }

    public function toActivityItem(): ActivityItemData
    {
        $status = $this->status instanceof MailNotificationStatus
            ? $this->status
            : MailNotificationStatus::PENDING;

        $createdAt = $this->sentAt
            ?? $this->deliveredAt
            ?? $this->openedAt
            ?? $this->clickedAt
            ?? $this->failedAt
            ?? $this->createdAt;

        return new ActivityItemData(
            id: $this->id ?? sprintf('mail-notification:%s', spl_object_id($this)),
            log: 'mail_notifications',
            event: sprintf('mail_%s', $status->value),
            source: EntrySource::Mail,
            createdAt: $createdAt?->toIso8601String(),
            properties: [
                'resource_id' => $this->id,
                'subject' => $this->subject,
                'status' => $status->value,
                'status_label' => $this->statusLabel,
                'primary_recipient_email' => $this->primaryRecipientEmail,
            ],
        );
    }
}
```

### Comment Example

```php
final class CommentDisplayData extends Data implements MergeableActivityData
{
    public static function collectToActivityFor(Model $subject): Collection
    {
        if (! method_exists($subject, 'comments')) {
            return collect();
        }

        $comments = $subject->relationLoaded('comments')
            ? $subject->comments
            : $subject->comments()->latest()->get();

        return $comments
            ->map(static fn (Comment $comment): ActivityItemData => self::fromModel($comment)->toActivityItem())
            ->values();
    }

    public function toActivityItem(): ActivityItemData
    {
        return new ActivityItemData(
            id: $this->id ?? sprintf('comment:%s', spl_object_id($this)),
            log: 'comments',
            event: 'comment_created',
            source: EntrySource::Comment,
            createdAt: $this->createdAt?->toIso8601String(),
            properties: [
                'resource_id' => $this->id,
                'comment' => $this->toArray(),
            ],
        );
    }
}
```

Rules:

- source DTOs own their own translation
- `collectToActivityFor()` may only collect subject-relevant rows
- `toActivityItem()` must return canonical `ActivityItemData`
- do not build page-local adapters in controllers
- do not make `Activity` guess whether a source applies to a model

## Canonical Activity Item Contract

All timeline rows converge to `ActivityItemData`.

Example shape:

```php
new ActivityItemData(
    id: 'mail-notification:123',
    log: 'mail_notifications',
    event: 'mail_delivered',
    source: EntrySource::Mail,
    eventLabel: 'Delivered',
    description: 'Booking confirmation email delivered',
    createdAt: '2026-04-02T18:30:00+03:00',
    causer: null,
    subjectType: Booking::class,
    subjectId: '0195...',
    subjectLabel: 'Booking',
    headline: 'Email "Booking confirmation" for customer@example.com was Delivered.',
    summary: null,
    changes: [],
    changesDetailed: [],
    properties: [
        'resource_id' => '0196...',
        'subject' => 'Booking confirmation',
        'status' => 'delivered',
        'status_label' => 'Delivered',
        'primary_recipient_email' => 'customer@example.com',
    ],
);
```

Use `properties` for row-specific metadata. Do not widen the DTO for every new source unless the field truly becomes shared across multiple renderers.

### Structured Headline Segment Standard

When generic activity rows need richer emphasis such as actors, fields, values, or statuses, extend the canonical read model with semantic headline segments instead of teaching the frontend to parse the flat sentence.

Rules:

- `headline` remains the backward-compatible flat sentence
- `headlineSegments` carries semantic tokens, not formatting instructions
- allowed segment types should stay small and semantic, such as `text`, `actor`, `field`, `value`, and `status`
- backend payloads must never encode typography flags such as `isBold`, HTML fragments, or markdown markers
- the frontend may decide that `actor` becomes a link, `status` becomes a badge, and `field` / `value` become emphasized text
- mail and comment rows may continue owning richer row-local rendering without being forced into generic headline segments

Example:

```php
new ActivityItemData(
    id: 'activity-123',
    log: 'booking',
    event: 'status_transition',
    source: EntrySource::ActivityLog,
    headline: 'Simona Tsvetanova changed the status to Pending Approval.',
    headlineSegments: [
        new HeadlineSegmentData(type: 'actor', text: 'Simona Tsvetanova', causerId: 'user-1'),
        new HeadlineSegmentData(type: 'text', text: ' changed the status to '),
        new HeadlineSegmentData(type: 'status', text: 'Pending Approval'),
        new HeadlineSegmentData(type: 'text', text: '.'),
    ],
    properties: [],
);
```

The boundary is strict:

- backend exports semantics
- frontend owns presentation
- merge logic stays dumb

## Notification Ownership Rule

The resource implementing `HandlesNotifications` must be the canonical notifiable owner.

Correct:

```php
MailEnvelopeFactory::make($this)
    ->forNotifiable($this->protocol)
    ->build();
```

Not correct:

- storing a protocol-specific mail notification on `Vendor`
- then recovering it through `metadata.protocol_id`

Use metadata for recipient/business context, not to compensate for wrong notifiable ownership.

If an email is about a `Protocol`, store it on `Protocol`.
If it is about a `Booking`, store it on `Booking`.
If it is vendor-wide and not about one protocol, then it may belong to `Vendor`.

## Mail Transport Exclusion Rule

Do not write transport or deliverability events into `activity_log`.

Do not author activity events such as:

- `email_sending`
- `email_sent`
- `message_sent`
- `mail_delivered`
- `mail_opened`

Those belong in `mail_notifications` and should appear on merged timelines through `MailNotificationDisplayData`.

Implementation rule:

- the `MailNotifications` module must not define module-local `app/Activity/*` writers
- the `MailNotifications` module must not read or write transport lifecycle through `activity_log`
- merged mail timelines should source directly from native `MailNotification` records through `MailNotificationDisplayData` or a source-native timeline provider
- compatibility helpers such as `getMailNotificationTimeline()` or `getMailNotificationActivities()` should return canonical mail timeline items, not raw Spatie activity rows
- read-side Activity imports inside `MailNotifications` are allowed when they only support canonical timeline translation; the prohibition is on shadow logging and model activity, not on source-native read adapters

Allowed:

- parent lifecycle events that happen to involve communication as a business step, such as `Protocol sent to vendor for review and signature`
- parent workflow events such as `Booking confirmed`, `Protocol published`, or `Voucher attached`

Not allowed:

- duplicating the same email send/delivery/open fact in both `activity_log` and `mail_notifications`

## Exclusion And Supersession Rules

The host timeline may suppress generic base activity rows when a richer merged source already explains the same business fact.

Bookings legacy-compatibility example:

- base auto activity row: `System sent an email for this booking.`
- merged mail row: `Email "Booking confirmation" for Customer (customer@example.com) was Delivered.`

Final timeline:

- keep the merged mail row
- drop the generic base activity row

That rule belongs here:

```php
protected function mergedActivitySupersededBaseEvents(): array
{
    return [
        EntrySource::Mail->value => ['email_sent', 'message_sent'],
    ];
}
```

Rules:

- only the host may decide supersession
- hosts may only declare source-activated base-event suppression, not arbitrary timeline mutation
- never suppress rows inside a mergeable DTO
- never suppress rows inside `ActivityTimelineData`
- keep the simpler generic row if the richer source does not exist
- new write paths should prefer not generating the generic mail activity row at all

## Dependent Sub-Model Example

Dependent models use the same shared trait, but they keep narrow self-auditing and explicit log names:

```php
final class ProtocolRevision extends Model
{
    use HasModelActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('protocol_revisions')
            ->logOnly([
                'revision_number',
                'reason',
                'changed_at',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
```

This does not make `ProtocolRevision` the timeline host. The parent `Protocol` still owns semantic events such as revision creation:

```php
final class ProtocolsActivity
{
    public static function revisionCreated(
        Protocol $protocol,
        ProtocolRevision $revision,
        Model|string|int|null $actor = null,
    ): void {
        ActivityLog::record(
            subject: $protocol,
            event: 'revision_created',
            description: 'Protocol revision created',
            context: [
                'revision_id' => $revision->id,
                'revision_number' => $revision->revision_number,
                'reason' => $revision->reason,
            ],
            actor: $actor,
            logName: 'protocol',
            visibility: ActivityVisibility::Timeline,
            importance: ActivityImportance::Normal,
        );
    }
}
```

## Frontend Companion Contract

The frontend entry point is the shared merged timeline component. Pages pass data; the shared component owns rendering by source.

Current prop shape:

```tsx
export interface MergedActivitiesTimelineProps {
  activities?: readonly TimelineActivityItem[]
  title: string
  emptyMessage: string
  comments?: {
    context: CommentableContext
    canComment: boolean
    canModerate: boolean
    translations: CommentsTranslationBundle
  }
  mailNotifications?: MailNotificationItem[]
  onSelectMailNotification?: (notification: MailNotificationItem) => void
}
```

Current orchestration shape:

```tsx
{group.items.map(({ activity, key }, index) => {
  const comment = getCommentPayload(activity)
  const resourceId = getMailResourceId(activity)
  const mailNotification = resourceId
    ? (notificationMap.get(resourceId) ?? null)
    : null

  return (
    <TimelineRow
      key={key}
      dotColor={getDotColor(activity.event)}
      isLast={index === group.items.length - 1}
    >
      {comment && comments ? (
        <CommentActivityRow
          comment={comment}
          canModerate={comments.canModerate}
          translations={comments.translations}
          onDelete={handleDelete}
        />
      ) : activity.source === 'mail' ? (
        <MailActivityRow
          activity={activity}
          locale={appLocale}
          notification={mailNotification}
          onPreview={(notification, previewUrl) => {
            previewMail.open({ notification, previewUrl })
          }}
          onSelectMailNotification={onSelectMailNotification}
          viewLabel={viewLabel}
        />
      ) : (
        <GenericActivityRow
          activity={activity}
          locale={appLocale}
          sourceLabel={getActivitySourceLabel(activity, translate)}
          showChangesLabel={showChangesLabel}
          currentUserId={currentUserId}
        />
      )}
    </TimelineRow>
  )
})}
```

Row split:

- `generic-activity-row.tsx` for plain activity log rows
- `mail-activity-row.tsx` for mail rows
- `comment-activity-row.tsx` for rich comment rows

Rules:

- shared FE owns row dispatch by `activity.source`
- the shared merged activity FE package lives in `resources/js/pages/admin/activity/shared`
- `merged-activities-timeline.tsx` is the orchestrator and `shared/partials/*` contains the row renderers
- when merged activity FE behavior needs to change, extend or edit the shared activity package itself
- do not patch Booking, Protocol, or other module pages with one-off merged activity rendering logic
- pages must not reimplement mail/comment/activity row rendering
- mail preview is a row-local action and belongs in the shared timeline
- comment mutations are the only current interactive row type and stay inside the shared timeline

## Generic Activity Row Rules

The generic row now renders canonical semantic headline segments first and only falls back to flat headline parsing for legacy rows that do not yet provide segments.

Rules:

- `actor` segments may become `You`, a user link, or emphasized text
- `status` segments may render as inline badges
- `field` and `value` segments may render as emphasized text
- `text` segments stay plain text
- disclosure still appears only when there are multiple change details
- do not synthesize new business sentences in React when the backend can resolve them canonically

That means:

- `pending_approval` should already arrive as display-ready status text before it becomes a `status` segment
- simple status changes no longer need frontend-only sentence synthesis
- multi-field updates still show the disclosure

## Mail Row Rules

The mail row must:

- resolve recipient as `Name (email)` when possible
- render deliverability inline in the sentence
- own the `View` action
- use the shared action/modal paradigm for preview

Example:

```tsx
<Text size="sm" className="leading-snug">
  Email "{subject}" for {recipient.name} (
  <EmailDisplay
    email={recipient.email}
    size="sm"
    tag="span"
    type="main"
    copyable={false}
  />
  ) was{' '}
  <Badge inline size="xs" variant="secondary" className={statusClass}>
    {status.label}
  </Badge>
  .
</Text>
```

## Comment Row Rules

Comments stay rich and interactive inside the shared timeline, but the shared timeline still owns the surface:

```tsx
export function CommentActivityRow({
  comment,
  canModerate,
  translations,
  onDelete,
}: CommentActivityRowProps) {
  return (
    <CommentItem
      comment={comment}
      canModerate={canModerate}
      translations={translations}
      onDelete={onDelete}
    />
  )
}
```

That is the current exception where a row remains richer than generic activity or mail.

## Controller And Page Wiring Standard

Serve merged activity as a deferred top-level prop:

```php
return Inertia::render('admin/bookings/pages/bookings-show.page', [
    'booking' => BookingShowData::fromModel($booking),
    'activity' => Inertia::defer(fn (): array => $booking->buildActivityTimeline()),
]);
```

Render it through an Inertia `Deferred` boundary on the frontend. The boundary may live in the page or inside the shared timeline component, but it must exist around the deferred prop:

```tsx
<Deferred
  data="activity"
  fallback={<SmartCardSkeleton lines={4} />}
>
  <MergedActivitiesTimeline
    title={t('shared.activities.ui.timeline')}
    emptyMessage={t('shared.activities.ui.empty')}
    activities={activity}
    comments={{
      context: commentContext,
      canComment: booking.canReceiveComments,
      canModerate: booking.canModerateComments,
      translations: commentsTranslations,
    }}
    mailNotifications={mailNotifications}
  />
</Deferred>
```

Rules:

- backend merged timelines should use `Inertia::defer(...)`
- the deferred prop should be top-level, typically `activity`
- frontend rendering must happen behind Inertia's `Deferred` component
- the `Deferred` boundary can be page-owned or shared-component-owned, but pages must not eagerly assume deferred props are present

## Description Rule

Descriptions should be concrete and easy to translate.

Good:

- `Voucher status changed`
- `Protocol marked as paid`
- `Voucher attached to booking`

Bad:

- `Something happened`
- `Model updated`
- `Operation completed successfully`

## Bilingual Message Composition

Compose readable activity messages in English and Bulgarian from canonical stored activity data, not from stored final UI sentences.

Required composition model:

- store one canonical event key such as `paid`, `status_changed`, or `voucher_attached`
- store one structured `context` payload with the business facts needed for the message
- let mappings or model-local hooks translate field labels and scalar display values
- let translation templates own the full final sentence per locale
- keep placeholder names stable across locales

Do not:

- store pre-localized English and Bulgarian sentences in activity properties
- build the final headline by concatenating translated fragments
- assume English word order will also read naturally in Bulgarian

The locale must own the whole sentence.

Good:

```php
[
    'event' => 'paid',
    'context' => [
        'amount_formatted' => '125.00 BGN',
        'payment_method' => 'bank transfer',
    ],
]
```

```php
// en
'paid_with_amount' => ':actor marked this :subject as paid for :amount.',

// bg
'paid_with_amount' => ':actor отбеляза този :subject като платен за :amount.',
```

Bad:

```php
$headline = $actor.' '.trans("activity::activity/general.events.{$event}").' '.$subject.' '.$amount;
```

## Bilingual Placeholder Rules

Every custom event headline that is visible in timelines must follow these placeholder rules:

- use the same placeholder names in English and Bulgarian
- prefer semantic names such as `:status`, `:amount`, `:voucher`, `:customer`, `:value`
- keep placeholders display-ready before they reach the template
- do not format money, enum labels, or booleans directly inside the translation string
- if a placeholder requires localization or formatting, resolve it in mapping logic or explicit event context first

Examples:

- good: `:actor changed the status to :status.`
- good: `:actor промени статуса на :status.`
- bad: use `:new`, `:x`, or `:item` when the placeholder meaning is actually `status` or `amount`

## ActivityMapping Contract — Module-Owned Headline Control

The `ActivityMapping` interface (`Modules\Activity\Contracts\ActivityMapping`) gives each module control over how its activity rows are displayed. Every module with a host resource model should implement one.

Location: `Modules/<Module>/app/Support/<Model>ActivityMapping.php`

### HeadlineRenderer Pipeline

The `HeadlineRenderer` now resolves one canonical result object that contains both the flat `headline` and semantic `headlineSegments`. It must choose the template path once, resolve placeholders once, render the flat sentence once, and derive segments from that same template.

Resolution order:

1. **Updated events** (`updated`, `details_updated`) → updated templates with semantic `field` / `value` placeholders
2. **Status events** (`status_changed`, `status_transition`, `status_override`) → `templates.status_changed_to` when a display-ready `:status` exists, otherwise a natural status fallback
3. **Shared event templates** → activity timeline locale keys in `locales/{en,bg}/activity.json` when all required placeholders can be satisfied
4. **Module-owned templates** → `ActivityMapping::eventTemplates()` when all required placeholders can be satisfied
5. **Generic fallback** → `activity.general.headline` → `:subject was :event by :actor`

Rules:

- never build the flat headline and segments through separate mirrored branching
- never parse the final rendered sentence to recover semantics
- if a template requires `:value` and the mapping cannot resolve one, fall back to the generic headline instead of rendering a broken sentence
- keep status labels display-ready before they reach the template

If an event lacks a usable template in steps 3 and 4, it falls through to the generic step 5 headline — producing ugly output like "vendor was Alias Synced by Nicolas Vlachos". The fix is still to improve mappings and templates, not to patch the frontend.

### Fixing Bad Headlines — Diagnosis Checklist

When activity rows display poorly, diagnose in this order:

1. **Check the event name** — Is it a raw string like `'alias_synced'`? Does a matching template exist in `locales/en/activity.json` under `templates.{event}` or the module-owned activity namespace?
2. **Check the event label** — Does `events.{event}` exist in the activity locale dictionary? Missing event labels fall back to `Str::headline()` which produces "Alias Synced" instead of "alias synced".
3. **Check the mapping's `eventDisplayValue()`** — For events with `:value` in their template, the mapping must resolve the value from `properties`. If it returns `null`, the renderer should fall back rather than emit a broken `:value` sentence.
4. **Check the properties structure** — `ActivityLog::record()` stores `attributes` at `properties.attributes`, `old` at `properties.old`, `context` at `properties.context`. `ActivityLog::recordUser()` stores everything flat at `properties.*`. Know which you used.
5. **Check the field labels** — `fieldLabel()` maps DB column names to display labels. Missing labels fall back to `Str::headline($key)`.

### Adding a New Semantic Event

When a module introduces a new business event (not a simple CRUD update), follow all three steps:

**Step 1: Add shared activity timeline copy** (both EN and BG in `locales/{en,bg}/activity.json`)

```json
{
    "events": {
        "alias_synced": "alias synced"
    },
    "templates": {
        "alias_synced": ":actor synced the alias to :value."
    }
}
```

**Step 2: Implement `eventDisplayValue()` in the module's ActivityMapping**

```php
public function eventDisplayValue(string $event, array $properties): ?string
{
    if ($event === 'alias_synced') {
        return $properties['attributes']['alias']
            ?? $properties['context']['source']
            ?? null;
    }

    return null;
}
```

**Step 3: Record the event with proper structure**

```php
ActivityLog::record(
    subject: $vendor,
    event: 'alias_synced',
    description: 'Vendor alias synced with active business',
    context: ['source' => $business->name],
    attributes: ['alias' => $newAlias],
    old: ['alias' => $originalAlias],
    actor: auth()->user(),
    logName: 'vendor',
);
```

Result: `Nicolas Vlachos synced the alias to "Business Name".`

### `eventTemplates()` — Module-Owned Semantic Template Support

`ActivityMapping::eventTemplates()` is consumed by `HeadlineRenderer` after the shared translation templates. Use it when a module needs a semantic event sentence that should stay module-owned instead of expanding the shared translation file.

Rules:

- prefer shared `activity::activity/general.templates.*` entries for broadly shared event families
- use `eventTemplates()` for module-specific business events that are not good shared candidates
- keep placeholder names semantic and stable across locales
- keep `eventDisplayValue()` aligned with any `:value` placeholders used by module templates
- if you also expose the event in shared translations for EN/BG parity reasons, keep the wording consistent with the module-owned template

### `recordUser()` vs `record()` — When to Use Which

| Method | When | Properties Structure |
|--------|------|---------------------|
| `ActivityLog::recordUser()` | Legacy thin wrapper — acceptable for simple events | Flat: `properties.old`, `properties.new`, `properties.*` |
| `ActivityLog::record()` | **Preferred** — full structured payload with explicit actor | Structured: `properties.attributes`, `properties.old`, `properties.context` |

For new semantic events, always use `ActivityLog::record()` with explicit `attributes`, `old`, `context`, `actor`, and `logName`. This gives the `HeadlineRenderer` and `ActivityMapping` the cleanest data to work with.

### Repairing an Existing Module's Activity

Audit sequence for bringing a module's activity up to standard:

1. **Read the current timeline output** — identify ugly/generic headlines
2. **List all events** — `grep -r "ActivityLog::" Modules/<Module>/app/` to find every recording site
3. **Check the ActivityMapping** — does one exist? Is `eventDisplayValue()` implemented? Is `eventTemplates()` populated?
4. **Add missing shared templates** — for every custom event that lacks a template in `activity/general.php`
5. **Add missing event labels** — in `activity/general.events.*`
6. **Implement `eventDisplayValue()`** — for events that need `:value` in their headline
7. **Migrate `recordUser()` to `record()`** — for events where the flat properties structure causes mapping failures
8. **Verify EN/BG parity** — every template and event label in EN must have a BG counterpart

Do not refactor all recording sites at once. Fix the mapping and templates first — many existing rows will immediately display better without changing the write path.

## Anti-Patterns

Do not:

- use raw `activity()->log(...)` in module code
- put event composition inline inside controllers or services
- store protocol-specific mail on `Vendor` and recover it through metadata
- build timeline rows directly in controllers
- create module-specific timeline React components when the shared renderer already supports the sources
- add disclosure arrows for single-value status updates
- keep separate primary comments/mail cards once the merged timeline owns those surfaces
- put module-specific supersession logic inside `ActivityTimelineData`
- introduce custom events without adding both `events.*` and `templates.*` entries in shared activity translations
- return `null` from `eventDisplayValue()` when the template uses `:value` — the placeholder stays raw
- use `recordUser()` for new semantic events when `record()` gives better structure
- leave `eventDisplayValue()` returning `null` for all events — audit it when adding new events
- skip BG translations when adding EN templates — always maintain parity
