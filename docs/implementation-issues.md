# NVL Laravel Suite Implementation Issues

This repository-local tracker records real package defects, unsafe defaults, and documentation gaps discovered while migrating KPO functionality to `nvl/laravel-suite`. It was imported on 2026-08-10 so implementation work, verification, commits, and release history remain focused and auditable.

The issue descriptions below preserve the source report's area, impact, finding, consumer risk, expected package change, and current KPO workaround. Resolved entries remain as migration and release history.

## Tracking and commit protocol

- Status markers are `[ ]` not started, `[~]` in progress, and `[x]` resolved.
- Work one group at a time in the order agreed before implementation.
- Default to one cohesive commit per group. If a group must be split, keep every commit within that group and use the same group identifier in the commit body.
- Do not mix implementation from different groups in one commit.
- A resolution requires production code, focused regression tests, relevant documentation and skill updates, public-contract review, formatting, and the smallest sufficient package/root verification.
- When closing an item, retain its original report, add the resolving commit SHA and release target, and move it to the resolved history only after verification.
- Reassess overlapping findings inside a group before coding so one shared contract fixes the underlying problem instead of adding parallel adapters.

## Commit groups

| Group | Scope | Issues | Commit prefix | Status |
|---|---|---:|---|---|
| G01 | Suite migration ownership and release workflow | 1 | `fix(suite): …` | Not started |
| G02 | Templates and Content adoption and rendering | 4 | `feat(templates): …` | Not started |
| G03 | Settings adoption, keys, audit context, and validation | 4 | `feat(settings): …` | Not started |
| G04 | Data, TypeScript, and CSV consumer contracts | 4 | `fix(data): …` | Not started |
| G05 | Mail Notifications adoption and administrative reads | 2 | `feat(mail-notifications): …` | Not started |
| G06 | Auth schema and principal adoption | 4 | `feat(auth): …` | Not started |
| G07 | Authentication and onboarding security | 10 | `fix(auth): …` | Not started |
| G08 | RBAC and principal lifecycle system transitions | 7 | `feat(auth-rbac): …` | Not started |
| G09 | Media storage, delivery, mutation, and adoption | 7 | `fix(media): …` | Not started |
| G10 | Activity adoption, compatibility, and retention safety | 3 | `fix(activity): …` | Not started |

Total open issues: **46**.

## Consumer adoption evidence

### KPO Auth adoption status

- `App\Models\User` is the canonical application class on the package-owned `nvl_auth_users` table.
- NVL Auth owns principals, password-reset tokens, API tokens, roles, permissions, assignments, invitations, challenges, audits, and their `nvl_auth_*` tables.
- NVL Auth also owns social identity linkage in `nvl_auth_social_identities`; KPO no longer stores provider flags or OAuth access/refresh tokens on principals.
- Legacy `users`, `password_reset_tokens`, `rp_*`, `auth_audits`, `passwordless_challenges`, and `registration_invitations` data is migrated forward and those tables are dropped.
- KPO domain foreign keys now reference `nvl_auth_users`; application-specific principal columns extend that table without creating a parallel Auth table.
- Outstanding legacy bearer credentials are retained as revoked audit records because their one-way SHA-256 hashes cannot be converted into package HMACs; newly issued credentials use package hashing exclusively.
- The Auth module no longer defines package-overlap User, Role, Permission, AuthAudit, Challenge, or Invitation models.
- The unused local identity persistence service and password-writing console command were removed; management principal and access mutations go through package Actions.
- KPO permission names and canonical system-role assignments now enter Auth through `PermissionCatalogProvider`, `RoleTemplateProvider`, and the package `RbacSynchronizer`; seeders only enrich package records with host presentation metadata.
- Dead Auth compatibility DTOs, middleware, and Socialite contracts left behind by the cutover were removed.
- The unused Auth module configuration catalog and scaffold-only Blade index/layout were removed; the host provider now boots only the routes, policies, translations, email views, and KPO adapters still required around package Actions.
- Package principal management and API-token storage are enabled. Remaining local invitation, passwordless, and domain-profile orchestration exists only where the package's current public Actions cannot express the KPO workflow described below.

### KPO Activity, Media, Comments, and Mail Notifications adoption status

- Activity, Media, Comments, Mail Notifications, and their transitive suite dependencies are enabled through `config/nvl-suite.php`.
- Auth, Activity, Media, Comments, and Mail Notifications load their canonical schema directly from enabled NVL Suite migrations; publishing those migrations is optional and KPO carries no fresh-install copy.
- The host Auth provider no longer registers a local migration path at all. A package ownership regression test rejects published/copied NVL migration basenames in every application and module migration directory.
- The former KPO Auth, RBAC, Activity, and Media create migrations have been removed, including rollback paths that could recreate legacy tables or restore the deleted `Modules\Auth\Models\User` morph type.
- Existing `px_media` tables are staged before the vendor migrations, then their rows are copied into the vendor-created schema; ownership moves to `px_media_associations`, localized copy moves to `px_media_i18n`, and all temporary legacy tables are dropped. `px_media*` are NVL Media's own canonical table names.
- Existing Activity rows are staged before the vendor migration, copied into its canonical table, and removed from staging after count reconciliation. KPO no longer carries a copy of the Activity table definition.
- The disabled local Activity module shell and its duplicate translation catalog were removed; KPO's retained integration tests and shared frontend translation projection now consume the package module directly.
- Automatically audited KPO models now use `Nvl\Activity\Traits\HasModelActivity` with registered package mappings; no production model imports Spatie's logging trait directly.
- Comments has no pre-existing generic KPO schema; fresh installations use only the four package-owned Comments tables loaded from NVL Suite.
- NVL Mail Notifications exclusively owns `mail_notifications`, `mail_notification_events`, and `scheduled_mail_messages`. KPO's incompatible tracking and scheduler migrations, models, write Actions, status transitioners, tracking traits, resend implementation, MailerSend verifier/normalizer, and webhook-management commands were removed.
- Every opted-in KPO Mailable now implements the package `TrackableMessage` contract and uses `TracksMailDelivery`; package testing interception replaces KPO's address wrapper. Host models expose registered stable aliases through `MailTrackable`, so new rows never persist PHP class names.
- The public MailerSend route remains host-owned as required by the package, but authentication, normalization, idempotency, lifecycle mutation, and remote webhook management are delegated to the package adapter and services.
- Eight historical KPO tracking rows were staged, imported as package `accepted` attempts with privacy-safe metadata and stable `user` aliases, reconciled, and removed with the legacy tables. A pre-migration SQLite backup is retained at `/tmp/kpo-before-nvl-mail-notifications-adoption.sqlite` for local recovery.
- `reminder_occurrences` remains a KPO business ledger. Its nullable delivery link now references the package table, and a package `MailTrackingStarted` listener records the attempt identity without retaining a second tracking model.
- The duplicate `app/Lib/CSV` framework was removed. KPO member import preview and Auth export now call `Nvl\Csv` directly; only KPO's row normalization and domain import ledger remain application-owned.
- Stale project skills that instructed agents to restore `app/Lib/Filterable`, raw Spatie Activity/Media usage, or `Modules\Auth\Models\User` were corrected to the NVL public APIs.

### KPO Settings adoption status

- NVL Settings exclusively owns the canonical `settings` table, model, casts, repository, cache, validation, synchronization, management Actions, DTOs, events, and optional JSON API.
- KPO's `core_settings` create migration, model, policy, CRUD Actions, DTOs, value-type enum, validation rule, registry, cache façade, service wrapper, seeders, and local JSON API were removed.
- KPO retains source definition files, a typed `ApplicationSetting` key enum, a thin enum-key reader over `SettingRepository`, package authorization, package-event activity integration, and the Inertia presentation adapter the headless package intentionally does not provide.
- Existing `core_settings` rows are copied into source-defined package records by one forward-only adoption migration. It rejects unknown keys and invalid values, verifies every target row, and drops `core_settings`; fresh installations never create that legacy table.
- The initial adoption draft incorrectly assumed the legacy table was named `settings` and introduced an unnecessary staging rename. Schema inspection caught this before migration; the staging file was removed and the adoption bridge now targets the actual `core_settings` schema directly.

### KPO Templates and Content adoption status

- NVL Templates exclusively owns `templates`, `templates_i18n`, `template_versions`, `template_assignments`, and `template_renders`; NVL Content exclusively owns its five `content_*` tables.
- The local Templates module, models, CRUD Actions, controllers, API routes, migrations, factories, seeders, translations, frontend pages, and generated local route bindings were removed.
- Genuine KPO candidacy and REV report definitions/views now live in their respective domain modules and extend `Nvl\Templates\Templates\BasePdfTemplate` directly.
- Existing `templates`, `template_contents`, and `template_assets` tables are staged before the package migrations. Ninety-five content identities and 190 localized values are imported through NVL Content Actions, the two source-defined template records are reconciled through NVL Templates Actions, and all staging tables are removed.
- The package-backed candidacy bootstrap preserves imported/customized values and fills only missing required keys or locales. This added ten missing content identities to the current database; fresh installations receive all 12 required bilingual identities during the adoption migration.
- The staging migration strips legacy named indexes after table rename. This is required on SQLite because renamed indexes retain names such as `templates_key_unique` and otherwise collide with the package migration.
- Fresh-install and existing-data rehearsals both pass strict Content and Templates doctors. The pre-cutover SQLite backup is retained at `/tmp/kpo-before-nvl-templates-adoption.sqlite` for local recovery.

## Open implementation groups

### G01 — Suite migration ownership and release workflow

- Status: not started
- Commit boundary: keep implementation, tests, documentation, and contract updates for this group together; do not mix unrelated groups.
- Commit subject prefix: `fix(suite): …`

#### [ ] G01-01 — Direct-loaded and published migration workflows are not clearly separated

- Area: Suite module service providers and installation/migration documentation
- Impact: high
- Finding: Auth, Activity, Comments, Media, and Mail Notifications can load their migrations directly from the installed package while also exposing the same files for publication. The installation guidance does not state clearly that these are alternative ownership workflows. Publishing the files while automatic loading remains enabled leaves two source paths with identical migration basenames, and a stale published copy can obscure a newer vendor migration. Several providers also register migration directories through generic `publishes()` instead of Laravel's timestamp-aware `publishesMigrations()` API.
- Consumer risk: consumers may believe package tables must also be copied into the host repository, accidentally fork package schema ownership, miss later package migration changes, or run a source-path-dependent migration version.
- Expected package change: use `publishesMigrations()` consistently; document two explicit modes—automatic vendor loading with no published copies, or published host-owned migrations with automatic loading disabled—and add a Doctor warning when enabled vendor migrations have matching published basenames in `database/migrations`.
- Current workaround: KPO enables package migrations and loads them directly from `vendor/nvl/laravel-suite`; it does not publish or retain copies of package table-creation migrations. KPO-local migrations in these areas are forward-only data-adoption bridges or genuine domain extensions, not alternate package schema owners.

### G02 — Templates and Content adoption and rendering

- Status: not started
- Commit boundary: keep implementation, tests, documentation, and contract updates for this group together; do not mix unrelated groups.
- Commit subject prefix: `feat(templates): …`

#### [ ] G02-01 — Templates migration silently accepts incompatible same-name tables and can collide after staging

- Area: Templates migrations and installation preflight
- Impact: high
- Finding: `create_templates_table` returns whenever any `templates` table exists without validating the package columns, indexes, or constraints. Renaming an incompatible SQLite table is also insufficient by itself because its named indexes retain their original names and collide with the package migration when the canonical table is recreated.
- Consumer risk: the package can report a successful migration against an incompatible schema, or an otherwise correct staged adoption can fail partway through with an index-name collision.
- Expected package change: fail closed when a same-name table lacks the canonical schema, add this validation to Doctor/preflight, and document that staged legacy tables must shed schema-wide named indexes before package creation. A first-party staging/adoption command should handle this per supported database driver.
- Current workaround: KPO renames the three legacy tables before the package timestamp, enumerates and drops every non-primary legacy index, lets the unmodified package migrations create the canonical schema, reconciles all data, and removes staging.

#### [ ] G02-02 — Templates and Content lack a first-party adoption workflow

- Area: Templates/Content installation and legacy import guidance
- Impact: high
- Finding: the packages define canonical schemas and runtime contracts but do not provide a dry-run adoption command for existing template metadata, localized content, or template assets. Content correctly fails closed on conflicts, while Templates silently skips a same-name table, so the two modules have inconsistent conflict behavior.
- Consumer risk: consumers retain parallel storage, fork package migrations, lose localized values/assets, or invent timestamp-sensitive destructive bridges without reconciliation guarantees.
- Expected package change: provide one staged adoption surface with schema inventory, explicit key/scope maps, locale validation, asset-to-Media mapping, idempotent Action-backed writes, count reconciliation, and a fail-loud finalization report. Templates should adopt Content's fail-closed conflict posture.
- Current workaround: KPO uses two forward-only host migrations solely for staging and data adoption. Non-empty legacy asset storage fails closed until each alias is imported into NVL Media; no local runtime table or model remains.

#### [ ] G02-03 — Class templates cannot hydrate complete Content scopes through a dedicated application read contract

- Area: class-template compatibility API and Content application surface
- Impact: medium
- Finding: `BasePdfTemplate::withContent()` is documented as transitional and does not resolve NVL Content automatically. The public Content façade exposes only request-style paginated block listing capped at 100 rows, and its `scope` filter supports equality only. A renderer needing ordered scope fallback must issue one page-limited request per scope or query package models directly.
- Consumer risk: each host writes its own precedence, pagination, locale, publication, and definition-resolution adapter; templates can silently omit content beyond the page boundary.
- Expected package change: expose a bounded, non-request-paginated scope-resolution contract for internal rendering, with ordered scope fallback, locale selection, visibility/status enforcement, deterministic limits, and explicit overflow failure. Allow `scope in [...]` where appropriate.
- Current workaround: KPO's narrow `TemplateContentResolver` uses only the public Content façade, queries each governed scope with its supported equality filter, rejects overflow, and supplies the resulting map to the package class template.

#### [ ] G02-04 — Templates has no first-party NVL Media asset resolver

- Area: Templates asset resolution and Media integration
- Impact: medium
- Finding: Templates exposes a replaceable `TemplateAssetResolver`, but the default is null and the suite does not ship an NVL Media-backed implementation even though its architecture assigns asset ownership to Media.
- Consumer risk: every suite consumer must write alias/path resolution glue or fall back to source filesystem assets; legacy template asset adoption has no canonical destination mapping API.
- Expected package change: provide an opt-in Media-backed resolver with registered collections/aliases, authorization-safe URLs or local render paths, revision-aware resolution, and an adoption helper for legacy aliases.
- Current workaround: KPO's two currently used logos are source-controlled and registered through the package asset guard. The legacy asset table was empty; adoption fails closed if that assumption is not true.

### G03 — Settings adoption, keys, audit context, and validation

- Status: not started
- Commit boundary: keep implementation, tests, documentation, and contract updates for this group together; do not mix unrelated groups.
- Commit subject prefix: `feat(settings): …`

#### [ ] G03-01 — Settings has no first-party legacy-schema adoption preflight or command

- Area: Settings migrations, Doctor, and installation guidance
- Impact: high
- Finding: enabling Settings creates the package schema but provides no supported mapping/import workflow for an established typed key-value table. A consumer must independently map key grammar, value codecs, definition hashes, fallback metadata, revisions, and validity windows before removing the legacy table. If an existing table is also named `settings`, the package migration silently returns when the table exists even when its columns are incompatible.
- Consumer risk: consumers can leave two authoritative settings stores, lose overrides, import invalid raw values, or believe a same-name legacy table is package-compatible until runtime queries fail.
- Expected package change: add a Doctor preflight that distinguishes the package schema from a same-name legacy table, plus a first-party adoption API/command with explicit key replacement maps, dry-run validation, count reconciliation, and fail-loud unknown-key handling.
- Current workaround: KPO uses one reviewed forward-only migration from `core_settings` into source-defined NVL records, validates through package definitions/codecs, verifies every inserted identity, and drops the legacy table.

#### [ ] G03-02 — Settings canonical keys cannot preserve arbitrarily nested legacy keys

- Area: source definition grammar and legacy key mapping
- Impact: medium
- Finding: package identities support `namespace`, one optional `scope`, and one `key` segment. Existing keys such as `core.currency.dual_pricing.enabled` cannot be represented without flattening the segments after the scope.
- Consumer risk: adoption changes public key strings and requires every caller, migration, cache key, API client, and audit projection to coordinate a replacement map.
- Expected package change: document the one-scope grammar prominently and support a validated legacy-key replacement map in an adoption command. If nested key paths are intentionally unsupported, provide a canonical flattening convention.
- Current workaround: KPO defines every package key in `ApplicationSetting` and uses an explicit legacy-to-canonical map in the adoption migration.

#### [ ] G03-03 — Settings audit events lack actor and request metadata

- Area: `SettingChanged`
- Impact: medium
- Finding: the event correctly excludes setting values, but it only carries record id, key, revision, and operation. It does not carry the authenticated actor, request correlation metadata, or an extension contract for resolving them after commit.
- Consumer risk: package-owned mutations cannot produce a complete security audit without host listeners inferring mutable request context.
- Expected package change: add a replaceable audit-context provider analogous to Auth login metadata while continuing to prohibit values in events and logs.
- Current workaround: KPO records a value-free package activity from `SettingChanged`; actor/request enrichment is limited to context safely available to the listener.

#### [ ] G03-04 — JSON item validation requires undocumented custom root rules

- Area: Settings source-definition validation rules
- Impact: low
- Finding: definition rules are applied to the root `value`. Conventional nested Laravel rules such as `value.*` cannot be expressed directly in a definition's rule list, so a JSON list of bounded integers requires a custom rule object.
- Consumer risk: consumers may believe `list` validates item types/ranges or attempt rules that never run against nested elements.
- Expected package change: document the root-rule behavior and provide examples or first-party helpers for typed JSON lists/maps.
- Current workaround: KPO supplies one reusable `IntegerListBetween` rule for its source-defined reminder schedules.

### G04 — Data, TypeScript, and CSV consumer contracts

- Status: not started
- Commit boundary: keep implementation, tests, documentation, and contract updates for this group together; do not mix unrelated groups.
- Commit subject prefix: `fix(data): …`

#### [ ] G04-01 — Settings generator strictness documented for a newer release is absent in 1.0.1

- Area: `nvl:data:types:generate` CLI contract and release guidance
- Impact: medium
- Finding: the installed stable suite release is 1.0.1, and its generator rejects the documented `--fail-on-warning` option even though the later improvement summary says generation/check support fail-on-warning behavior.
- Consumer risk: consumers following the newer instructions get an immediate CLI error and may assume type generation itself is broken.
- Expected package change: version the instructions explicitly, expose the option in the next stable release, and have the command help/report identify the minimum suite version supporting strict warning failure.
- Current workaround: KPO runs the 1.0.1 generator without the unavailable flag and uses `nvl:data:types:check`, TypeScript, and lint verification as separate gates.

#### [ ] G04-02 — CSV query export rejects correctly typed concrete Eloquent builders

- Area: `Nvl\Csv\Services\CSVExport::fromQuery()` PHPDoc/static-analysis contract
- Impact: medium
- Finding: the package declares `fromQuery()` against `Builder<Model>`. Eloquent builders are invariant in their model template, so a valid `Builder<App\Models\User>` is rejected by maximum-level PHPStan even though the runtime API accepts it.
- Consumer risk: direct package adoption introduces false-positive static-analysis failures or encourages consumers to erase query model types before export.
- Expected package change: make the method generic, for example `@template TModel of Model` with `@param Builder<TModel> $query`, and carry that template through any stored builder property or downstream query callbacks.
- Current workaround: KPO widens only the variable passed to the package boundary with an explicit `Builder<Model>` PHPDoc. No local CSV implementation remains.

#### [ ] G04-03 — Mail Notifications does not register its public delivery-status enum for TypeScript generation

- Area: Mail Notifications public DTO integration with NVL Data strict TypeScript generation
- Impact: medium
- Finding: a host `#[TypeScript]` DTO that correctly types a property as `Nvl\MailNotifications\Enums\MailDeliveryStatus` causes `nvl:data:types:generate` to fail on an unresolved replacement warning because the package enum is outside the host source roots and Mail Notifications does not register it through the Data source registry.
- Consumer risk: strict generation rejects an otherwise valid package-typed read projection, encouraging consumers to weaken the PHP type or disable fail-on-warning behavior.
- Expected package change: register public Mail Notifications enums as package TypeScript sources, or ship validated package-owned replacements/literal definitions that NVL Data loads automatically when the module is enabled.
- Current workaround: KPO retains the package enum in PHP and applies a property-level `LiteralTypeScriptType` union at the host DTO boundary.

#### [ ] G04-04 — Generated TypeScript artifacts conflict with standard lint/format checks

- Area: NVL Data split writer and generated declaration integration
- Impact: low
- Finding: `nvl:data:types:generate` writes `resources/js/types/generated.d.ts` with triple-slash path references and deterministic scope files that intentionally do not match the host's Prettier output. TypeScript compiles the entrypoint correctly, but `typescript-eslint` rejects each reference under `@typescript-eslint/triple-slash-reference`, while repository-wide Prettier checks report every scope as unformatted.
- Consumer risk: a clean generated contract set fails normal ESLint/Prettier checks, encouraging consumers to edit integrity-protected output, disable rules globally, or format files that `nvl:data:types:check` will immediately report as stale.
- Expected package change: either emit lint/format-compatible declarations when that preserves deterministic global namespace semantics, provide official ESLint flat-config and `.prettierignore` fragments for every generated output path, or document the exact exclusions alongside setup.
- Current workaround: KPO excludes the NVL entrypoint and generated scope directory from ESLint and Prettier while continuing to verify their exact generator-owned content with `nvl:data:types:check` and `tsc --noEmit`.

### G05 — Mail Notifications adoption and administrative reads

- Status: not started
- Commit boundary: keep implementation, tests, documentation, and contract updates for this group together; do not mix unrelated groups.
- Commit subject prefix: `feat(mail-notifications): …`

#### [ ] G05-01 — Mail Notifications has no first-party legacy-schema adoption command

- Area: Mail Notifications installation, compatibility preflight, and scheduled-message upgrade path
- Impact: high
- Finding: the package correctly rejects a pre-existing incompatible `mail_notifications` table and documents field-level mapping, but it provides no command or migration API that stages an established table before the timestamped preflight, imports rows, reconciles identities/counts, rewires host foreign keys, and removes the legacy schema. Legacy scheduled rows are even harder to adopt because class-name/template persistence must be converted to registered factory aliases and payload versions.
- Consumer risk: enabling automatic package migrations fails before a later host migration can inspect the canonical table name. Consumers must invent a timestamp-sensitive bridge and may silently lose provider events, stable notifiable aliases, privacy boundaries, or scheduled work.
- Expected package change: provide a dry-run adoption command or documented bridge API that inventories legacy columns and foreign keys, validates a mapping, stages canonical-name collisions, maps statuses/events/notifiable aliases, requires explicit scheduled factory mappings, reconciles counts, and emits a forward-only cutover report.
- Current workaround: KPO stages the two incompatible tables immediately before the package preflight, lets the unmodified package migrations establish canonical ownership, imports only privacy-safe delivery state, refuses to discard non-empty legacy scheduled rows, reconciles all attempt IDs, retargets the reminder ledger, and drops staging tables.

#### [ ] G05-02 — Mail Notifications has no package read/query Actions

- Area: Mail Notifications administrative read API
- Impact: medium
- Finding: the package owns tracking models, delivery lifecycle, scheduling, webhooks, and mutations but exposes no bounded list/show/statistics/suggestion Actions or stable read DTOs for an administrative delivery log.
- Consumer risk: every host must query the package model directly and independently define filters, pagination, statistics, suggestions, and public-safe projections, which makes downstream UI contracts inconsistent.
- Expected package change: add read-only list/show/statistics/suggestion Actions with bounded filters, stable pagination, privacy-safe DTOs, and explicit authorization hooks.
- Current workaround: KPO's remaining Mail Notifications Actions are read-only host projections over `Nvl\MailNotifications\Models\MailNotification`; all tracking and delivery mutations remain package-owned. `reminder_occurrences` is a separate KPO workflow ledger.

### G06 — Auth schema and principal adoption

- Status: not started
- Commit boundary: keep implementation, tests, documentation, and contract updates for this group together; do not mix unrelated groups.
- Commit subject prefix: `feat(auth): …`

#### [ ] G06-01 — Auth has no first-party legacy-principal adoption bridge

- Area: Auth installation migrations and upgrade guidance
- Impact: high
- Finding: the package can create `nvl_auth_users`, but it has no supported path for copying an established principal table, preserving host extension columns, moving password-reset tokens, and retargeting domain foreign keys before the legacy principal table is dropped. Historical host migrations can also reference the principal before the timestamped package migration creates it on a fresh install.
- Consumer risk: consumers either retain two active user tables, publish and fork the package migration, or write a high-risk one-off migration without a documented reconciliation contract.
- Expected package change: provide a dry-run/adoption command or migration API with principal field mapping, conflict detection, row reconciliation, password-token adoption, foreign-key inventory/retarget guidance, and explicit rollback boundaries. Document migration ordering for hosts with historical domain migrations.
- Current workaround: KPO loads the unmodified package migration, uses one irreversible application bridge to copy 67 legacy principals and password-reset tokens, retarget every discovered domain foreign key, reconcile counts, and drop the legacy tables. Fresh historical migrations defer principal foreign keys until that bridge runs.

#### [ ] G06-02 — Reserved principal profile storage can collide with a host domain relationship

- Area: `Nvl\Auth\Models\User`, principal Actions, and configurable principal documentation
- Impact: high
- Finding: the package reserves the physical `profile` JSON attribute, while established applications commonly expose a `profile()` Eloquent relationship. Eloquent resolves the selected `profile` column before the relationship, so simply extending the package model silently changes `$user->profile` from a related model to array/null data.
- Consumer risk: enabling package principal storage can break domain profile reads without a schema error, including eager loads, relation filters, and mutations.
- Expected package change: make principal metadata attributes configurable through a mapper/repository, or reserve a clearly namespaced attribute such as `auth_profile` and add Doctor checks for attribute/relation collisions on configured principal models.
- Current workaround: KPO renamed its domain relationship to `kpoProfile`, reserves `profile`/`preferences` for package Actions, and added focused integration coverage for both contracts.

#### [ ] G06-03 — Configurable principal models are not mutation-schema adaptable

- Area: principal-management Actions
- Impact: high
- Finding: the configured user model is replaceable, but principal mutation Actions still hardcode package-owned physical attributes and relations such as `is_active`, `profile`, and `preferences`. KPO uses `active` and a related profile model.
- Consumer risk: enabling package principal-management or RBAC Actions against an established host model can silently discard domain fields or fail with missing-column errors even though the configured models correctly extend the package base models.
- Expected package change: introduce a replaceable principal mutation mapper/repository, or validated field maps, so package orchestration can target an existing schema without requiring package-shaped columns.
- Current workaround: KPO adopted the package-owned principal table and fields, enabled package principal management, mapped the public `active`/`settings` host surface to `is_active`/`preferences`, and renamed the colliding domain profile relationship. Host-specific fields extend `nvl_auth_users`; remaining domain-specific mutations are being removed or isolated as adapters.

#### [ ] G06-04 — Auth migrations are monolithic during staged feature adoption

- Area: `2026_08_02_000000_create_nvl_auth_tables.php`
- Impact: medium
- Finding: enabling package migrations creates every Auth table, including feature tables that the consumer has not adopted.
- Consumer risk: staged adoption produces unused package tables and makes schema ownership less obvious.
- Expected package change: split migrations by capability or make table creation feature-aware without making later feature enablement unsafe.
- Current workaround: KPO now uses the package principal, password-reset, API-token, RBAC, invitation, challenge, audit, and social-identity tables directly. Tables for disabled TOTP, passkey, recovery-code, and client capabilities remain empty until those features are adopted.

### G07 — Authentication and onboarding security

- Status: not started
- Commit boundary: keep implementation, tests, documentation, and contract updates for this group together; do not mix unrelated groups.
- Commit subject prefix: `fix(auth): …`

#### [ ] G07-01 — Auth delivery requests permit incoherent feature and message-type pairs

- Area: `Nvl\Auth\ValueObjects\AuthDeliveryRequest`
- Impact: medium
- Finding: the request validates identifier, recipient, expiry, locale, and payload bounds, but it accepts combinations such as `AuthFeature::Authentication` with `AuthMessageType::EmailVerification`.
- Consumer risk: delivery listeners that route only on message type can cross an intended feature boundary and process a request emitted under the wrong capability.
- Expected package change: define and validate the supported feature-to-message-type mapping when constructing a delivery request, or expose a package-owned invariant that consumers can call before delivery.
- Current workaround: KPO's email-verification listener requires both `AuthFeature::EmailVerification` and `AuthMessageType::EmailVerification` before resolving a recipient or claiming an idempotency key.

#### [ ] G07-02 — Authentication policy is not consistently extensible across login flows

- Area: `LoginAction`, `EstablishAuthenticatedSessionAction`, and `PrincipalEligibility`
- Impact: high
- Finding: credential login uses the concrete, non-replaceable `PrincipalEligibility`, records successful-login metadata before the configurable login pipeline completes, and exposes no pre-authentication subject-policy contract. `EstablishAuthenticatedSessionAction` bypasses `PrincipalEligibility`, the login pipeline, and `SuccessfulLoginMetadataRecorder` entirely.
- Consumer risk: a host cannot safely enforce policies such as KPO's maximum active-session ceiling inside the package action. Enforcing it in the current login pipeline can write false successful-login metadata before the policy rejects the login. Passwordless authentication can admit inactive, locked, or otherwise host-ineligible subjects and omit required login metadata.
- Expected package change: add a replaceable authentication-eligibility contract used by every session-establishment flow; run it before successful-login metadata is recorded; apply the same login pipeline, request context, metadata recorder, rejection events, and audit semantics to passwordless/social session establishment.
- Current workaround: KPO retains a narrow credential-login policy gateway and its existing passwordless login gateway. Package login/logout, auditing, events, session rotation, and successful credential-login metadata are used wherever their ordering is safe.

#### [ ] G07-03 — Socialite identity contract discards provider email-verification evidence

- Area: `SocialiteIdentityProvider`, `ExternalIdentity`, and `SocialSubjectResolver`
- Impact: high
- Finding: the Socialite adapter reads the provider identifier, email, name, avatar, and nickname but discards raw `email_verified` / `verified_email` claims. `ExternalIdentity` has no verification field even though `SocialSubjectResolver` describes the identity as verified. An email-based resolver therefore cannot distinguish a provider-verified address from an unverified claim.
- Consumer risk: a host that resolves existing principals by email can link or authenticate the wrong external account when a provider permits unverified email claims. The package contract makes the unsafe implementation appear compliant.
- Expected package change: carry a normalized email-verification claim with explicit provenance through `ExternalIdentity`, require resolvers to enforce it when matching by email, and make provider adapters fail closed when a configured provider cannot prove the address.
- Current workaround: KPO uses the package `StartSocialAuthorizationAction` and `LinkSocialIdentityAction`, but retains a narrow Google claim-acquisition check that requires the raw verified-email claim before linking. Canonical identity data lives only in `nvl_auth_social_identities`; KPO no longer stores OAuth access or refresh tokens on the principal row.

#### [ ] G07-04 — Self-service profile mutation cannot change or partially update an email address

- Area: `UpdateProfileAction` and principal email-verification lifecycle
- Impact: high
- Finding: the package self-service Action requires a complete name/locale/timezone/profile/preferences replacement and does not accept email. The management `UpdateUserAction` can change email, but authorizes only the actor-level management ability and cannot safely authorize a self-only target because the target is not passed to `ManagementAuthorizer`.
- Consumer risk: a host must either grant an over-broad management gate, remove self-service email changes, or write the email and verification state outside the package Action. Supplying current values to the complete profile replacement also creates avoidable stale-write risk for fields the request did not change.
- Expected package change: provide a partial self-service principal mutation with explicit field policy, including an atomic email-change flow that verifies the current credential, normalizes and writes the new email, clears verification, emits delivery/audit/event contracts, and contains any credentials required by policy. Authorization must receive both actor and target.
- Current workaround: KPO delegates name, locale, timezone, package profile, and preferences to `UpdateProfileAction`; only the unsupported self-service email transition and KPO extension columns are written by one application composition Action, which records package audit/event contracts. Administrative email changes use `UpdateUserAction` directly.

#### [ ] G07-05 — Invitation issuance cannot support actorless or host-expiry workflows

- Area: `CreateInvitationAction`, `ResendInvitationAction`, and invitation delivery
- Impact: high
- Finding: `CreateInvitationAction::execute()` requires an authenticated actor and always applies the package-wide TTL. KPO has a public, enumeration-safe candidate self-invitation flow with no authenticated actor, type-specific expiry rules, contextual metadata, and host-specific post-accept routing.
- Consumer risk: a consumer must either invent a fake actor, lose its public onboarding flow, or duplicate issuance orchestration despite adopting the package model and table.
- Expected package change: support an explicitly authorized actorless issuance context, allow a bounded per-invitation expiry override, and expose host hooks for contextual acceptance and post-accept routing.
- Current workaround: KPO stores invitations exclusively in `Nvl\Auth\Models\Invitation`, uses package-compatible HMAC blind indexes, and keeps a thin host issuance/acceptance adapter for the unsupported public workflow.

#### [ ] G07-06 — Invitation acceptance cannot atomically create a package principal

- Area: `AcceptInvitationAction`, `CreateUserAction`, and public registration
- Impact: high
- Finding: `AcceptInvitationAction` requires a principal that already exists, while `CreateUserAction` requires an authenticated management actor. There is no package Action that validates a usable invitation, creates the configured principal from bounded registration data, applies the invitation RBAC payload, consumes the invitation, and emits the package audit/events in one transaction.
- Consumer risk: an invitation-only application must create the principal outside package orchestration before it can call `AcceptInvitationAction`. Failure between those operations can leave an orphan principal or partially completed onboarding, and the host must independently reproduce principal creation normalization and event semantics.
- Expected package change: add an invitation-registration Action with a replaceable registration-data mapper, configured principal model, one transaction, unique-email conflict handling, password/social identity variants, invitation RBAC application, audit/events, and an extension hook for host fields and post-accept work.
- Current workaround: KPO creates `App\Models\User` on the package-owned `nvl_auth_users` table inside its invitation transaction, then delegates token verification, RBAC assignment, invitation consumption, audit recording, verification delivery, and social identity persistence to package Actions. No local principal model or table exists.

#### [ ] G07-07 — One passwordless login cannot carry both a link token and numeric code

- Area: `IssueChallengeAction` and challenge verification
- Impact: high
- Finding: the package issues either one opaque secret or one numeric secret. KPO delivers a magic-link token and a six-digit fallback code for the same single-use challenge. Package token hashing also depends on the recipient blind index, while a token-only callback does not carry the challenge identifier needed for a direct lookup.
- Consumer risk: consumers needing both credentials must create two independently consumable challenges or retain custom orchestration around the package Challenge model.
- Expected package change: support a compound single-use challenge with multiple verification methods, or expose package-owned secondary-secret storage and verification. Token callbacks should have a documented challenge-id contract that avoids scanning active challenge rows.
- Current workaround: KPO uses `Nvl\Auth\Models\Challenge` and package-compatible purpose-separated HMACs, retaining only the combined link/code issuance and atomic-consumption adapter.

#### [ ] G07-08 — Invitation listing cannot search encrypted recipients by partial email

- Area: `ListInvitationsAction` and `Invitation::recipient`
- Impact: medium
- Finding: recipients are encrypted and only an exact blind index is queryable. The package list Action exposes pagination only, with no exact-recipient, type, lifecycle, expiry, or host-context filters.
- Consumer risk: established management screens lose useful filters or must decrypt and scan records in application memory.
- Expected package change: add a package-owned filter DTO and exact recipient blind-index filtering; document that substring recipient search is intentionally unavailable when encrypted storage is enabled.
- Current workaround: KPO uses exact normalized-email search plus package fields for type, lifecycle, expiry, and contextual metadata filters.

#### [ ] G07-09 — Auth has no explicit self-service account-deletion Action

- Area: principal lifecycle Actions
- Impact: medium
- Finding: `DeleteUserAction` correctly rejects an administrator deleting their own account, but the package exposes no separate self-service deletion use case with explicit subject confirmation and credential containment.
- Consumer risk: consumers either misuse the management Action, omit account deletion, or duplicate deletion, audit, event, token, and session semantics locally.
- Expected package change: add a dedicated `DeleteOwnAccountAction` with a replaceable confirmation policy and complete session/token containment while retaining the management Action's self-deletion guard.
- Current workaround: KPO keeps one narrowly scoped application Action that requires the authenticated actor to be the target, revokes browser/API credentials, soft deletes the package principal, and records package audit/event contracts.

#### [ ] G07-10 — Password reset Actions cannot enforce host subject eligibility

- Area: `RequestPasswordResetAction` and `ResetPasswordAction`
- Impact: high
- Finding: password-reset request and consumption resolve the broker subject but do not call `PrincipalEligibility` or a replaceable host policy. The pipeline context is created before subject resolution and does not expose the resolved subject to a consumer policy.
- Consumer risk: inactive, locked, soft-deleted, or otherwise ineligible host accounts can receive or consume reset tokens unless the host retains duplicate broker orchestration.
- Expected package change: apply the shared replaceable subject-eligibility contract after broker resolution and before token issuance or credential mutation, with enumeration-safe public responses and explicit rejection audits.
- Current workaround: KPO calls both package password-reset Actions directly. Its package delivery listener suppresses mail for ineligible principals, and its configured `PasswordUpdater` extension checks the canonical `App\Models\User` policy before delegating credential persistence to the package's `EloquentPasswordUpdater`. This closes delivery and consumption without retaining duplicate broker orchestration, but the package still creates an unused token and records a matched request before the listener can reject an ineligible subject.

### G08 — RBAC and principal lifecycle system transitions

- Status: not started
- Commit boundary: keep implementation, tests, documentation, and contract updates for this group together; do not mix unrelated groups.
- Commit subject prefix: `feat(auth-rbac): …`

#### [ ] G08-01 — Host-principal RBAC assignment is coupled to package principal management

- Area: `SyncUserRolesAction`, `SyncUserPermissionsAction`, and `BulkUpdateUsersAction`
- Impact: high
- Finding: user-role and user-permission synchronization requires `principal_management` even when RBAC is enabled and the configured host principal fully supports Spatie roles. KPO cannot enable principal management because its established user schema intentionally differs from package mutation columns.
- Consumer risk: a host can use package-owned roles, permissions, tables, and role CRUD, but must retain local user-assignment orchestration solely because an unrelated package feature gate is disabled.
- Expected package change: gate access synchronization on RBAC plus a configurable principal locator/assignment contract; require principal management only for package-shaped principal CRUD and lifecycle mutations.
- Current workaround: KPO now uses package-shaped principal storage and enables principal management, so package user-role and user-permission synchronization can be adopted directly. The coupling remains a package limitation for consumers that intentionally retain a different principal schema.

#### [ ] G08-02 — Role templates cannot describe or instantiate host presentation metadata

- Area: `RoleTemplateProvider`, `ListRoleTemplatesAction`, and `ApplyRoleTemplateAction`
- Impact: medium
- Finding: providers can contribute only `role name => permission names`. The package cannot carry a display name, description, system flag, hierarchy parent/priority, or apply a template's permissions to a caller-selected role name.
- Consumer risk: an established role-template screen must either lose its descriptive UI and custom naming workflow or retain a host adapter around package Role and Permission models.
- Expected package change: introduce a validated role-template value object with optional presentation/hierarchy metadata and allow `ApplyRoleTemplateAction` to accept a bounded target-role mutation.
- Current workaround: canonical system roles are contributed through the package provider contract. `App\Support\Auth\KpoRoleTemplates` is intentionally only a host presentation-to-`StoreRoleData` mapper because registering those non-system, caller-named templates would make the package synchronizer persist them as fixed system roles. All persisted models, mutations, and tables remain package-owned.

#### [ ] G08-03 — RBAC synchronization has no bootstrap-safe public Action

- Area: `SynchronizePermissionCatalogAction`, `SynchronizeRoleTemplatesAction`, `SynchronizeRbacAction`, and seeding
- Impact: medium
- Finding: every public synchronization Action requires an already-authenticated, authorized management actor. A fresh installation must create permissions and its first system roles before such an actor can exist. The lower-level `RbacSynchronizer` performs the correct package persistence but does not invalidate Spatie's permission cache itself or emit an explicit bootstrap result.
- Consumer risk: consumers must either manufacture a privileged principal during installation, bypass the public Actions, or recreate synchronization and cache invalidation in seeders.
- Expected package change: expose a console/bootstrap synchronization Action with an explicit trusted-installation context, feature checks, transaction handling, cache invalidation, deterministic reporting, and no fabricated actor. Keep the existing actor-authorized Actions for runtime management.
- Current workaround: KPO contributes catalogs and system roles through package contracts, invokes the package `RbacSynchronizer` from its seeders, and touches `PermissionRegistrar` only to invalidate the transitive cache before and after bootstrap synchronization. Package models remain the sole RBAC persistence layer.

#### [ ] G08-04 — RBAC assignment events and Actions do not cover domain-owned system transitions

- Area: `RbacManager`, `CreateUserAction`, `AcceptInvitationAction`, `SyncUserRolesAction`, and `RbacChanged`
- Impact: high
- Finding: `RbacChanged` is emitted by explicit user synchronization Actions, but initial role assignments performed through `RbacManager` during principal creation or invitation acceptance emit only Spatie's transitive attach events. Conversely, `SyncUserRolesAction` always requires an authenticated management actor, so a committed domain workflow or background process cannot express a trusted system role transition through the public package Action.
- Consumer risk: domain listeners must remain coupled to Spatie events to observe all package-owned assignments, while system workflows must either invent an actor or call `syncRoles()` outside the package audit/event contract.
- Expected package change: emit one package-owned assignment event from the common RBAC manager for every add/remove/sync path, including initial creation and invitation acceptance. Add an explicitly trusted system-transition context or replaceable authorization contract that preserves feature checks, audits, cache invalidation, and package events without fabricating a human actor.
- Current workaround: KPO listens to Spatie's attachment event only for the missing initial-assignment signal and keeps one isolated domain transition service for candidate/member lifecycle changes. It uses package Role models and tables; management UI mutations continue using package Actions directly.

#### [ ] G08-05 — Domain-driven principal lifecycle changes require a fabricated management actor

- Area: `SetUserActiveAction`, `DeleteUserAction`, browser-session containment, and system workflows
- Impact: high
- Finding: principal lifecycle Actions require an authenticated management actor. Scheduled expiry, candidacy advancement, compliance failure, and other committed domain workflows therefore cannot disable or re-enable a principal through the package without inventing a human actor. `SetUserActiveAction` revokes package API tokens but does not rotate the remember token or remove the principal's Laravel database sessions.
- Consumer risk: domain workflows either bypass package audit/events or leave browser sessions valid after a security-sensitive deactivation. Fabricating an actor produces misleading audit records and can violate self-mutation guards.
- Expected package change: provide an explicitly trusted system-transition context with a required reason/correlation identifier, the same feature/audit/event guarantees, and a replaceable principal-session containment contract covering API tokens, remember credentials, and host browser sessions.
- Current workaround: management UI changes use package Actions. KPO keeps one narrow account-status writer for actorless domain transitions and one session-containment service; both mutate the canonical package principal, and the remaining gap is recorded here rather than hidden behind a second Auth implementation.

#### [ ] G08-06 — Principal lifecycle Actions do not contain Laravel browser sessions

- Area: `SetUserActiveAction`, `DeleteUserAction`, `BulkUpdateUsersAction`, and `RestoreUserAction`
- Impact: high
- Finding: disabling or deleting a principal revokes package API tokens, but it does not remove Laravel database sessions or rotate the principal's remember token. A browser session established before an administrator disables the account can therefore remain usable unless every protected request independently rechecks eligibility.
- Consumer risk: a management UI can report a successful account containment operation while existing browser credentials remain valid.
- Expected package change: expose a replaceable principal-session containment contract and invoke it inside package lifecycle transactions, covering Laravel sessions, remember credentials, API tokens, and host-defined client sessions with consistent audit metadata.
- Current workaround: KPO listens to package `PrincipalChanged` events and revokes Laravel database sessions and remember credentials through one host containment listener. Package Actions remain the only management lifecycle writers.

#### [ ] G08-07 — Principal status mutation has no system/domain workflow context

- Area: `SetUserActiveAction` and lifecycle authorization
- Impact: medium
- Finding: package status mutation always requires an authenticated management actor and a management gate. KPO domain workflows must activate or deactivate the same canonical principal as part of candidacy advancement, profile validity, and deactivation processing, where there is no administrator HTTP actor.
- Consumer risk: domain workflows must forge an actor, bypass their transaction boundary, or write `is_active` directly without a package audit/event context.
- Expected package change: add an explicitly authorized system mutation context or replaceable lifecycle writer that accepts a domain reason, correlation metadata, and optional actor while preserving audit and event semantics.
- Current workaround: KPO retains one actorless domain status writer against the package-owned principal table and revokes browser credentials on deactivation. It is not used by Auth management controllers.

### G09 — Media storage, delivery, mutation, and adoption

- Status: not started
- Commit boundary: keep implementation, tests, documentation, and contract updates for this group together; do not mix unrelated groups.
- Commit subject prefix: `fix(media): …`

#### [ ] G09-01 — Media storage hash is incorrectly globally unique

- Area: `2024_02_01_000001_create_media_table.php`
- Impact: high
- Finding: the package builds the physical object identity from `root_folder/folder/hash`, but its migration declares `hash` globally unique and then also adds a normal hash index. KPO has 2,363 valid assets and 25 hash values repeated across valid legacy records, including records in different folders.
- Consumer risk: an in-place adoption must either discard valid rows, rename hashes and break existing object paths, copy large binaries solely to satisfy a database constraint, or diverge from the published migration.
- Expected package change: make storage identity unique on the actual path boundary, such as `(disk, folder, hash)`, or leave `hash` normally indexed and rely on the existing digest/disk/visibility deduplication constraints.
- Current workaround: fresh installations use the unmodified vendor migration. KPO's already-adopted database preserves its legacy duplicate paths; the staged bridge now fails explicitly if a not-yet-adopted database contains duplicate hashes instead of silently discarding rows or rewriting object paths. This requires an upstream constraint fix before another duplicate-bearing installation can cut over safely.

#### [ ] G09-02 — Generated binary uploads have no binary-safe package entry point

- Area: Media upload actions and `addMediaFromString`
- Impact: medium
- Finding: generated PDFs and spreadsheets cannot use `addMediaFromString` because it materializes content as `text/plain`, while the package correctly rejects a detected MIME that conflicts with the requested extension.
- Consumer risk: report/template consumers must either bypass package validation or independently reproduce temporary-file adaptation just to enter the canonical upload pipeline.
- Expected package change: expose a binary-content upload method that accepts a filename and safely detects/validates MIME from the bytes, or a package-owned temporary-upload adapter.
- Current workaround: KPO's `GeneratedMediaUploader` materializes bytes into a test `UploadedFile` and delegates all validation, storage, associations, and metadata to the package upload pipeline.

#### [ ] G09-03 — Media lacks a reusable single-record cross-disk relocation action

- Area: Media mutation actions
- Impact: medium
- Finding: the package exposes bulk move behavior and useful storage primitives, but no public single-record action that atomically copies one media record and its variations, verifies integrity, updates disk/visibility, and schedules rollback/commit cleanup.
- Consumer risk: workflows that promote one generated private artifact to a public or durable disk must reconstruct package transaction and file-effect semantics.
- Expected package change: expose a public single-media relocation action with optimistic locking and the same integrity/cleanup guarantees as package bulk operations.
- Current workaround: KPO's `MediaDiskRelocator` composes package locks, gateways, existence checks, file operators, and file-effect scheduling without owning media persistence.

#### [ ] G09-04 — Media has no nullable existence-safe URL projection

- Area: `MediaUrlResolver` and display/presenter API
- Impact: medium
- Finding: package URL generation assumes the media object should be delivered, but migrated host read models often need the old nullable semantic: return no URL when the record is null or its canonical binary is missing.
- Consumer risk: each host repeats existence checks and can accidentally expose a URL for a missing object.
- Expected package change: expose an existence-aware nullable URL helper or presenter that composes `MediaFileExistence` and `MediaUrlResolver`.
- Current workaround: KPO's `ExistingMediaUrlResolver` is a thin package-service composition and contains no local storage logic.

#### [ ] G09-05 — Separate PUT and PATCH Media routes collide in Wayfinder generation

- Area: Media API route registration
- Impact: medium
- Finding: registering separate PUT and PATCH routes with the same controller action/name causes Wayfinder to emit duplicate TypeScript declarations for the generated action.
- Consumer risk: enabling the package API can break frontend route generation even though Laravel accepts the routes.
- Expected package change: register the verbs through one `Route::match(['put', 'patch'], ...)` definition or give each generated route a distinct export contract.
- Current workaround: KPO keeps package API registration disabled and mounts the package controllers through host routes, using one consolidated update route.

#### [ ] G09-06 — Media adoption has no first-party Spatie-to-association bridge

- Area: Media installation, migration, and command surface
- Impact: high
- Finding: the package replaces Spatie's inline `model_type`, `model_id`, and `collection_name` ownership with package associations and also changes translation, variation, lifecycle, uploader, and storage metadata. The package doctor diagnoses the result but no package command performs or validates this common data conversion before destructive cutover.
- Consumer risk: consumers can enable the package models against an apparently familiar `px_media` table while its physical schema and ownership semantics are incompatible.
- Expected package change: ship a dry-run import/adoption command or documented bridge API that maps legacy media, associations, translations, variations, uploader morphs, lifecycle state, counts, and backing-file paths with pre/post row reconciliation.
- Current workaround: KPO uses a one-time application bridge, count assertions, canonical package tables, and the package doctors; the bridge is removed from runtime ownership after migration.

#### [ ] G09-07 — Media root-folder default silently changes adopted object paths

- Area: `media.root_folder` adoption guidance
- Impact: high
- Finding: package paths prepend the default `media` root, while KPO's existing rows already store complete protected/public folders. Importing those rows without changing the root makes every package URL and existence check point at a different object path.
- Consumer risk: database adoption can succeed while all existing media appears missing.
- Expected package change: make Doctor compare representative persisted paths to storage, and document that existing complete folders require an empty root or an explicit physical move through the disk migration command.
- Current workaround: KPO uses an empty package root during in-place adoption so existing `folder/hash` paths remain unchanged.

### G10 — Activity adoption, compatibility, and retention safety

- Status: not started
- Commit boundary: keep implementation, tests, documentation, and contract updates for this group together; do not mix unrelated groups.
- Commit subject prefix: `fix(activity): …`

#### [ ] G10-01 — Activity package migration cannot adopt its canonical existing table

- Area: `2026_07_25_090858_create_activity_log_table.php` and migration publishing guidance
- Impact: high
- Finding: the package migration unconditionally creates `activity_log`. A consumer already using the exact canonical table cannot enable bundled migrations because the new vendor migration basename is not in its migration repository, and publishing the migration under its original timestamp has the same collision.
- Consumer risk: following the normal enable-and-migrate path fails before a later bridge migration can inspect or align existing data.
- Expected package change: provide a documented migration-baseline/adoption command or a package-owned adoption migration path that can certify and register an existing canonical table without making rollback destructive.
- Current workaround: KPO stages a non-canonical legacy table immediately before the vendor timestamp, lets the unmodified vendor migration create `activity_log`, then copies and reconciles the staged rows immediately afterward. Current canonical installations no-op through the bridge. No KPO Activity table definition or parallel runtime remains.

#### [ ] G10-02 — Activitylog v5 adoption can be an implicit breaking consumer upgrade

- Area: root Composer constraints and installation/upgrade guidance
- Impact: high
- Finding: the root package allows `spatie/laravel-activitylog` `^4.0 || ^5.0`. Updating NVL Suite with dependencies upgraded KPO from Activitylog v4 to v5. v5 requires the new `activity_log.attribute_changes` JSON column and moves `LogsActivity` and `LogOptions` namespaces; the package compatibility bootstrap does not migrate consumer schemas or consumer source imports.
- Consumer risk: an otherwise routine suite update can break all model activity writes at runtime with a missing-column error and can break host source loading through removed v4 namespaces.
- Expected package change: document the full v4-to-v5 consumer migration in the release/installation guidance and add a preflight diagnostic for the `attribute_changes` column. Consider preventing an implicit major upgrade unless the consumer explicitly requires Activitylog v5.
- Current workaround: KPO explicitly adopted Activitylog v5, stages the legacy table so the package migration creates the canonical v5 schema, copies the historical rows back with reconciliation, updates host imports, and keeps a read fallback for historical v4 change data stored in `properties`.

#### [ ] G10-03 — Activity purge ignores importance and can delete protected business evidence

- Area: Activity purge criteria and retention semantics
- Impact: high
- Finding: Activity records carry a validated `importance` classification, but `ActivityPurgeCriteria` and `ActivityLogBuilder::applyPurgeCriteria()` never use it. A general purge deletes `important` rows exactly like `low` rows, and a system-only purge also deletes important system evidence.
- Consumer risk: a consumer can correctly mark security, identity, payment, or compliance history as important and still irreversibly delete it through the package's documented purge UI/API.
- Expected package change: exclude `important` records by default and require an explicit, auditable opt-in to purge them, or add a validated importance criterion with a safe default. Doctor and documentation should state the effective retention policy.
- Current workaround: KPO records the package semantics in tests and limits purge access to its verified admin scope. The package should own the retention fix rather than KPO reintroducing a parallel purge implementation.

## Resolved

### v1.0.1 — staged adoption, Auth extensibility, and Data diagnostics

NVL Suite v1.0.1 implements the improvements reported during the initial KPO Auth and Data adoption. KPO resolves the stable Packagist release through its existing `^1.0` constraint.

#### Auth provider is not passive when global Auth is disabled

- Area: `Nvl\Auth\Providers\AuthServiceProvider`
- Original impact: high
- Resolution: disabled Auth registration is now passive for host authentication providers, password brokers, RBAC models and tables, Sanctum token models, and package migrations.

#### Root suite provider cannot support staged module adoption safely

- Area: `Nvl\Suite\SuiteServiceProvider`
- Original impact: high
- Resolution: dependency-safe staged module adoption is now configured through `config/nvl-suite.php`, allowing consumers to enable only the modules they are ready to integrate.

#### Auth migration loading is independent of the global Auth flag

- Area: `Nvl\Auth\Providers\AuthServiceProvider::boot()`
- Original impact: medium
- Resolution: disabled Auth registration no longer contributes package migrations or changes the consumer's migration inventory.

#### Selective Auth provider dependency ordering is undocumented

- Area: Auth installation and provider documentation
- Original impact: medium
- Resolution: staged module selection now owns dependency ordering, and the selective-adoption workflow is documented in the release, README, and public-contract guidance.

#### Configurable Auth models still require undocumented physical columns

- Area: Auth model extension and Action contracts
- Original impact: high
- Resolution: configurable Auth model schema requirements, reserved attributes, and consumer responsibilities are now explicitly documented. Consumers can determine schema compatibility before enabling principal-management or RBAC actions.

#### Auth audit persistence cannot be adapted to an existing host audit schema

- Area: Auth audit recording contracts
- Original impact: high
- Resolution: Auth audit persistence is configurable through a replaceable contract, allowing an established host audit implementation to remain authoritative.

#### Authentication actions lack hooks for host audit parity and login metadata

- Area: authentication actions, events, and successful-login metadata
- Original impact: high
- Resolution: the package now emits authentication attempt and rejection events, accepts successful-login request metadata, and exposes configurable audit and login-metadata contracts. Consumers no longer need to wrap package login solely to preserve audit and client metadata.

#### Suite installation lacks a consumer guide for forced dependency major upgrades

- Area: installation, upgrade, and release documentation
- Original impact: medium
- Resolution: transformer-v3 migration requirements and public API changes are documented across the README, release guidance, changelog, skills, and public-contract documentation.

#### Spatie Data agent instructions require a removed transformer attribute

- Area: consumer migration skills and instructions
- Original impact: medium
- Resolution: package skills and examples now use transformer-v3-compatible APIs and no longer direct consumers to the removed `RecordTypeScriptType` attribute.

#### Generated-type commands succeed despite unresolved reference replacements

- Area: `Nvl\Data` TypeScript generation diagnostics
- Original impact: medium
- Resolution: Data now supports validated TypeScript replacement maps. Generation and freshness checks can fail on unresolved replacement warnings instead of reporting a false success.

### v1.0.1 package verification

- Auth: 77 tests, 1,386 assertions
- Data: 50 tests, 313 assertions
- Archive and public contracts: 14 tests, 449 assertions
- Root integration: 19 tests, 36 assertions
- Pint formatting passed
- Composer strict validation passed
- Public contract baseline check passed
- PHPStan passed for all touched production sources
- `git diff --check` passed

### Known unrelated package debt

The existing full-package Auth PHPStan migration typing debt remains outside the v1.0.1 change set. All Auth production sources changed for v1.0.1 pass maximum-level PHPStan analysis.
