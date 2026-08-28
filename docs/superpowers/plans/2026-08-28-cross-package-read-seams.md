# Cross-Package Read Seams Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the smaller read/event gaps KPO exposed across Activity, Mail Notifications, Translations, Comments, Settings, and SEO.

**Architecture:** Each package adds only the projection or event context it owns. Cross-package composition remains in the application, but stable identity/context values prevent the application from reloading another package's persistence model.

**Tech Stack:** PHP 8.4, Laravel 13 Eloquent/events, Spatie Laravel Data, Pest 4.

**Spec:** `docs/superpowers/specs/2026-08-28-consumer-readiness-v2-design.md`

## Global Constraints

- Existing constructor signatures remain source-compatible by appending optional parameters only.
- New aggregate dimensions have explicit result limits and deterministic ordering.
- Events remain value-free for protected setting values and redacted for mail metadata.
- Packages do not add dependencies on one another to solve consumer composition.
- New DTOs participate in generated TypeScript where their package already does.

---

### Task 1 (CR-09): Support bounded multi-event Activity reads

**Files:**
- Modify: `packages/nvl/activity/src/Data/ActivityIndexFilter.php`
- Modify: `packages/nvl/activity/src/Builders/ActivityLogBuilder.php`
- Modify: `packages/nvl/activity/src/Services/ActivityReadService.php`
- Create: `packages/nvl/activity/src/Support/ActivitySubjectReference.php`
- Modify: `packages/nvl/activity/src/Services/ActivityRecorder.php`
- Modify: `packages/nvl/activity/src/Facades/ActivityLog.php`
- Modify: `packages/nvl/activity/tests/Unit/ActivityIndexFiltersDataTest.php`
- Modify: `packages/nvl/activity/tests/Feature/ActivityApiTest.php`
- Modify: `packages/nvl/activity/tests/Feature/ActivityRecorderTest.php`

**Interfaces:**
- Consumes: existing single `event` input and `ActivityLogBuilder::whereEvents()`.
- Produces: `ActivityIndexFilter::$events`, clamped single/multi-subject history, and model-free `recordForSubjectReference()`.

- [x] **Step 1: Write failing multi-event and bound tests**

```php
$filters = ActivityIndexFilter::fromInput([
    'events' => ['created', 'updated', 'updated'],
]);

expect($filters->events)->toBe(['created', 'updated']);
```

Also assert the legacy `event=created` input still works, comma-separated input
normalizes, blank entries disappear, more than ten unique events is rejected,
subject pagination clamps `perPage` to 100, multi-subject reads reject more
than 100 references, and mixed subject types cannot cross-match IDs.

- [x] **Step 2: Run focused Activity tests and verify failures**

Run: `vendor/bin/pest --configuration=packages/nvl/activity/phpunit.xml.dist --compact packages/nvl/activity/tests/Unit/ActivityIndexFiltersDataTest.php packages/nvl/activity/tests/Feature/ActivityApiTest.php`

Expected: FAIL because `events` does not exist and subject pagination is not
clamped.

- [x] **Step 3: Add compatible event normalization**

Append `public readonly array $events = []` to `ActivityIndexFilter`. In
`fromInput()`, accept `events` as an array or comma-separated string; merge the
legacy `event`; trim, discard blanks, deduplicate in caller order, and throw an
`InvalidArgumentException` above ten values. `applyIndexFilters()` calls
`whereEvents($filters->events)` when non-empty and otherwise preserves the
legacy single-event path.

- [x] **Step 4: Add bounded subject history and stable subject recording**

Clamp `paginateForSubjectKey()` to 1–100. Add this value object:

```php
new ActivitySubjectReference(string $type, string|int $id)
```

Add:

```php
public function paginateForSubjectReferences(
    array $subjects,
    int $perPage = 20,
): LengthAwarePaginator

public function recordForSubjectReference(
    ActivitySubjectReference $subject,
    string|BackedEnum $event,
    string $description = '',
    array $context = [],
    Model|string|int|null $actor = null,
    string|BackedEnum|null $importance = null,
): ?ActivityContract
```

Normalize and deduplicate at most 100 `ActivitySubjectReference` values by
type+ID. Group IDs by type and build nested `(subject_type = ? AND subject_id
IN (...))` branches inside one outer OR expression. An empty list returns an
empty paginator without querying. Apply the canonical null-safe newest-first
ordering and clamp `perPage` to 1–100.

It reuses normal metadata validation, creates the activity through the existing
logger, and sets `subject_type`/`subject_id` through a logger tap. It never
instantiates or queries the subject model and never enables automatic diffs.

- [x] **Step 5: Run Activity quality**

Run: `php tools/run-package-quality.php activity`

Expected: PASS, including legacy single-event compatibility.

- [x] **Step 6: Commit CR-09**

```bash
git add packages/nvl/activity/src packages/nvl/activity/tests
git commit -m "feat(activity): add bounded event and subject reads"
```

### Task 2 (CR-10): Add Mail aggregates and safe tracking correlation

**Files:**
- Create: `packages/nvl/mail-notifications/src/ValueObjects/MailNotificationAggregate.php`
- Modify: `packages/nvl/mail-notifications/src/ValueObjects/MailNotificationStatistics.php`
- Modify: `packages/nvl/mail-notifications/src/Actions/GetMailNotificationStatisticsAction.php`
- Modify: `packages/nvl/mail-notifications/src/ValueObjects/TrackingContext.php`
- Modify: `packages/nvl/mail-notifications/src/Events/MailTrackingStarted.php`
- Modify: `packages/nvl/mail-notifications/src/Services/DatabaseTrackingLifecycle.php`
- Modify: `packages/nvl/mail-notifications/tests/Feature/MailNotificationAdministrationTest.php`
- Modify: `packages/nvl/mail-notifications/tests/Feature/MailTrackingEventDispatcherTest.php`
- Modify: `packages/nvl/mail-notifications/tests/Unit/PrivacyTest.php`

**Interfaces:**
- Consumes: existing authorized read query and `TrackingContext::metadata` redaction.
- Produces: top mailer/category aggregates and `MailTrackingStarted::$correlation`.

- [x] **Step 1: Write failing aggregate and privacy tests**

```php
$statistics = app(GetMailNotificationStatisticsAction::class)->execute(
    $actor,
    new MailNotificationReadQuery,
);

expect($statistics->mailers[0])->toBeInstanceOf(MailNotificationAggregate::class)
    ->and($statistics->categories[0]->key)->toBe('domain.reminder');
```

Add an event assertion where approved correlation contains
`reminder_occurrence_id`, while nested arrays, objects, email addresses, and a
key rejected by policy never appear in the dispatched event.

- [x] **Step 2: Run focused Mail tests and verify missing fields fail**

Run: `vendor/bin/pest --configuration=packages/nvl/mail-notifications/phpunit.xml.dist --compact packages/nvl/mail-notifications/tests/Feature/MailNotificationAdministrationTest.php packages/nvl/mail-notifications/tests/Feature/MailTrackingEventDispatcherTest.php packages/nvl/mail-notifications/tests/Unit/PrivacyTest.php`

Expected: FAIL because aggregate DTOs and correlation context do not exist.

- [x] **Step 3: Implement bounded statistics dimensions**

`MailNotificationAggregate` has `string $key` and `int $count`. Append
`array $mailers = []` and `array $categories = []` to
`MailNotificationStatistics`. Build each dimension with one grouped aggregate
query, order count descending/key ascending, and limit to ten. Empty/null keys
normalize to `unknown`. Include both arrays in `toArray()`.

- [x] **Step 4: Implement approved correlation context**

Append `array $correlation = []` to `TrackingContext` and add:

```php
public function withCorrelation(array $correlation): self
```

Accept at most twenty keys matching `/^[a-z][a-z0-9_]{0,63}$/`; values are
`string|int|bool|null`; strings are at most 255 characters. Reject nested data,
objects, resources, keys containing `email`, `token`, `secret`, `password`, or
`payload`, and invalid UTF-8. Pass the validated map directly to an appended
`MailTrackingStarted::$correlation` constructor argument. Persist the same map
under redacted metadata key `correlation`, but the event never reloads it.
Preserve the correlation map in every existing clone-style method, including
`forNotifiable()` and `withMetadata()`, and prove both method orders retain the
same validated values.

- [x] **Step 5: Run Mail quality and compatibility tests**

Run: `php tools/run-package-quality.php mail-notifications`

Expected: PASS, including old two-argument `MailTrackingStarted` construction
with the optional correlation default.

- [x] **Step 6: Commit CR-10**

```bash
git add packages/nvl/mail-notifications/src packages/nvl/mail-notifications/tests
git commit -m "feat(mail): expose safe delivery aggregates and correlation"
```

### Task 3 (CR-11): Add Translation catalog statistics

**Files:**
- Create: `packages/nvl/translations/src/Data/TranslationCatalogStatisticsData.php`
- Create: `packages/nvl/translations/src/Actions/Entries/GetTranslationCatalogStatisticsAction.php`
- Create: `packages/nvl/translations/src/Services/TranslationEntryFilterSchema.php`
- Modify: `packages/nvl/translations/src/Traits/TranslationEntryFilters.php`
- Modify: `packages/nvl/translations/src/Http/Controllers/Api/TranslationsApiController.php`
- Modify: `packages/nvl/translations/tests/Feature/TranslationsConsumerContractsTest.php`
- Modify: `packages/nvl/translations/README.md`

**Interfaces:**
- Consumes: `translations` storage fields `is_missing`, `sync_status`, `locale`, `scope_type`, and `scope_name`, plus `TranslationsAuthorization`.
- Produces: `TranslationEntryFilterSchema::make(): FilterSchema` and an authorized `GetTranslationCatalogStatisticsAction::execute(?FilterSet $filters = null): TranslationCatalogStatisticsData`.

- [ ] **Step 1: Write failing aggregate tests**

```php
$statistics = app(GetTranslationCatalogStatisticsAction::class)->execute();

expect($statistics->toArray())->toMatchArray([
    'total' => 6,
    'missing' => 2,
    'conflicts' => 1,
    'changed' => 3,
]);
```

Add filter parity with `ListTranslationEntriesAction`, empty-catalog behavior,
locale/scope sorting, authorization denial before a query, and query-count
assertions. Prove the model trait, package HTTP controller, statistics Action,
and a consumer-built `QueryFilterSetFactory` all use the same schema service.

- [ ] **Step 2: Run the consumer-contract test and verify missing classes fail**

Run: `vendor/bin/pest --configuration=packages/nvl/translations/phpunit.xml.dist --compact packages/nvl/translations/tests/Feature/TranslationsConsumerContractsTest.php`

Expected: FAIL because the statistics Action and DTO do not exist.

- [ ] **Step 3: Implement one base aggregate plus bounded dimensions**

DTO constructor:

```php
TranslationCatalogStatisticsData(
    int $total,
    int $missing,
    int $conflicts,
    int $changed,
    array $locales,
    array $scopes,
)
```

`locales` and `scopes` are `array<string, int>`, sorted count descending/key
ascending and capped at 100 distinct entries. Apply the same Filterable schema
as the list Action. Define conflicts as `sync_status = conflict`; define changed
as non-null `source_hash` where `sync_status` is `changed`, `preserved`, or
`conflict`; document these semantics in the README. Implement the DTO with
`DataTransform`, camel-case output mapping, and `#[TypeScript]` like the other
Translations public DTOs.

Move the schema declaration currently owned by `TranslationEntryFilters` into
`TranslationEntryFilterSchema::make()`; the trait delegates to it for backward
compatibility, and the package HTTP controller injects it rather than
instantiating `TranslationEntry`. The statistics Action authorizes
`TranslationsAbility::ListEntries` before building its first query.

- [ ] **Step 4: Run package quality and generated-type checks**

Run: `php tools/run-package-quality.php translations`

Expected: PASS.

Run: `php artisan nvl:data:types:generate --fail-on-warning`

Run: `composer types:check`

Expected: PASS with `TranslationCatalogStatisticsData` generated.

- [ ] **Step 5: Commit CR-11**

```bash
git add packages/nvl/translations/src/Data/TranslationCatalogStatisticsData.php packages/nvl/translations/src/Actions/Entries/GetTranslationCatalogStatisticsAction.php packages/nvl/translations/src/Services/TranslationEntryFilterSchema.php packages/nvl/translations/src/Traits/TranslationEntryFilters.php packages/nvl/translations/src/Http/Controllers/Api/TranslationsApiController.php packages/nvl/translations/tests/Feature/TranslationsConsumerContractsTest.php packages/nvl/translations/README.md resources/js/types
git commit -m "feat(translations): add catalog statistics"
```

### Task 4 (CR-12): Add Comments, Settings, and SEO identity reads

**Files:**
- Create: `packages/nvl/comments/src/Actions/FindLatestTargetCommentAction.php`
- Create: `packages/nvl/comments/src/Data/Queries/CommentSelectorData.php`
- Modify: `packages/nvl/comments/tests/Feature/CommentsV1ApiProjectionTest.php`
- Create: `packages/nvl/settings/src/Data/SettingSubjectReferenceData.php`
- Modify: `packages/nvl/settings/src/Events/SettingChanged.php`
- Modify: `packages/nvl/settings/tests/SettingsConsumerContractsTest.php`
- Create: `packages/nvl/seo/src/Actions/GetOwnerSeoProfileAction.php`
- Create: `packages/nvl/seo/src/Data/SeoOwnerRevisionData.php`
- Create: `packages/nvl/seo/src/Actions/GetOwnerSeoRevisionAction.php`
- Modify: `packages/nvl/seo/tests/Feature/SeoConsumerContractsTest.php`

**Interfaces:**
- Consumes: Comments read/projection services, CR-09 Activity subject reference shape, Settings event identity, SEO owner registry/authorization/presenter.
- Produces: one-comment DTO lookup, value-free setting subject identity, and owner-shaped SEO profile/revision DTOs.

- [ ] **Step 1: Write failing package-specific contract tests**

```php
$comment = app(FindLatestTargetCommentAction::class)->execute(
    target: $article,
    actor: $actor,
    selector: new CommentSelectorData(tags: ['decision']),
    audience: CommentAudience::Member,
);

expect($comment)->toBeInstanceOf(MemberCommentData::class);
```

For Settings, assert `SettingChanged::$subject` contains only type/id and the
event serializes no setting value. For SEO, assert an authorized owner lookup
returns `SeoProfileData`, a missing scope returns null, and the revision lookup
returns only owner alias/ID/scope/profile ID/revision.

- [ ] **Step 2: Run all three focused tests and verify missing APIs fail**

Run: `vendor/bin/pest --configuration=packages/nvl/comments/phpunit.xml.dist --compact packages/nvl/comments/tests/Feature/CommentsV1ApiProjectionTest.php`

Run: `vendor/bin/pest --configuration=packages/nvl/settings/phpunit.xml.dist --compact packages/nvl/settings/tests/SettingsConsumerContractsTest.php`

Run: `vendor/bin/pest --configuration=packages/nvl/seo/phpunit.xml.dist --compact packages/nvl/seo/tests/Feature/SeoConsumerContractsTest.php`

Expected: each command FAILS for its missing API.

- [ ] **Step 3: Implement the latest matching Comment projection**

Signature:

```php
public function execute(
    Model $target,
    CommentActorData $actor,
    CommentSelectorData $selector,
    CommentAudience $audience = CommentAudience::Member,
): PublicCommentData|MemberCommentData|CommentManagementData|null
```

Resolve the canonical target, build the authorized query through
`CommentReadService`, apply only allowlisted tag/status criteria from
`CommentSelectorData`, select the newest by `created_at` then `id`, limit one,
and project through `CommentProjectionFactory`. CR-31 extends the selector with
registered metadata equality aliases; callers never provide JSON paths.
Denied management audiences fail before the query.

- [ ] **Step 4: Add a stable Settings subject reference**

`SettingSubjectReferenceData` contains `type: 'nvl_setting'` and `id: string`.
Declare `public SettingSubjectReferenceData $subject` on `SettingChanged` and
initialize it from the existing event ID inside the constructor body; do not
add a constructor argument. KPO can map it to CR-09's
`ActivitySubjectReference` without querying the Setting model.

- [ ] **Step 5: Add authorized SEO owner reads**

Signatures:

```php
public function execute(Model $owner, ?string $scope = null): ?SeoProfileData
public function execute(Model $owner, ?string $scope = null): SeoOwnerRevisionData
```

Resolve the registered owner alias/morph identity through `SeoOwnerRegistry`,
authorize a view context before returning data, query by owner type/ID and
normalized scope, eager-load translations only for the full profile, and use
`SeoProfilePresenter`. `SeoOwnerRevisionData` contains owner alias, owner ID,
scope, nullable profile ID, and revision (zero when absent). Make the revision
DTO a camel-case `#[TypeScript]` Data contract.

- [ ] **Step 6: Run the three package quality gates**

Run: `php tools/run-package-quality.php comments`

Run: `php tools/run-package-quality.php settings`

Run: `php tools/run-package-quality.php seo`

Expected: all PASS.

Run: `php artisan nvl:data:types:generate --fail-on-warning`

Run: `composer types:check`

Expected: PASS with the SEO revision projection generated.

- [ ] **Step 7: Commit CR-12**

```bash
git add packages/nvl/comments/src/Actions/FindLatestTargetCommentAction.php packages/nvl/comments/src/Data/Queries/CommentSelectorData.php packages/nvl/comments/tests/Feature/CommentsV1ApiProjectionTest.php packages/nvl/settings/src/Data/SettingSubjectReferenceData.php packages/nvl/settings/src/Events/SettingChanged.php packages/nvl/settings/tests/SettingsConsumerContractsTest.php packages/nvl/seo/src/Actions/GetOwnerSeoProfileAction.php packages/nvl/seo/src/Actions/GetOwnerSeoRevisionAction.php packages/nvl/seo/src/Data/SeoOwnerRevisionData.php packages/nvl/seo/tests/Feature/SeoConsumerContractsTest.php resources/js/types
git commit -m "feat: add stable cross-package read seams"
```

### Workstream acceptance gate

- [ ] Run the quality command for Activity, Mail Notifications, Translations, Comments, Settings, and SEO.
- [ ] Run `composer contracts:check` and `composer types:check`.
- [ ] Replace KPO's direct failed-mail inbox query with `ListMailNotificationsAction` using `failedOnly: true`; the existing Action already matches all four failure statuses.
- [ ] Replace KPO's mail and setting listener package-model reloads with the new event/reference context.
- [ ] Confirm KPO strict consumer audit has no Mail Notification or Setting model-query findings outside documented legacy-import bridges.
