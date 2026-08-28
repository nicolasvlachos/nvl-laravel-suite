# Proof Consumers and KPO Adoption Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove the new contracts in clean applications, migrate KPO without suppressions, and turn those workflows into release gates.

**Architecture:** Two production-style fixtures exercise coherent module profiles from a sealed suite artifact. KPO migrates in independent reversible commits only after each suite API passes its package gate; strict consumer audit is the boundary acceptance test.

**Tech Stack:** PHP 8.4/8.5, Laravel 12/13, Composer archives/path repositories, Pest 4, TypeScript, SQLite/PostgreSQL/MySQL/MariaDB, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-08-28-consumer-readiness-v2-design.md`

## Global Constraints

- Do not begin KPO edits from its currently dirty working tree.
- At execution time, identify with the user which KPO commit includes the in-progress Website/Mail/UI work, then create an isolated worktree from that commit.
- Never reset, overwrite, stash, or commit unrelated KPO changes.
- KPO file lists use `/Users/nicolasvlachos/Herd/kpo` as the canonical source location; apply those relative paths inside the approved isolated worktree, not the dirty source checkout.
- Fixture installs use a copied archive (`symlink: false`), not the suite working tree.
- Both migration ownership modes are tested; they are never mixed in one database.
- Every fixture and KPO wave passes strict suite Doctor, strict consumer audit, config cache, route cache, and generated-type checks.
- A suppression is not an acceptance mechanism for a new consumer workflow.

---

### Task 1 (CR-19): Build the Auth production consumer

**Files:**
- Create: `tools/fixtures/auth-production-consumer/app/Providers/AuthConsumerServiceProvider.php`
- Create: `tools/fixtures/auth-production-consumer/app/Models/User.php`
- Create: `tools/fixtures/auth-production-consumer/app/Auth/AuthConsumerProbe.php`
- Create: `tools/fixtures/auth-production-consumer/app/Console/Commands/AuthConsumerSmokeCommand.php`
- Create: `tools/fixtures/auth-production-consumer/bootstrap/providers.php`
- Create: `tools/fixtures/auth-production-consumer/config/nvl-suite.php`
- Create: `tools/fixtures/auth-production-consumer/config/nvl-auth.php`
- Create: `tools/fixtures/auth-production-consumer/config/settings.php`
- Create: `tools/fixtures/auth-production-consumer/config/activity.php`
- Create: `tools/fixtures/auth-production-consumer/config/mail-notifications.php`
- Create: `tools/fixtures/auth-production-consumer/typescript/auth-consumer.ts`
- Create: `tools/fixtures/auth-production-consumer/typescript/tsconfig.json`
- Create: `tools/run-auth-production-consumer.sh`
- Create: `tests/Contract/AuthProductionConsumerWorkflowTest.php`

**Interfaces:**
- Consumes: CR-04 configure/audit commands, CR-05–08 Auth reads, CR-09 Activity, CR-10 Mail, CR-12 Settings identity.
- Produces: a sealed-artifact smoke command covering Auth, Settings, Activity, and Mail Notifications.

- [ ] **Step 1: Write the failing fixture contract test**

```php
expect($fixtureRoot.'/app/Auth/AuthConsumerProbe.php')->toBeFile()
    ->and(file_get_contents($fixtureRoot.'/app/Auth/AuthConsumerProbe.php'))
    ->not->toContain('Role::query(', 'Permission::query(', 'Setting::query(');
```

Assert exact fixture files, enabled module flags, authorization bindings,
TypeScript DTO usage, runner commands, package/application migration modes, and
strict audit/Doctor invocations.

- [ ] **Step 2: Run the contract test and verify the fixture is absent**

Run: `php artisan test --compact tests/Contract/AuthProductionConsumerWorkflowTest.php`

Expected: FAIL because the fixture and runner do not exist.

- [ ] **Step 3: Implement the fixture provider and probe**

Enable exactly Support, Data, Auth, Settings, Activity, and Mail Notifications
plus transitive dependencies. Bind real deny-by-default authorization adapters,
register one principal, one role template, Activity mapping, Settings catalog,
mail notifiable alias, and array transport. The probe must:

- create a principal through Auth Actions;
- bootstrap RBAC and call every CR-05–08 read;
- set/reset one setting and record Activity from its subject reference;
- track one accepted and one failed mail;
- read failed mail with `ListMailNotificationsAction(failedOnly: true)`;
- assert mailer/category statistics and safe correlation;
- assert denied actors see none of the management data.

- [ ] **Step 4: Implement a dry-run-first production runner**

Follow the existing Comments production runner pattern. Create a fresh Laravel
13 application in a temporary directory, install the copied archive, copy the
fixture, run package-owned migrations, config/route cache, strict Doctor,
strict audit, type generation/check, TypeScript compile, and smoke command.
Repeat after rollback using published application-owned migrations. Never use a
symlink and never use `QUEUE_CONNECTION=sync` for the queued-mail pass.

- [ ] **Step 5: Run the fixture contract and production runner**

Run: `php artisan test --compact tests/Contract/AuthProductionConsumerWorkflowTest.php`

Run: `bash tools/run-auth-production-consumer.sh`

Expected: PASS in both migration modes with no audit suppression.

- [ ] **Step 6: Commit CR-19**

```bash
git add tools/fixtures/auth-production-consumer tools/run-auth-production-consumer.sh tests/Contract/AuthProductionConsumerWorkflowTest.php
git commit -m "test: add Auth production consumer"
```

### Task 2 (CR-20): Build the Content production consumer

**Files:**
- Create: `tools/fixtures/content-production-consumer/app/Providers/ContentConsumerServiceProvider.php`
- Create: `tools/fixtures/content-production-consumer/app/Models/Article.php`
- Create: `tools/fixtures/content-production-consumer/app/Pages/ArticlePageResourceHandler.php`
- Create: `tools/fixtures/content-production-consumer/app/Content/ContentConsumerProbe.php`
- Create: `tools/fixtures/content-production-consumer/app/Console/Commands/ContentConsumerSmokeCommand.php`
- Create: `tools/fixtures/content-production-consumer/bootstrap/providers.php`
- Create: `tools/fixtures/content-production-consumer/config/nvl-suite.php`
- Create: `tools/fixtures/content-production-consumer/config/content.php`
- Create: `tools/fixtures/content-production-consumer/config/media.php`
- Create: `tools/fixtures/content-production-consumer/config/pages.php`
- Create: `tools/fixtures/content-production-consumer/config/seo.php`
- Create: `tools/fixtures/content-production-consumer/config/metafields.php`
- Create: `tools/fixtures/content-production-consumer/config/translatable.php`
- Create: `tools/fixtures/content-production-consumer/config/translations.php`
- Create: `tools/fixtures/content-production-consumer/typescript/content-consumer.ts`
- Create: `tools/fixtures/content-production-consumer/typescript/tsconfig.json`
- Create: `tools/run-content-production-consumer.sh`
- Create: `tests/Contract/ContentProductionConsumerWorkflowTest.php`

**Interfaces:**
- Consumes: CR-04 audit/adoption, CR-12 SEO, CR-13–16 editor/Page APIs, and CR-17/18 Media slot workflows.
- Produces: a sealed-artifact smoke command covering Pages, Content, Media, SEO, Metafields, Translatable, and Translations.

- [ ] **Step 1: Write the failing fixture contract test**

```php
$probe = file_get_contents($fixtureRoot.'/app/Content/ContentConsumerProbe.php');

expect($probe)->not->toContain(
    'Page::query(',
    'ContentBlock::query(',
    'ContentPlacement::query(',
    'Media::query(',
    'SeoProfile::query(',
);
```

Assert exact bindings/aliases, bilingual locales, page resource handler, document
slot declaration, editor DTOs, TypeScript contracts, runners, and audit gates.

- [ ] **Step 2: Run the contract test and verify the fixture is absent**

Run: `php artisan test --compact tests/Contract/ContentProductionConsumerWorkflowTest.php`

Expected: FAIL because the fixture and runner do not exist.

- [ ] **Step 3: Implement the fixture's golden workflow**

The probe must:

- create bilingual static/resource pages through Page Actions;
- create/publish Content blocks and place/reorder/replace them through CR-14;
- initialize the complete CR-16 editor bootstrap;
- resolve public navigation, children, static page publication, and resource page;
- set/read localized Metafields and SEO through package Actions;
- stage, replace, copy, read, clear, replay, and conflict a private PDF slot;
- scan/import/update/export a UI translation key;
- assert locale fallback provenance and strict authorization denial;
- compare editor query counts for one and twenty-five placements.

- [ ] **Step 4: Implement both migration ownership and storage passes**

The production runner follows CR-19 but additionally runs Media with local
private storage and queued variation effects, checks file existence before and
after rollback, runs the Media operation-prune command, and validates Pages /
Content / Media / SEO / Metafields / Translatable / Translations Doctors.

- [ ] **Step 5: Run the fixture contract and production runner**

Run: `php artisan test --compact tests/Contract/ContentProductionConsumerWorkflowTest.php`

Run: `bash tools/run-content-production-consumer.sh`

Expected: PASS in both migration modes with no direct-query finding.

- [ ] **Step 6: Commit CR-20**

```bash
git add tools/fixtures/content-production-consumer tools/run-content-production-consumer.sh tests/Contract/ContentProductionConsumerWorkflowTest.php
git commit -m "test: add Content production consumer"
```

### Task 3 (CR-21a): Prepare a safe KPO migration baseline

**Files:**
- Inspect only: `/Users/nicolasvlachos/Herd/kpo`
- Create in the selected KPO worktree: `docs/nvl-suite-consumer-migration-evidence.md`
- Modify in the selected KPO worktree: `composer.json`
- Modify in the selected KPO worktree: `composer.lock`
- Modify in the selected KPO worktree: `config/nvl-suite.php`

**Interfaces:**
- Consumes: a user-confirmed KPO commit and the sealed suite candidate archive.
- Produces: an isolated KPO worktree on a `codex/` branch with reproducible baseline/audit evidence.

- [ ] **Step 1: Stop if the KPO baseline has not been selected**

Run: `git -C /Users/nicolasvlachos/Herd/kpo status --short`

Expected today: a large dirty tree including Website, Mail Notifications, UI,
generated translations/types, and unrelated modules. Do not stash, reset, or
create the migration branch from an older commit. Ask the user to identify the
commit/worktree containing the intended baseline.

- [ ] **Step 2: Create an isolated KPO worktree after baseline approval**

Use `superpowers:using-git-worktrees`, branch prefix `codex/`, and the approved
commit. Confirm `git status --short` is empty inside the new worktree.

- [ ] **Step 3: Install the copied suite artifact**

Configure a Composer path repository with `symlink: false` and the exact suite
candidate version, update only `nvl/laravel-suite` and required dependencies,
and assert `vendor/nvl/laravel-suite` is not a symlink. Add
`adoption.require_explicit_module_decisions => true`; preserve KPO's explicit
seventeen enabled and three disabled module flags.

- [ ] **Step 4: Record the baseline gates**

Run in KPO:

```bash
php artisan optimize:clear
php artisan nvl:suite:upgrade:check --strict --format=json
php artisan nvl:suite:doctor --strict --format=json
php artisan nvl:suite:consumer-audit --format=json
php artisan test --compact tests/Feature/Package/NvlSuiteConsumptionGuidesTest.php tests/Feature/CanonicalApplicationHostTest.php
```

Record command, exit code, finding-code counts, suite archive checksum, KPO
commit, PHP/Laravel versions, and timestamp in the evidence document. Do not
copy source excerpts or secrets.

- [ ] **Step 5: Commit the isolated baseline**

```bash
git add composer.json composer.lock config/nvl-suite.php docs/nvl-suite-consumer-migration-evidence.md
git commit -m "chore: prepare suite consumer migration"
```

### Task 4 (CR-21b): Migrate KPO Auth and event seams

**Files:**
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Http/Controllers/Auth/Api/RolesApiController.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Http/Controllers/Auth/Api/PermissionsApiController.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Http/Controllers/Auth/RolesController.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Http/Controllers/Auth/PermissionsController.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Http/Controllers/Auth/RolesActionsController.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Http/Controllers/Auth/UsersController.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Actions/Auth/CreateApplicationRoleAction.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Actions/Auth/CreateApplicationPermissionAction.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Services/Auth/UserMutationNormalizer.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Support/Auth/KpoSystemRoleTemplates.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Data/Auth/Permissions/AssignPermissionsData.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Data/Auth/Permissions/CreatePermissionData.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Data/Auth/Permissions/PermissionData.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Data/Auth/Permissions/UpdatePermissionData.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Data/Auth/Roles/CloneRoleData.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Data/Auth/Roles/CreateRoleData.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Data/Auth/Roles/CreateRoleFromTemplateData.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Data/Auth/Roles/RoleData.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Data/Auth/Roles/UpdateRoleData.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Data/Auth/Users/CreateUserData.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Data/Auth/Users/UpdateUserData.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Data/Auth/Users/UsersSuggestionsQueryData.php`
- Delete after callers migrate: `/Users/nicolasvlachos/Herd/kpo/app/Services/Auth/RbacPresentationReadService.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Listeners/MailNotifications/LinkReminderOccurrenceMailNotification.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Listeners/Settings/RecordSettingActivity.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Support/MailNotifications/MailNotificationTimelineProvider.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/MailNotifications/app/Mail/ReminderOccurrenceMail.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Admin/app/Services/Read/OperationsInbox/FailedMailNotificationsInboxReader.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Admin/app/Services/Read/CommunicationsCenterReadService.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Admin/app/Services/Read/RevSessionControlTowerReadService.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Admin/tests/Feature/OperationsControllerTest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Admin/tests/Feature/CommunicationsControllerTest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Admin/tests/Feature/RevSessionControllerTest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/tests/Feature/Auth/Users/RolesActionsTest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/tests/Feature/Auth/Users/PermissionsActionsTest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/tests/Feature/Auth/Users/RolesApiControllerTest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/tests/Feature/Auth/Users/PermissionsApiControllerTest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/tests/Feature/Auth/Users/UsersControllerTest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/MailNotifications/tests/Feature/MailNotificationActionsTest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/tests/Feature/Package/NvlActivityIntegrationTest.php`

**Interfaces:**
- Consumes: CR-05–12 package APIs.
- Produces: KPO controller responses with existing JSON shapes and no presentation-service package queries.

- [ ] **Step 1: Lock existing transport shapes with failing adapter tests**

For each Auth endpoint, assert current `data`/`meta` keys, pagination, grouped
permissions, minimum search length, name-availability messages, and denial. For
events, assert the reminder is linked and setting Activity is recorded with no
package-model query. Lock the Operations failed-mail section and Communications
mailer breakdown for all four failure statuses, ordering, item limits, and
authorization.

- [ ] **Step 2: Replace RBAC reads endpoint by endpoint**

Inject the matching Auth Action, map its DTO directly into KPO's existing JSON
shape, and run the focused endpoint test after each method. Use the new catalog
Actions for index and user-access catalogs; option/suggestion/group Actions for
pickers; package show reads for individual identity; and the availability
Action for name checks. Keep KPO translation messages and route contracts;
remove only duplicate query/filter code. Compose role Activity through
`ActivityReadService`, not Auth.

- [ ] **Step 3: Replace RBAC validation-table and assignment workarounds**

Remove every `Role::TABLE`/`Permission::TABLE` existence or uniqueness rule
from the listed KPO Data classes. Retain structural UUID/string validation, then
batch-resolve submitted identifiers through CR-07 inside the application
Action so error messages can be translated without exposing package tables.
Use `AddRolePermissionsAction`/`SyncRolePermissionsAction` in
`RolesActionsController`, resolve permission IDs before `CreateRoleAction`, and
use `CreatePermissionWithRolesAction` instead of the current consumer-owned
role loop. Replace `UserMutationNormalizer` RBAC lookup methods with CR-07 and
remove the persisted Permission query from `KpoSystemRoleTemplates`; first
prove every required KPO permission is registered through
`PermissionCatalogRegistry`, and register any missing declarations explicitly.
Require no direct Role/Permission query in these files.

- [ ] **Step 4: Replace Mail administrative and timeline queries**

Use `ListMailNotificationsAction` with `MailNotificationReadQuery(failedOnly:
true)` in `FailedMailNotificationsInboxReader`, preserving the current section
shape and explicit page limit. Use CR-10 statistics with the existing `from`
filter for the Communications mailer breakdown. In
`MailNotificationTimelineProvider`, obtain notification IDs through the
existing `ListMailNotificationsForNotifiableAction` and pass bounded
`ActivitySubjectReference` values to CR-09's multi-subject Activity read. Do
not query either package model. Replace the REV session control-tower Activity
query with `ActivityIndexFilter(events: [...])` and `ActivityReadService` while
preserving the three event types and history limit.

- [ ] **Step 5: Replace mail and setting model reloads**

Add `reminder_occurrence_id` through `TrackingContext::withCorrelation()` in
the reminder Mailable. Read `$event->correlation` in the listener. Map
`SettingChanged::$subject` to `ActivitySubjectReference` and call
`ActivityLog::recordForSubjectReference()`.

- [ ] **Step 6: Remove the presentation service after all callers migrate**

Run `rg -n 'RbacPresentationReadService' app tests Modules`; require no output,
then delete the service. Do not delete package model policies or route-binding
type hints that remain documented identity contracts.

- [ ] **Step 7: Run KPO focused quality and audit**

Run the exact Auth/Mail/Activity tests above, `composer quality:phpstan`, and
`php artisan nvl:suite:consumer-audit --strict`. Require the corresponding Auth,
Mail, and Settings findings to be absent: all Role/Permission persistence
queries, the failed-mail/timeline queries, and the Setting reload. Remaining
documented 1.x compatibility warnings (for example invitation-model reads) are
recorded for CR-24 rather than hidden by a suppression.

- [ ] **Step 8: Commit CR-21b**

```bash
git add app/Actions/Auth app/Data/Auth app/Http/Controllers/Auth app/Listeners app/Support/MailNotifications Modules/Admin/app/Services/Read Modules/Admin/tests/Feature Modules/MailNotifications/app/Mail Modules/MailNotifications/tests tests/Feature/Auth tests/Feature/Package/NvlActivityIntegrationTest.php
git add -u app/Services/Auth/RbacPresentationReadService.php
git commit -m "refactor: consume suite RBAC and event APIs"
```

### Task 5 (CR-21c): Migrate KPO Pages and Content composition

**Files:**
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/app/Actions/CreateWebsitePageAction.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/app/Actions/UpdateWebsitePageAction.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/app/Actions/CreateWebsiteBlockAction.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/app/Actions/UpdateWebsiteBlockAction.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/app/Actions/DeleteWebsiteBlockAction.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/app/Http/Controllers/Admin/WebsitePagesController.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/app/Http/Controllers/Admin/WebsiteTranslationsController.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/app/Http/Requests/WebsitePageRequest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/database/seeders/WebsiteDatabaseSeeder.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/app/Http/Controllers/Public/WebsitePageController.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/app/Services/Read/PublicEventLandingReadService.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/app/Services/Read/PublicEventIndexReadService.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/app/Services/Read/PublicNewsIndexReadService.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/tests/Feature/WebsiteAdminActionsTest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/tests/Feature/PublicWebsiteDeliveryTest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Website/tests/Feature/WebsiteTranslationTest.php`

**Interfaces:**
- Consumes: CR-13–16 Content/Page APIs and KPO's existing resource handlers/presentation DTOs.
- Produces: unchanged Website transport behavior with package-owned editor/public reads.

- [ ] **Step 1: Review the approved baseline diff before editing**

The current KPO working tree already contains substantial Website work. Compare
the approved commit with its parent and identify exact behavior that must be
preserved: public routes, Inertia props, event/news resource handlers, bilingual
fallback, form payloads, and action transactions.

- [ ] **Step 2: Lock current Website behavior with focused tests**

Add assertions for admin editor bootstrap, content reorder/replace conflicts,
public static/resource delivery, child navigation, SEO/metafields, locale
fallback, page-key/parent validation, translation filter parity,
missing/expired pages, and query ceilings.

- [ ] **Step 3: Replace only package-owned reads and placement workflows**

Use `ListPageEditorSummariesAction`, `GetPageEditorBootstrapAction`,
`FindPageByKeyAction`, `ListPublicChildPagesAction`,
`GetPagePublicationProjectionAction`, and CR-14 placement Actions. Build the
translation counters with CR-11 and inject its filter schema into the Website
translation workspace. Replace the Page table rules in `WebsitePageRequest`
with structural validation followed by CR-15 availability/parent resolution in
the Action. Use CR-12's SEO revision read instead of navigating `seoProfiles`.
Rewrite `WebsiteDatabaseSeeder` to use system actor data plus Page/Content
Actions, `FindPageByKeyAction`, `FindContentBlockByKeyAction`, and
`FindContentPlacementAction`; seeders must not query or write package models.
Keep KPO-owned resource queries/presenters and page-specific business rules. Do
not move KPO event/news vocabulary into the suite.

- [ ] **Step 4: Run Website and audit gates**

Run the three Website tests, KPO PHPStan, TypeScript checks, and strict consumer
audit. Require no Pages/Content/SEO/Metafields model-query finding in the
Website module.

- [ ] **Step 5: Commit CR-21c**

```bash
git add Modules/Website/app Modules/Website/tests
git commit -m "refactor(website): consume suite page editor APIs"
```

### Task 6 (CR-21d): Migrate KPO document Media workflows

**Files:**
- Rename/modify: `/Users/nicolasvlachos/Herd/kpo/app/Concerns/HasSingleDocumentMedia.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Kpo/app/Models/UserDocument.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Participations/app/Models/ParticipationDocument.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Rev/app/Models/AnnualRecordDocument.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Kpo/app/Actions/Workspace/UpsertUserDocumentAction.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Participations/app/Actions/Mutations/RepairParticipationDocumentAction.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Rev/app/Actions/AnnualCompliance/UpsertAnnualRecordDocumentAction.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Rev/app/Services/AnnualCompliance/AnnualRecordDocumentSlotUpdater.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/app/Providers/NvlSuiteServiceProvider.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Kpo/tests/Feature/CandidacyLifecycleActionsTest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Participations/tests/Feature/ParticipationDocumentRepairTest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/Modules/Rev/tests/Feature/RevAnnualComplianceActionsTest.php`
- Modify: `/Users/nicolasvlachos/Herd/kpo/tests/Feature/Package/NvlSuiteConsumptionGuidesTest.php`

**Interfaces:**
- Consumes: CR-17/18 Media Actions and KPO Media authorization.
- Produces: three document domains that declare slot policy locally but delegate every lifecycle invariant to Media.

- [ ] **Step 1: Lock all three document workflows with tests**

For each domain, cover attach, same-ID no-op, replace, clear, copy/regenerate,
invalid MIME/size, foreign staging ownership, administrator adoption, rollback,
and idempotency replay. Preserve existing response/DTO fields.

- [ ] **Step 2: Reduce the consumer concern to declaration/read convenience**

Rename it to `DeclaresSingleDocumentMediaSlot`. Keep only the slot name,
`registerMediaSlots()`, allowed MIME/size policy, `documentMediaId()`,
`documentMedia()`, and the owner-model `has document` scope. Remove direct Media
queries, association queries, staging cleanup, force deletion, copying, and
authentication checks.

- [ ] **Step 3: Inject package Actions into domain mutation services**

Use `ReplaceOwnerMediaSlotAction`/`ClearOwnerMediaSlotAction` for user and
participation documents; use `CopyOwnerMediaSlotAction` for annual report
regeneration. Generate idempotency UUIDs from KPO request/job identities. Map
the authenticated principal to `MediaActorData`; scheduled work uses a trusted
system actor only after KPO policy explicitly allows it.

- [ ] **Step 4: Add the new Media authorization ability**

Update KPO's Media policy/binding so `ManageStaging` is limited to the existing
administrative role and system regeneration path. Normal users may adopt only
their own staging assets.

- [ ] **Step 5: Run document, storage, and audit gates**

Run the three focused tests, Media package integration tests in KPO, PHPStan,
and strict consumer audit. Require no lifecycle finding in the reduced concern.

- [ ] **Step 6: Commit CR-21d**

```bash
git add app/Concerns app/Providers/NvlSuiteServiceProvider.php Modules/Kpo Modules/Participations Modules/Rev tests/Feature/Package/NvlSuiteConsumptionGuidesTest.php
git commit -m "refactor: delegate document slots to Media"
```

### Task 7 (CR-22): Add golden journeys and release matrix gates

**Files:**
- Create: `docs/golden-journeys/auth-application.md`
- Create: `docs/golden-journeys/bilingual-content-website.md`
- Create: `docs/golden-journeys/page-editor.md`
- Create: `docs/golden-journeys/private-document-slots.md`
- Create: `docs/golden-journeys/tracked-mail.md`
- Create: `docs/golden-journeys/settings-activity.md`
- Create: `docs/golden-journeys/version-upgrade.md`
- Modify: `docs/installation-profiles.md`
- Modify: `docs/releasing.md`
- Modify: `.github/workflows/package-quality.yml`
- Modify: `.github/workflows/package-release.yml`
- Modify: `tests/Contract/PackageQualityWorkflowTest.php`
- Modify: `tests/Contract/PackageArchiveToolsTest.php`

**Interfaces:**
- Consumes: CR-19/20 fixtures and CR-21 KPO evidence.
- Produces: reproducible adoption documentation and CI/release gates.

- [ ] **Step 1: Write failing documentation/workflow contract tests**

```php
foreach ($journeys as $journey) {
    expect($root.'/docs/golden-journeys/'.$journey)->toBeFile();
}
```

Assert every journey contains exact module flags, bindings, migration choice,
Actions/DTOs, queue/schedule requirements, focused tests, strict Doctor/audit,
and rollback/upgrade evidence. Assert CI invokes both production runners.

- [ ] **Step 2: Run contract tests and verify missing journeys/gates fail**

Run: `php artisan test --compact tests/Contract/PackageQualityWorkflowTest.php tests/Contract/PackageArchiveToolsTest.php tests/Contract/SuiteAdoptionDocumentationTest.php`

Expected: FAIL because the journey documents and workflow steps are absent.

- [ ] **Step 3: Write the seven executable golden journeys**

Use only commands and APIs exercised by CR-19/20 and KPO. Include expected exit
codes and outputs without credentials. Each journey ends with
`nvl:suite:upgrade:check --strict`, `nvl:suite:doctor --strict`, and
`nvl:suite:consumer-audit --strict`.

- [ ] **Step 4: Expand current/lowest/database CI**

Run both fixture contracts in normal tests. Add production runners to the
current PHP 8.4/Laravel 13 job. Add PHP 8.5/Laravel 13 and PHP 8.4/Laravel 12
fixture matrix entries. Include Media owner-slot and Page/Content tests in
PostgreSQL, MySQL 8.4, and MariaDB jobs.

- [ ] **Step 5: Expand the sealed release rehearsal**

After building the archive, run both production consumers from that archive,
then rehearse the previous 1.x minor to the candidate with published config and
both migration ownership modes. Require non-symlink installation, config/route
cache, types, all Doctors, and strict audit.

- [ ] **Step 6: Run the complete release gate**

Run:

```bash
vendor/bin/pint --dirty --format agent
composer quality
bash tools/run-auth-production-consumer.sh
bash tools/run-content-production-consumer.sh
```

Expected: all PASS.

- [ ] **Step 7: Commit CR-22**

```bash
git add docs/golden-journeys docs/installation-profiles.md docs/releasing.md .github/workflows/package-quality.yml .github/workflows/package-release.yml tests/Contract
git commit -m "test: gate suite golden consumer journeys"
```

### Workstream acceptance gate

- [ ] Both sealed proof consumers pass in both migration modes.
- [ ] KPO's full `composer ci:check` passes from the approved migration worktree.
- [ ] KPO strict Doctor, upgrade check, and consumer audit pass with no new suppression.
- [ ] KPO's `RbacPresentationReadService` and package-lifecycle portion of `HasSingleDocumentMedia` are absent.
- [ ] Release workflow covers PHP 8.4/8.5, Laravel 12/13, and all declared databases.
- [ ] Record suite and KPO commit IDs beside CR-19 through CR-22 in the master tracker.
