# Tenancy Workflows Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep Forms, Templates, Comments, Activity, and Mail workflows inside their captured tenant across public requests, copies, timelines, queues, callbacks, and retention.

**Architecture:** Each package owns its resources, grants, adoption and persistence through existing public APIs. Foundation owns context establishment and lifecycle. Template catalog sharing imports independent Content and Media graphs; it never renders a live shared platform version in tenant mode.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4, Eloquent, existing Redis/cache/queue/storage infrastructure, existing mPDF rendering.

**Spec:** [Execution contracts](../specs/2026-09-16-tenancy-execution-contracts.md), [suite design](../specs/2026-09-16-configurable-tenancy-design.md), and [Content/Sites plan](2026-09-16-tenancy-content-sites.md).

## Global Constraints

- PHP 8.4 and Laravel 13 are the execution baseline.
- No new external Composer dependency is required.
- All tenancy types and runtime logic live in `Nvl\Tenancy`, including the disabled implementation. Support remains free of tenant domain logic.
- Missing context in enabled mode fails closed. Platform access, central identity use cases, and tenant operations are explicit and separate.
- Released migrations are immutable.
- Grants are inspection/import availability, never tenant ownership of platform rows.
- A supplied model is an identifier; canonically reload and validate it before reads or writes.
- Scalar tenant references are captured before dispatch/after-commit callbacks. No tenant runtime state belongs in singleton registries.
- This document authorizes planning only in the current turn. Runtime implementation, dependency changes, test execution and commits occur only in execution.

## Ordering

**Task 1 is a prerequisite for the first Auth/Media tenant proof when Activity is installed or an Activity bridge is enabled.** Foundation's own durable privileged-operation table already avoids an Activity dependency. Unintegrated bridges must deny tenant recording. Tasks 2–6 can ship independently once their real dependencies are ready: Forms needs Translatable/public admission, Templates needs Content/Media, Comments needs Media and registered target ownership, Mail needs queue/lifecycle and its actual domain factories. Do not activate the entire workflow graph after implementing only one package.

Run the early Settings/bootstrap and generic Filterable/Data tasks from [Settings/Tools](2026-09-16-tenancy-settings-tools.md) before this graph. Tenant SMTP credentials are outside this first release: provider credentials/configuration remain deployment-managed. Tenant mail may select only approved named delivery profiles without changing Laravel global config.

## Concrete test setup shared by these plans

Create each package's `tests/TenancyTestCase.php` directly from Orchestra Testbench, using `DatabaseMigrations`, **not** inheritance from its current `RefreshDatabase` case. Copy its current provider list and environment fixture configuration, insert `TenancyServiceProvider`, and set enabled/profile/resource decisions in `defineEnvironment()` before provider boot. Load core opt-in migrations explicitly from `packages/nvl/tenancy/database/migrations/tenancy`; participating packages' normal migrations still run through their own provider. Their tenant schema changes run through real adoption adapters.

Route new adopted tests under `tests/Tenancy/` to `TenancyTestCase` in each package's `tests/Pest.php`; keep existing suites routed to their current cases. Replace a broad `in(__DIR__)` with explicit existing directories/root test files so no file receives two different base classes. Do not convert existing unit/feature suites to nontransactional tests.

Create `tests/Fixtures/TenantScenario.php` within each participating package, with its package's test namespace. The Activity spelling below is the complete helper; Content/Pages/SEO/Forms/Templates/Comments/MailNotifications/Settings/Translations/CSV use their corresponding test namespace. These are local test files, so standalone package tests never import another package's development-only namespace.

```php
namespace Nvl\Activity\Tests\Fixtures;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Contracts\Foundation\Application;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use RuntimeException;

final class TenantScenario
{
    public const string A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    public const string B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    public static function bind(Application $app): void
    {
        $app->instance(TenantDirectory::class, new class implements TenantDirectory {
            public function find(TenantId $id): TenantDescriptor
            {
                if (! in_array($id->value, [TenantScenario::A, TenantScenario::B], true)) {
                    throw new TenantNotFound('Unknown test tenant.');
                }
                return new TenantDescriptor($id, TenantStatus::Active);
            }
        });
        $app->instance(PlatformAccess::class, new class implements PlatformAccess {
            public function authorize(PlatformOperation $operation): void {}
        });
        $app->instance(MaintenanceMode::class, new class implements MaintenanceMode {
            private bool $enabled = true;
            public function activate(array $payload): void { $this->enabled = true; }
            public function deactivate(): void { $this->enabled = false; }
            public function active(): bool { return $this->enabled; }
            public function data(): array { return []; }
        });
    }

    /** @param list<string> $packages */
    public static function activate(array $packages): void
    {
        $coordinator = app(TenantAdoptionCoordinator::class);
        $operation = new PlatformOperation('test-fixture-adoption', 'test', 'pest');
        $plan = $coordinator->prepare($packages, [], $operation);
        $done = false;
        for ($batch = 0; $batch < 100 && ! $done; $batch++) {
            $done = $coordinator->backfill($plan, 100, $operation);
        }
        if (! $done || ! $coordinator->verify($plan)->passed()) {
            throw new RuntimeException('Tenant fixture adoption did not verify.');
        }
        $coordinator->activate($plan, $operation);
    }
}
```

Create `packages/nvl/activity/tests/TenancyTestCase.php` with this complete base. The migration path is relative to the package's own tests directory and works in the monorepo; isolated installs instead resolve the installed Tenancy provider path with `ReflectionClass(TenancyServiceProvider::class)->getFileName()` and append `../../database/migrations/tenancy` from its directory.

```php
namespace Nvl\Activity\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Tests\Fixtures\TenantScenario;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use Spatie\Activitylog\ActivitylogServiceProvider;

abstract class TenancyTestCase extends Orchestra
{
    use DatabaseMigrations;

    protected function getPackageProviders($app): array
    {
        return [DataServiceProvider::class, SupportServiceProvider::class,
            TenancyServiceProvider::class, ActivitylogServiceProvider::class,
            ActivityServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('tenancy.enabled', true);
        $app['config']->set('tenancy.profile', 'application');
        $app['config']->set('tenancy.resources', ['activity' => 'tenant']);
        TenantScenario::bind($app);
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $provider = new ReflectionClass(TenancyServiceProvider::class);
        $this->loadMigrationsFrom(dirname($provider->getFileName()).'/../../database/migrations/tenancy');
    }

    protected function setUp(): void
    {
        parent::setUp();
        TenantScenario::activate(['activity']);
    }
}
```

Add `uses(\Nvl\Activity\Tests\TenancyTestCase::class)->in('Tenancy');` to its Pest file. For each other participating package create the same two local fixture files, substitute its namespace, copy its actual existing provider/environment/schema setup, change the `tenancy.resources` family and `activate()` package list, and route only `Tenancy/` to that case. Forms copies from `tests/FormsTestCase.php`; all others copy from `tests/TestCase.php`; Pages HTTP tests additionally copy `tests/HttpTestCase.php` route setup. Set the package's optional Activity bridge off until Activity is included in the package list. Code-backed definitions synchronize through their real platform Action after activation. Do not reuse another package's test class.

Call `TenantScenario::activate()` once after empty normal schemas/core schemas exist and before tenant rows. Coordinator derives dependency closure. Existing-data tests replace the final `setUp()` activation with their own seed/mapping/coordinator calls using real `TenantAssignment` records. Bind test adapters before resolving any context/runner singleton; production adapters remain denying by default. Do not manually create active markers. Existing custom owner fixture tables/models need resource registration before prepare, and their ownership/backfill is part of the host fixture, not a bypass.

Keep platform catalogs synchronized through an explicit `PlatformOperation` after adoption. The fixtures' allowing domain policies are existing test policies; `system()` actors still do not bypass TenantBoundary. Use `TenantRunner` for A/B switches outside open transactions.

All commands run from root, for example:

```bash
vendor/bin/pest --test-directory=packages/nvl/activity/tests --configuration=packages/nvl/activity/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/activity/tests/Tenancy/ActivityTenantTest.php
```

Every task uses write-red/run-red/implement/run-green/review-commit. Schema/worker tests use actual processes/serialization. `Queue::fake()` alone cannot satisfy an async gate. Load architecture/domain/Pest/testing skills and Boost documentation before execution.

### Task 1: Partition Activity's minimal recording/read boundary before tenant emitters

**Files:** Modify `packages/nvl/activity/src/Services/ActivityRecorder.php`, `ActivityReadService.php`, `ActivityRelationLoader.php`, `ActivitySubjectTimelineResolver.php`, `packages/nvl/activity/src/Models/ActivityLog.php`, `src/Providers/ActivityServiceProvider.php`, `src/Traits/HasModelActivity.php`; create `packages/nvl/activity/src/Tenancy/ActivityResourceRegistrar.php`, `ActivityAdoptionAdapter.php`, `packages/nvl/activity/database/tenancy-migrations/2026_09_16_160001_add_activity_ownership.php`, `packages/nvl/activity/tests/Tenancy/ActivityTenantTest.php`.

**Interfaces:** Register `activity.events` as Root with `allowsPlatformRows=true`, not as a shared catalog. Persist scalar `tenant_id` and non-null ownership discriminator on the Activity row, never only inside arbitrary JSON properties. Existing `ActivityRecorder::record` and `recordForSubjectReference` APIs retain their signatures; capture current ownership at their entry. A subjectless system event under Tenant mode is still tenant-owned. A direct reference is not authorization for reading its subject.

- [ ] Write the failing real recorder/read test after `TenantScenario::activate(['activity'])`:

```php
use Nvl\Activity\Services\ActivityReadService;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;

test('a shared subject reference does not merge tenant timelines', function (): void {
    $runner = app(TenantRunner::class);
    $subject = new ActivitySubjectReference('external_record', 'same-id');
    $record = fn () => app(ActivityRecorder::class)
        ->recordForSubjectReference($subject, 'updated', context: ['key' => 'safe']);
    $a = $runner->run(new TenantId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'), $record);
    $b = $runner->run(new TenantId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'), $record);
    $rows = $runner->run(new TenantId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
        fn () => app(ActivityReadService::class)->forSubjectKey('external_record', 'same-id'));
    expect($rows->modelKeys())->toBe([$a->getKey()])->not->toContain($b->getKey());
});
```

Add Spatie automatic model capture and model-free Settings event cases; a new writer-only guard is insufficient if automatic logging bypasses it. Test unresolved denial, explicit platform row isolation, B preloaded subject, global shared causer, and a queued listener whose context changes after event dispatch.

- [ ] Run ActivityTenantTest. Expected red: both subject rows appear together or ownership is absent.
- [ ] Apply `TenantBoundary` to all Activity queries before subject/causer OR composition, counts, causer suggestions, exports and hydration. Capture ownership at recording/event creation and add immutable event envelopes to tenant/disabled events using `TenantJobEnvelope::capture`. When a listener persists later, `TenantQueueContext::run` establishes the captured context and validates canonical subject ownership. Platform catalog/grant audit facts record synchronously inside the authorized platform operation; never capture or serialize a Platform envelope. Deferred maintenance must explicitly authorize its own bounded dispatcher. Use row tenant for projection; never derive it from a user's current membership.

```php
$ownership = $this->tenancy->attributes('activity.events');
$logger->tap(static function (ActivityContract $activity) use ($ownership): void {
    if ($activity instanceof Model) {
        $activity->forceFill($ownership);
    }
});
```

Add the required model imports in implementation. Automatic capture calls the same package-owned stamping guard. Parent/subject deletion may preserve historical rows; ownership remains on the immutable event. Hydration validates tenant-owned subjects; global causers use a narrow safe presenter, excluding credentials/account audit facts. Unregistered foreign subject types never trigger arbitrary cross-package queries. Convert context-capturing singleton services to scoped bindings; immutable mapping declarations may stay singleton.

- [ ] Rerun new tests plus Activity recorder/model-capture/timeline/API safety regressions. Run the dependency-free package install with Tenancy present and Auth absent. Expected green includes global disabled history and missing-context denial.
- [ ] Commit `feat(activity): capture and partition tenant audit facts`. This is the only workflow prerequisite required before Auth/Media emit into Activity; deeper retention follows Task 6.

### Task 2: Isolate Forms and establish context before public resolution

**Files:** Modify `packages/nvl/forms/src/Actions/Form/GetFormForRenderAction.php`, `HandlePublicFormSubmissionAction.php`, `CreateFormAction.php`, `packages/nvl/forms/src/Services/PublicFormTokenService.php`, `CustomSubmissionReceiptService.php`, `EntryCallbackRegistry.php`, `FormRateLimitService.php`, `packages/nvl/forms/src/Http/Middleware/ValidateFormHost.php`, `EnsureFormIsAvailable.php`, `packages/nvl/forms/routes/api.php`, `src/Providers/FormsServiceProvider.php`; create `packages/nvl/forms/src/Tenancy/FormsResourceRegistrar.php`, `FormsAdoptionAdapter.php`, `packages/nvl/forms/database/tenancy-migrations/2026_09_16_180010_add_forms_ownership.php`, `packages/nvl/forms/tests/Tenancy/FormTenantTest.php`, `PublicFormTenantHttpTest.php`.

**Interfaces:** Register `forms.forms` Root, and `forms.entries`, `forms.receipts`, `forms.origins`, `forms.rates`, `forms.analytics`, `forms.translations` as inherited resources. Keep `GetFormForRenderAction::execute(Form|string): Form`; always canonical-reload the model and relations. Keep `TenantSiteResolver` as the common verified host/site admission; embed `Origin`/Referer never chooses tenant. Public tokens become version 2, binding tenant UUID, site, form UUID, iat/exp/nonce. Legacy tokens are accepted only in disabled unadopted mode.

- [ ] Write this failing canonical-model test through the existing Actions. Supply B's loaded Form to A's render Action and require the same not-found result as a missing ID.

```php
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nvl\Forms\Actions\Form\CreateFormAction;
use Nvl\Forms\Actions\Form\GetFormForRenderAction;
use Nvl\Forms\Data\Mutations\MutateFormPayload;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Enums\Resolvement;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;

test('a supplied foreign form is canonically denied', function (): void {
    $runner = app(TenantRunner::class);
    $formFromB = $runner->run(new TenantId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'),
        fn () => app(CreateFormAction::class)->execute(MutateFormPayload::from([
            'handle' => 'contact', 'translations' => ['en' => ['name' => 'Contact']],
            'resolvement' => Resolvement::ENTRIES->value, 'type' => FormType::IFRAME->value,
        ])));
    expect(fn () => $runner->run(
        new TenantId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
        fn () => app(GetFormForRenderAction::class)->execute($formFromB),
    ))->toThrow(ModelNotFoundException::class);
});
```

`$formFromB` is the persisted model returned by B's CreateFormAction in this test, with origins/translations loaded before the denial. Add A/B identical handles, forged loaded relation, token/site mismatch, suspended tenant, raw ownership aliases, duplicate submission keys, analytics/export isolation, and public HTTP host/Origin conflict cases.

- [ ] Run Forms tenant tests. Expected red: supplied Form bypasses lookup, global handle uniqueness conflicts, or token has no tenant/site binding.
- [ ] Add tenant columns/constraints and canonical resolution. Group `(handle = ? OR id = ?)` beneath the ownership predicate; preserve UUID-only ID lookup and translation fallback. Public middleware priority is verified host/site → active tenant entry → Forms locale → form resolution → origin/token/availability/throttle → Action. OPTIONS follows the same trusted site mapping and does not enumerate foreign forms.

Owner equality applies to entries, receipts, origins, rates and analytics. Derive every child from the canonical Form, scope handles and rate limits, preserve entry privacy/deletion policies. Exports are private tenant artifacts with exact storage identity and authorized download. Do not use the form's foreign ownership to auto-switch a caller into another tenant.

Add `Contracts/TenantEntrySubmissionCallback.php::after(Form $form, FormEntry $entry, FormSubmissionCallbackContext $context): void` and readonly `Data/FormSubmissionCallbackContext.php` with constructor `(string $tenantId, ?string $origin, string $locale, ?string $correlationId)`. Add `EntryCallbackRegistry::registerTenant(string $handle, string $callbackClass): void` and `dispatchTenant(Form $form, FormEntry $entry, FormSubmissionCallbackContext $context): void`. Tenant registrations contain only classes implementing this new interface, resolved per dispatch. Existing `dispatch(Form,FormEntry,Request)` stays the disabled request API; Doctor rejects legacy request-dependent callbacks configured for tenant mode. Capture the immutable server-derived callback context before registering after commit:

```php
$envelope = TenantJobEnvelope::capture($this->context);
$formId = $form->id;
$entryId = $entry->id;
$connection = $form->getConnection();
$callbackContext = new FormSubmissionCallbackContext(
    $this->context->requireTenant()->value, $validatedOrigin, $validatedLocale, $correlationId,
);
$connection->afterCommit(function () use ($envelope, $formId, $entryId, $callbackContext): void {
    $this->queueContext->run($envelope, function () use ($formId, $entryId, $callbackContext): void {
        $form = $this->getForm->execute($formId);
        $entry = $this->entries->forForm($form, $entryId);
        $this->entryCallbacks->dispatchTenant($form, $entry, $callbackContext);
    });
});
```

Define new internal `FormEntryLocator::forForm(Form $form, string $entryId): FormEntry` in `src/Services/FormEntryLocator.php`; it scopes by `forms.entries`, compares the canonical Form ID/tenant, then returns or throws ModelNotFoundException. The public submission Action supplies `$validatedOrigin`, `$validatedLocale`, and optional `$correlationId` from its validated request context. Never serialize the Request or authenticated model. Import `TenantJobEnvelope` and `FormSubmissionCallbackContext` in the Action; inject its context, queue context, locator and registry through its constructor.

- [ ] Run public submission/idempotency/entry privacy tests, real callback A→B→missing transitions, and real competing submission/rate-limit claims. Verify no duplicate after-commit callback on replay.
- [ ] Commit `feat(forms): bind public submissions and callbacks to tenant ownership`.

### Task 3: Add tenant Templates and a complete catalog-copy graph

**Files:** Modify `packages/nvl/templates/src/Actions/CreateTemplateAction.php`, `AssignTemplateAction.php`, `QueueTemplateRenderAction.php`, `ProcessTemplateRenderAction.php`, `RecoverStaleTemplateRendersAction.php`, `packages/nvl/templates/src/Services/StoredTemplateRenderResolver.php`, `TemplateRenderDispatcher.php`, `TemplateOwnerRegistry.php`, `MediaTemplateAssetResolver.php`, `MediaTemplateAssetRegistry.php`, `packages/nvl/templates/src/Jobs/RenderTemplateJob.php`; create registrar/adapter, `packages/nvl/templates/database/tenancy-migrations/2026_09_16_180011_add_templates_ownership.php`, `2026_09_16_180012_create_template_tenant_grants.php`, `packages/nvl/templates/tests/Tenancy/TemplatesTenantTest.php`, `TemplateCatalogImportTest.php`.

**Interfaces:** Register `templates.templates` Root with platform catalog support, `templates.versions`, `templates.assignments`, and `templates.renders` inherited/root as appropriate. Versions derive from their template; assignments and renders always belong to the effective tenant and reference tenant-owned copies. Code registry definitions remain immutable platform declarations. Render idempotency becomes tenant-qualified. Add these exact package-owned grant/copy files:

- `Models/TemplateTenantGrant.php` and `Actions/GrantTemplateToTenantAction.php`: `execute(string $versionId, TenantId $recipient, int $sourceRevision): TemplateTenantGrant`, explicit platform only.
- `Actions/RevokeTemplateTenantGrantAction.php`: `execute(string $grantId, int $expectedRevision): TemplateTenantGrant`, explicit platform only.
- `Data/Mutations/ImportPlatformTemplateData.php`: readonly `string $grantId`, `int $expectedGrantRevision`, `int $expectedSourceRevision`, `string $targetKey`, `string $idempotencyKey`, `array<string,string> $mediaGrantIds` keyed by source Media ID.
- `Actions/ImportPlatformTemplateAction.php`: `execute(ImportPlatformTemplateData $data, TemplateActorData $actor): TemplateVersion`.

Add Content-owned `Contracts/ContentCatalogCopyAccess.php` with `assertAllowed(string $ownerId, string $group, string $grantId, TenantId $destination): void`; register class names by owner alias in `Services/ContentCatalogCopyRegistry.php::register(string $ownerAlias,string $accessClass): void`, and `resolve(string $ownerAlias): ContentCatalogCopyAccess` resolves a fresh implementation. Templates supplies `Services/TemplateContentCopyAccess.php`, which validates the exact active grant, recipient, immutable version and source revision. No Content dependency on Templates is introduced.

Content supplies `Actions/ExportContentSnapshotForCopyAction.php::execute(string $ownerAlias,string $ownerId,string $group,string $grantId): ContentSnapshotCopyData` and `Actions/ImportContentSnapshotAction.php::execute(Model&ContentOwner $target, ContentSnapshotCopyData $source, array $mediaMap, ContentActorData $actor): ContentCompositionSnapshotData`. New readonly `Data/ContentSnapshotCopyData.php` constructor is `(string $ownerAlias, string $ownerId, string $group, string $grantId, int $sourceRevision, string $sourceHash, ContentCompositionSnapshotData $snapshot, array $mediaIds)` with `list<string>` Media IDs. The snapshot contains its format version and bounded schema/value/placement records; `$mediaMap` is `array<string,string>` from source to destination Media ID. Every source access revalidates through the registered owner-copy authorizer; tenant rows are never read with general scopes removed. Import validates current source/grant again and every mapped destination Media identity, rewrites owner/block/placement IDs, then captures a new tenant snapshot. These Content files are implemented/reviewed as a coordinated Content-owned task inside this commit series.

- [ ] Write failing ordinary Template tests: A/B use identical template keys/idempotency keys; B owner/assignment/version is denied in A; stale preloaded render denied; `system()` actor cannot cross ownership. Test grant import through its exact DTO and Action, including absent asset grants, stale revision, revocation, retry, source deletion after successful copy, and B inspecting A's grant.

```php
use Nvl\Templates\Actions\ImportPlatformTemplateAction;
use Nvl\Templates\Data\Mutations\ImportPlatformTemplateData;
use Nvl\Templates\Data\TemplateActorData;

$copy = app(ImportPlatformTemplateAction::class)->execute(
    new ImportPlatformTemplateData(
        grantId: $grant->id,
        expectedGrantRevision: $grant->revision,
        expectedSourceRevision: $platformVersion->revision,
        targetKey: 'invoice',
        idempotencyKey: 'copy-invoice-1',
        mediaGrantIds: [$platformAsset->id => $assetGrant->id],
    ),
    TemplateActorData::system(),
);
expect($copy->tenant_id)->toBe($tenantA->value)
    ->and($copy->content_snapshot->tenantId)->toBe($tenantA->value)
    ->and($copy->id)->not->toBe($platformVersion->id);
```

The source version and grants are created through the real explicit-platform Actions; tenant A invokes the import. The Media graph-copy port comes from resource Task 4: inject `Nvl\Media\Contracts\MediaCatalogImport`, whose exact methods are `inspect(string $grantId): MediaCatalogSnapshot`, `stage(MediaCatalogSnapshot $source): StagedCatalogMedia`, `persist(MediaCatalogSnapshot $source, StagedCatalogMedia $file, string $idempotencyKey): Media`, and `discard(StagedCatalogMedia $file): void`. Both DTOs live in `Nvl\Media\Data`. A template grant never supplies a missing Media grant. Do not call `ImportPlatformMediaAction` to stage assets: that convenience Action completes persistence and cannot provide the consumer's atomic graph boundary.

- [ ] Run both focused Template tests. Expected red: missing import surface/global identity or cross-owner acceptance.
- [ ] Implement the copy workflow with package-owned transactions and staging:

```text
inspect Template grant/version and export authorized bounded Content graph
require recipient's exact Media grant for each ID; inspect+stage with MediaCatalogImport outside final transaction
inside final shared-connection transaction lock/revalidate Template grant and exact version first
persist staged Media in sorted grant/source-ID order; port rechecks grants/revisions/digests under locks
create tenant Template/Version; import remapped Content using owning APIs; publish/capture
persist independent provenance IDs/hashes and tenant idempotency result
commit; discard unpersisted stages on failure; port registers persisted output cleanup on root rollback
```

Keep global code definition keys unchanged. If a target template key already exists, conflict unless this is the same idempotent import; do not merge into an unrelated tenant template. Snapshot source schema/view aliases must still match registered code. Media provenance has no cascade to source; successful copies survive revocation. Shared catalog inspection exposes safe metadata only, never internal paths or another tenant's grants. General template render APIs never accept platform versions in Tenant mode.

`persist()` requires an existing transaction on the compatible normalized connection. Nested savepoint release is not successful completion: Media owns after-root-commit variation dispatch and after-root-rollback deletion of its exact staged operation/path tuple. Consumer catches staging/finalization exceptions and calls `discard()` only for stages that were not persisted; it never deletes arbitrary source/output paths. Recheck Content source hash and all grant revisions before final commit. Test a caller transaction rolling back after this Action returns, plus concurrent revoke while bytes stage, against real storage and separate database connections.

For queued renders capture `TenantJobEnvelope` in `TemplateRenderDispatcher`, preserve dispatch generation and lease token, and put tenant on durable render. Foundation wraps both queue `call()` and `failed()` before deserialization; `RenderTemplateJob::failed()` queries under that restored context and checks persisted ownership before updating. Envelope capture in `afterCommit` occurs at registration time. Recovery/scheduler commands run bounded per-tenant batches. Do not retain tenant asset aliases or resolved renderers in singleton registries; code alias registries resolve instances under the current context.

PDF source views remain trusted code, and existing nested CSS/SVG asset restrictions stay enforced. Tenant output/temp paths include tenant/work identity; persisted output records include actual disk/path/checksum. Tenant-local paths alone do not authorize a download. Retry cannot re-read newly revoked platform assets because rendering uses independent copies.

- [ ] Run real render workers A→B→unresolved, retry, max-attempt failure before handle, after-commit failure, stale recovery, PDF asset fetch and copy-revocation races. Include Redis locks and actual storage checksum cleanup. Expected green: independent full graph and no residual process context.
- [ ] Commit the owner-scoped Content copy seam separately if useful, then `feat(templates): import isolated tenant template graphs` after both package tests pass.

### Task 4: Isolate Comments targets, replies, mentions, projections, and attachments

**Files:** Modify `packages/nvl/comments/src/Services/CommentTargetRegistry.php`, `CommentCreationWriter.php`, `CommentMutationLock.php`, `EloquentCommentMentionResourceResolver.php`, `CommentMentionResourceRegistry.php`, `packages/nvl/comments/src/Http/Middleware/CommentsResponseCache.php`, all existing comment/revision/report/reaction/attachment Actions; create `packages/nvl/comments/src/Tenancy/CommentsResourceRegistrar.php`, `CommentsAdoptionAdapter.php`, `packages/nvl/comments/database/tenancy-migrations/2026_09_16_180013_add_comments_ownership.php`, `packages/nvl/comments/tests/Tenancy/CommentsTenantTest.php`.

**Interfaces:** Comments and all independently queried revisions/reactions/reports/metadata/mention projections inherit canonical target tenant. Existing `CommentQueryScope` adds host constraints after mandatory ownership. Add `Contracts/CommentMentionTenantProjection.php::scope(Builder $query, CommentMentionContext $context, TenantId $tenant): void` for intentionally global principal catalogs. Every other mention resource uses its registered ownership. The Auth/host implementation limits global principals to active tenant membership and safe exposed fields; no Comments→Auth dependency.

- [ ] Write failing tests through `CreateCommentAction::execute(Model $target, CreateCommentData $data, CommentActorData $actor, CommentAudience $audience): Comment`: target B, parent B, reaction/comment mismatch, foreign attachment, preloaded target, identical A/B idempotency UUID, and restoration of a foreign revision all fail or remain independent as appropriate. Example denial:

```php
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

expect(fn () => app(CreateCommentAction::class)->execute(
    $targetFromB, new CreateCommentData(body: 'wrong tenant'), CommentActorData::system(),
))->toThrow(TenantBoundaryViolation::class);
```

The target comes from its owning package Action in B and this assertion runs in A. HTTP tests separately require enumeration-neutral 404. Add global shared-user mention suggestion tests: membership in B alone produces neither label nor ID in A; removing A membership removes suggestions/live projection without rewriting historical comment ownership.

- [ ] Run CommentsTenantTest. Expected red: target ownership or idempotency uniqueness has no tenant boundary.
- [ ] Add inherited columns, composite constraints where concrete, tenant-aware idempotency/identity/lock keys, and canonical target resolution at every public read/write boundary. Check ownership before optional host query scope, filters, count or pagination. A host scope cannot relax mandatory isolation. Keep public/member/management DTO separation and opaque audience-specific author keys. Preserve tombstones and anonymity behavior.

```text
canonical target -> registered ownership -> tenant predicate
host CommentQueryScope -> ability check -> revision/counter locks -> mutation
mention: mandatory resource ownership OR registered global-membership projection -> host authorization -> safe fields
attachment: same target/comment tenant -> Media Action -> private/public projection policy
```

Public shared-cache responses include tenant/site/host in cache identity and validator inputs; member/admin/assets remain private/no-store. Signed attachment capabilities bind tenant and canonical comment/media identity. Anonymization and retention never enumerate another tenant's author rows. Cross-connection relationships remain supported when tenancy is disabled; adopted writes require compatible normalized connection and fail Doctor otherwise.

- [ ] Run comments mention, projection, idempotency, concurrency and lifecycle suites; then real same-key creation/reaction races and sequential request cache cases. Expected green: membership-safe mentions and complete target inheritance.
- [ ] Commit `feat(comments): isolate tenant discussion graphs and mentions`.

### Task 5: Bind Mail scheduling, tracking, factories, and provider callbacks to persisted ownership

**Files:** Modify `packages/nvl/mail-notifications/src/Services/ScheduledMailScheduler.php`, `ScheduledMailClaimer.php`, `ScheduledMailProcessor.php`, `ScheduledMailFinalizer.php`, `ScheduledMailRecovery.php`, `ScheduledMessageFactoryRegistry.php`, `TrackingRuntime.php`, `WebhookProcessor.php`, `MailNotificationNotifiableTypeRegistry.php`, package storage/lifecycle implementations and `src/Providers/MailNotificationsServiceProvider.php`; create registrar/adapter, `packages/nvl/mail-notifications/database/tenancy-migrations/2026_09_16_180014_add_mail_ownership.php`, `packages/nvl/mail-notifications/tests/Tenancy/MailTenantTest.php`, `MailTenantWorkerTest.php`.

**Interfaces:** Register scheduled messages, mail notifications and delivery events with `allowsPlatformRows=true`, not shared catalogs. Persist ownership when scheduling/recording; provider events inherit the verified stored notification tenant. Preserve provider/message/event uniqueness under the first release's platform-managed provider account model. Do not prefix external provider IDs with tenant IDs. `ScheduledMessageFactoryRegistry` stores class names/aliases and resolves a factory for each invocation after context, never a tenant-scoped factory object retained at provider boot.

- [ ] Write failing tests through the existing ScheduledMailScheduler/Processor public API: A/B deliveries use the same factory alias and global recipient identity but render their own payload, locale and settings. Serialize actual delivery jobs and prove fresh factory resolution A→B. Forged notifiable B, schedule ID B, cancellation/recovery ID B and tracking preview/link B under A all fail before disclosure or side effects.

For the factory capture regression use two distinct context-dependent test factory instances registered by **class**, not object. The test factory's `make(ScheduledMessageData $data): Mailable` reads a tenant-owned setting through the scoped Settings repository and builds a test Mailable from scalar data. Assert exact received message content using the existing SMTP integration fixture rather than only mocking the registry.

- [ ] Run MailTenantTest and the focused factory registry test. Expected red: singleton instance reuse or globally claimed scheduled rows.
- [ ] Add ownership fields and guards to package storage contracts/implementations, schedule input validation, claim queries and terminal updates. `ScheduledMailProcessor::process(?int $limit): int` runs only inside one current tenant; platform scheduler explicitly enumerates bounded active tenants through TenantRunner, then calls it. Delivery happens after the claim transaction, within the same tenant execution lifetime. Expired claim/retry paths preserve ownership and claim-token comparisons.

```text
schedule under admitted tenant -> persist tenant + JSON-only versioned factory payload
claim within tenant -> commit claim -> resolve fresh factory -> validate recipient/notifiable -> send
tracking row captures tenant from that delivery, not from recipient/global user
verified webhook -> exact stored provider/correlation identity -> recover stored ownership
enter that bounded tenant context -> apply event transition -> restore context
```

Use a narrow internal verified-delivery locator for webhook bootstrap; it returns only stored identity/ownership after provider signature verification. It never trusts a webhook tenant field. Conflicting provider/correlation identities retain existing ambiguity denial. For suspended/deleted tenants, return the provider adapter's retryable-unavailable response before changing notification/event state; retries may succeed after reactivation. Verify the adapter's HTTP status/retry contract in its existing webhook tests. Do not acknowledge successful persistence or invoke maintenance admission online. Offline reconciliation/retention may use the foundation's `TenantMaintenanceRunner::run(TenantId, PlatformOperation, Closure)` while app maintenance mode is active and platform authorization/audit passes; its lease preserves exactly one tenant's predicates and is never serialized into an envelope.

Central Auth mail uses a dedicated scalar global-identity job registered in `TenantGlobalJobRegistry`, not generic `SendQueuedMailable`/`SendQueuedNotifications`. The narrow mail identity adapter records only that approved central flow's platform facts; it does not grant tenant data access under Unresolved context. Platform operations record their audit facts synchronously inside the authorized operation; a Platform `TenantJobEnvelope` is forbidden. Platform operations and tenant delivery queues are never interchangeable. Preserve the existing global mail testing interceptor and provider secrets in deployment configuration.

Register mail state cleanup through `TenantContextParticipant`; clear transient TrackingRuntime correlations/weak maps at the proper lifecycle boundary and resolve runtime state under the current operation. Preserve existing host CallQueuedHandler binding only if foundation's pre-deserialization call/failed contract is satisfied. Never clear context at JobFailed before the job's `failed()` handler completes.

- [ ] Run scheduled delivery/queued failure/SMTP/provider webhook tests plus real A→B→missing worker, exhausted attempts before handle, retry, webhook after suspension, and unknown/ambiguous provider identity. Verify no tenant credentials/payload in operational logs.
- [ ] Commit `feat(mail-notifications): retain tenant identity across delivery lifecycle`.

### Task 6: Add retention, adoption, and end-to-end workflow evidence

**Files:** Modify `packages/nvl/activity/src/Jobs/PurgeActivityLogsJob.php`, purge Actions/commands, `packages/nvl/mail-notifications/src/Services/MailRetentionPruner.php`, `MailHistoryAnonymizer.php`, workflow registrars/adapters/Doctor commands; add `tests/Feature/Integration/TenantWorkflowJourneyTest.php` and each package's `tests/Tenancy/AdoptionTest.php`. Update relevant README/UPGRADING/CHANGELOG/canonical skills, manifests, consumer audit and package contracts.

**Interfaces:** Each adapter implements exact `TenantAdoptionAdapter::{resources,prepare,backfill,verify,activate}`; use `TenantAdoptionMappings` for independent root/event rows and canonical parent derivation for children. Audit/mail historical rows whose tenant cannot be proven require explicit reviewed mapping/disposition; global user ID alone is insufficient. Platform-history assignment is a deliberate platform disposition, never a fallback for ambiguity.

- [ ] Write failing adoption and lifecycle tests: tenant A public Form submission → tenant entry callback → tenant Template render → tenant Mail → tenant Comment/Activity projection; B shares global user and business keys but sees none. Rehearse suspended/deleted tenants, grant revocation, retained files, tenant-specific export, legacy queues and existing-data ambiguity. Adoption tests invoke prepare/backfill/verify/activate and resume through the real coordinator.
- [ ] Run those tests plus the actual purge job serialized under a tenant envelope. Expected red: unscoped retention or incomplete ownership mapping.
- [ ] Partition retention criteria, purge/overlap locks, work counts and storage cleanup. A platform command emits one bounded tenant job per admitted tenant, using scalar envelopes; no implicit all-tenant query or bypassed scope. Tenant deletion is resumable package cleanup with explicit retention/export policy; it does not delete global principals or other memberships. Long-lived external links remain bearer capabilities until expiry unless served through a revocable proxy; plan cutover/revocation accordingly.
- [ ] Run affected package regressions, `vendor/bin/pint --dirty --format agent`, PHPStan and package-contract checks. Run actual SQLite/PostgreSQL/MySQL/MariaDB schema/constraint cases, Redis/storage/SMTP and workers, copy/revocation and submission races on separate connections, cached boot/routes, standalone Composer and archive installs. Check disabled behavior and lazy marker overhead separately from existing query budgets.
- [ ] Review and commit `test(tenancy): verify workflow adoption and lifecycle isolation`. Do not mark a package tenant-ready until all its exposed entry points and optional integrations pass.

## Acceptance evidence

Record complete command/results for the early Activity prerequisite separately from later workflow features. Full completion requires actual serialization/failure callbacks, complete immutable template-copy provenance, no process-wide Config mutation, neutral public denials, package-owned adoption/cleanup, disabled standalone compatibility, and a rehearsed two-tenant end-to-end journey.
