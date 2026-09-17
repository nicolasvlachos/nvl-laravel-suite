# Auth tenancy A1-A10 implementation report

Date: 2026-09-17
Branch: `codex/configurable-tenancy`
Reviewed base: `a4230c86e52d4cf4e391b835ca974636ddfc226c`

## Outcome

Auth tenancy A1-A10 is implemented as an opt-in capability. Disabled/default installs retain their global behavior. Enabled installs remain fail-closed until adoption is verified and activated, require explicit tenant context and membership, bind tenant-owned RBAC/invitations/tokens/intents/audits to the active tenant, and expose readiness only after the complete adoption/Doctor contract is satisfied.

## Requirements delivered by task

### A1 — inert capability declaration (`59660ee`)

- Added the Auth tenancy feature/config surface, resource inventory, ability aliases, provider hooks, and dependency declaration.
- Kept tenancy disabled by default and avoided eager bindings for incomplete capability paths.
- Proved disabled-provider and legacy feature-manifest compatibility.

### A2 — tenant ownership schema (`aa9c36d`)

- Added the independently selectable Auth tenancy migration, tenant membership/lock/intent models and factory, table declarations, and empty-install adopter.
- Added tenant-leading Spatie v8 role/pivot constraints, invitation/token/audit discriminators, and the real coordinator fixture.
- Used the foundation deny-default membership adapter; schema-only activation did not advertise full readiness.

### A3 — membership lifecycle (`b99b1cc`)

- Added enrollment, status, revocation, ownership transfer, owner provisioning, own-membership listing, DTOs, and membership access/principal resolution.
- Centralized writes in transaction-owning actions/services with stable tenant owner locks and last-owner protection.
- Added the independent-process race harness and tenant-aware audit prerequisites.

### A4 — tenant RBAC (`ff3455f`)

- Added tenant-aware Spatie v8 role assignment/query semantics without a context-dependent global scope.
- Added tenant context participation and tracked-principal cleanup for long-lived workers.
- Kept global permission vocabulary operations distinct from tenant role templates and assignments.

### A5 — principal and membership APIs (`5cbe79c`)

- Kept principals global while separating platform-only identity mutation from tenant membership management.
- Added account and management membership routes/controllers/resources and explicit tenant admission.
- Applied compatible owner-lock ordering to global principal deletion/deactivation paths.

### A6 — invitations (`223bc1f`)

- Bound invitations to stored platform/tenant ownership and verified recipient proof.
- Added atomic tenant membership bootstrap/acceptance with role revalidation inside the stored tenant.
- Captured delivery context without accepting client record IDs as tenant proof.

### A7 — Sanctum tokens (`8823ca9`)

- Added immutable tenant binding to all package-managed token lifecycle operations.
- Enforced stored token tenant equality plus current membership/admission before bearer use.
- Added tenant-bound adapter/Doctor contracts while preserving disabled token projections.

### A8 — authentication intents (`2b84295`)

- Added expiring, one-use tenant authentication intents and browser-session binding.
- Preserved Socialite's existing OAuth state as authoritative and kept tenant intent separate.
- Added context-aware pruning and restored tenant selection only after current membership admission.

### A9 — audit, events, and worker restoration (`2369a91`)

- Completed tenant-aware audit persistence, central identity audit allowlisting, event context capture, and Activity bridge contracts.
- Added queued delivery envelopes that restore tenant context before listener execution and clear tenant/Spatie state after success, retry, and failure.
- Added genuine database queue-worker fixtures; no Queue fake or direct listener call is used as the worker proof.

### A10 — adoption, readiness, docs, and sealed consumer (`97bb179` plus final follow-up commit)

- Completed deterministic adoption classification/backfill/verification/activation, resumable fingerprints, legacy token disposition, role/membership mapping, and final constraints/indexes.
- Optional feature-owned tables are classified only when installed; absent challenge/session/social profiles remain valid.
- Completed Auth Doctor readiness, schema/pruning checks, generated types, OpenAPI, operations/security/schema/configuration/API documentation, contract baselines, and package-family declarations.
- Extended the sealed consumer to four profiles: package/application-owned migrations with tenancy disabled, and package/application-owned migrations with tenancy enabled.
- Tenant consumer profiles load only Support, Data, Tenancy, Activity, and Auth. Settings and Mail Notifications remain enabled only in the preserved disabled-tenancy profiles.

## Files and architectural areas

- Configuration/provider/schema: `packages/nvl/auth/config/nvl-auth.php`, `packages/nvl/auth/database/migrations`, `packages/nvl/auth/src/Providers/AuthServiceProvider.php`, `packages/nvl/auth/src/Services/AuthSchemaManager.php`.
- Membership/RBAC/admission: `packages/nvl/auth/src/Actions/Memberships`, `packages/nvl/auth/src/Services/Membership*`, `packages/nvl/auth/src/Services/AuthTenant*`, `packages/nvl/auth/src/Relations`, role/permission models and actions.
- Invitations/tokens/intents: invitation actions/services/value objects, `SanctumApiTokenManager`, token admission middleware/policy, `TenantAuthenticationIntents`, browser/Socialite adapters.
- Audit/events/workers: `AuthAuditWriter`, `CentralIdentityAuditRecorder`, `AuthEventContext`, delivery/event value objects, queued-worker fixtures/tests.
- Adoption/readiness/docs: `AuthTenancyAdoption`, `AuthTenancyMapping`, `AuthDoctorCommand`, Auth package docs/OpenAPI/types.
- Release integration: `tools/fixtures/auth-production-consumer`, `tools/run-auth-production-consumer.sh`, `tests/Contract/AuthProductionConsumerWorkflowTest.php`, `tools/package-contracts.json`, `tools/package-family.php`.

## Focused evidence

- A1-A2: 12 schema/provider/legacy tests with 80 assertions; A2 schema 5 tests with 13 assertions; foundation configuration 12 tests with 48 assertions.
- A3-A5: tenancy/manifest 19 tests with 909 assertions; RBAC 37 tests with 352 assertions; principal/HTTP/routes 18 tests with 124 assertions.
- A6-A8: invitation 28 tests with 145 assertions; token/Doctor 22 tests with 105 assertions; intent/authentication/social/challenge/passkey 33 tests with 170 assertions.
- A9: audit/event/delivery 16 tests with 210 assertions.
- A10: adoption/Doctor/schema/pruning/OpenAPI 12 tests with 393 assertions.
- Final adoption absent-table regression: `php artisan test --compact packages/nvl/auth/tests/Feature/TenancyAdoption/AdoptionTest.php` — 3 passed, 9 assertions.
- Final consumer contract before the last source-only selector assertions: `php artisan test --compact tests/Contract/AuthProductionConsumerWorkflowTest.php` — 4 passed, 138 assertions. The selector and final tenant configuration were then exercised by the targeted and full sealed consumer runs below.

## Final runtime/database/archive matrix

### Portable package and contracts

- `php artisan test --compact packages/nvl/auth/tests` — 227 tests: 225 passed, 2 skipped, 2,886 assertions, 34.751s. The two skips are the intentionally native-engine-only forked owner race proofs.
- `php artisan nvl:data:types:check --no-interaction` — passed; generated declarations current.
- `composer contracts:check` — passed after the intentional Auth A1-A10 public contract baseline refresh.
- `composer packages:validate` — passed for all 21 packages; Auth dependencies declare Data, Support, and Tenancy.

### PostgreSQL 17.6

- Stateful all-feature Auth suite using the recorded task-owned PostgreSQL socket/database — 212 of 227 passed; 15 legacy timestamp-portability failures outside the A1-A10 tenancy paths (1 invitation, 2 password broker, 2 challenge, 4 passkey lifecycle, 6 WebAuthn ceremony).
- Focused adoption/schema command — 7 passed, 21 assertions.
- Focused authentication-intent command — 2 passed, 6 assertions.
- Focused membership plus independent owner-race command after aggregate-lock correction — 5 passed, 13 assertions.
- Focused tenant role-relation command — passed.

### MySQL 8.4.7

- Stateful tenancy/adoption command using the recorded task-owned MySQL socket — 38 passed, 131 assertions, 62.605s.
- Independent owner-race recheck — 1 passed, 3 assertions.

### MariaDB 12.3.3

- Stateful tenancy/adoption command using the recorded task-owned MariaDB socket — 38 passed, 131 assertions, 178.060s.
- Independent owner-race recheck — 1 passed, 3 assertions.

### Real worker/context restoration

- Database queue-worker/context proof — 2 passed, 8 assertions.
- Combined worker plus RBAC restoration recheck — 3 passed, 12 assertions.
- The final delivery event serializes the real `TenantJobEnvelope`, rejects missing event context, restores the tenant before deserialization/handling, and leaves no tenant/team state behind.

### Static analysis and formatting

- `(cd packages/nvl/auth && ../../../vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=2G)` — passed with 0 errors after correcting all 114 initially reported Auth-scope findings.
- Sealed consumer fixture PHPStan — passed with 0 errors before the final profile-only fixture refinements; the final refinements were exercised from the archived artifact.
- `vendor/bin/pint --dirty --format agent` — passed after the final consumer changes.
- `git diff --check` — passed.

### Sealed production consumer

- Targeted debug: `AUTH_CONSUMER_PROFILE=package_owned_tenant ./tools/run-auth-production-consumer.sh` — exit 0.
- Targeted debug: `AUTH_CONSUMER_PROFILE=application_owned_tenant ./tools/run-auth-production-consumer.sh` — exit 0.
- Definitive full run: `/bin/zsh -o pipefail -c './tools/run-auth-production-consumer.sh 2>&1 | tee /tmp/auth-production-consumer-final.log'` — exit 0.
- All four profiles created clean Laravel 13 applications from the sealed ZIP with `symlink:false`, installed the candidate, cached config/routes, migrated, generated/checked types, ran their profile-specific Doctor/audit/smoke checks, ran Composer audit, and rolled back.
- Disabled profiles preserved Settings/Mail lifecycle, real database queue worker, generated TypeScript compilation, and strict suite consumer audit.
- Tenant profiles loaded only Support/Data/Tenancy/Activity/Auth and proved two tenants, two memberships, distinct tenant roles, tenant-bound token issuance, invitation acceptance, membership revocation, Auth Doctor readiness, and both migration ownership modes.

## Failed/retried/skipped evidence

- The first final consumer attempt stopped before application code because sandbox DNS could not resolve Packagist. The identical pipefail command was retried with narrow network escalation.
- Consumer debugging then exposed and fixed, in order: optional absent Auth table classification, unrelated Settings/Mail modules loaded in tenant profiles, missing exact fixture authorization for tenant role synchronization, the ApiTokens-to-Authentication dependency, and missing exact fixture authorization for membership revocation. Package fail-closed defaults were not weakened.
- PostgreSQL broad-suite failures are recorded above and were not reclassified as passes. Tenant-specific PostgreSQL proofs passed after the scoped fixes.
- `composer dependencies:check` passed Auth but the whole-family command stopped in the unrelated Translatable package on five pre-existing shadow dependencies (`ext-pdo`, `ext-redis`, `symfony/console`, `symfony/filesystem`, `symfony/process`). No Translatable metadata was changed in this Auth batch.

## Decisions and concerns

- Global principals remain global. Memberships, roles, invitations/tokens/intents/audits are tenant-aware only where the frozen contracts require it.
- No nullable/fallback platform audit discriminator was introduced.
- Spatie's vocabulary cache remains global; tenant assignment/read authority is constrained separately.
- The consumer fixture grants only the exact sealed system abilities and validates its reason/correlation identifiers.
- Downstream Media/Metafields/Taxonomy tenancy declarations were intentionally not absorbed into Auth.
- Release concern: the broad PostgreSQL all-feature suite still has 15 non-tenancy timestamp-portability failures, and the whole-family dependency check still has the unrelated Translatable findings listed above. All Auth tenancy-specific PostgreSQL, MySQL, MariaDB, worker, static, and sealed-consumer gates are green.

## Independent review correction wave

The `auth-full-review.md` findings (1 Critical, 5 Important) were verified against the implementation. All six findings were confirmed and corrected; none were disputed.

### Corrections delivered

- **C-1 — RBAC platform authority:** every permission-vocabulary mutation/catalog Action now requires platform administration. `BootstrapRbacAction`, `SynchronizeRbacAction`, and `CreatePermissionWithRolesAction` reject enabled-tenancy execution with `rbac_mixed_context_operation`, while their disabled legacy behavior remains intact. The public-entry inventory has negative coverage using the permissive tenant actor.
- **I-1 — deterministic locking:** membership and invitation mutations resolve bounded identifiers before mutable locks, acquire the stable tenant lock first, and then lock membership/invitation rows deterministically. Transactions no longer rely on deadlock retry for serialization. Native acceptance-versus-registration and distinct-owner-removal races pass on all supported engines. MariaDB's same-token losing lock reports driver error 1020 rather than the PostgreSQL/MySQL result; that exact condition is translated to the deterministic `invitation_invalid` domain result without retry.
- **I-2 — public authentication intent:** magic-link, security-code, and passkey starts now persist server-owned one-use tenant intent references with exact purpose/session/provider/subject binding; completion recovers that state before session establishment. Real HTTP tests cover replay, mismatch, expiration, suspended tenants, and magic-link/security-code/passkey transports.
- **I-3 — authoritative integrations:** `nvl-auth.tenancy.activity_bridge` and `nvl-auth.tenancy.recipient_proof` are authoritative `disabled|class-string` settings. Provider bindings validate and resolve those exact paths. Doctor rejects invalid configuration, disabled required integrations, and configured/resolved mismatches. The sealed tenant fixture supplies a real tenant-aware Activity bridge.
- **I-4 — dependency injection:** new `app(AuthTenantRbacQueries::class)` fallbacks were removed. Provider/constructor injection is used; direct disabled construction remains compatible through a nullable collaborator, while enabled missing-dependency use fails closed with a configuration error.
- **I-5 — Action ownership:** the umbrella `MembershipMutator` was removed. Named membership Actions now own authorization, transactions, lock orchestration, writes, and after-commit audit effects; focused guards/writers remain transaction-agnostic.

### Correction-wave evidence

- Scoped Auth correction set after formatting: `vendor/bin/pest --test-directory=packages/nvl/auth/tests --configuration=packages/nvl/auth/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact <seven focused files>` — 23 tests: 21 passed, 2 expected native-only skips, 112 assertions. This covers invitation acceptance/bootstrap, membership races, the complete RBAC boundary inventory, real HTTP intent flows, Doctor/provider configuration, and audit bridge behavior.
- Sealed consumer source contract: `vendor/bin/pest --compact tests/Contract/AuthProductionConsumerWorkflowTest.php` — 4 passed, 151 assertions.
- Native `MembershipOwnerConcurrencyTest` (task-owned services, transaction attempts fixed at one): PostgreSQL 17.6 — 2 passed, 8 assertions; MySQL 8.4.7 — 2 passed, 8 assertions; MariaDB 12.3.3 — 2 passed, 8 assertions.
- Targeted tenant artifact proof: `AUTH_CONSUMER_PROFILE=package_owned_tenant bash tools/run-auth-production-consumer.sh` — exit 0 after placing adoption before audited platform catalog synchronization.
- Definitive artifact proof: `bash tools/run-auth-production-consumer.sh` — exit 0 for all four profiles (`package_owned_disabled`, `application_owned_disabled`, `package_owned_tenant`, `application_owned_tenant`). Both tenant profiles proved adoption, platform vocabulary synchronization, two isolated tenants, owner provisioning, tenant role synchronization, tenant-bound API tokens, invitation acceptance, membership revocation, Doctor readiness, Composer audit, and rollback.
- Auth static analysis: `(cd packages/nvl/auth && ../../../vendor/bin/phpstan analyse --memory-limit=2G --debug)` — 0 errors after final formatting.
- Consumer fixture static analysis: `vendor/bin/phpstan analyse --memory-limit=2G --debug tools/fixtures/auth-production-consumer/app` — 0 errors after final formatting.
- Formatting: `vendor/bin/pint packages/nvl/auth tools/fixtures/auth-production-consumer/app tools/fixtures/auth-production-consumer/config/nvl-auth.php tests/Contract/AuthProductionConsumerWorkflowTest.php --format agent` — passed. Explicit Auth-only paths avoided concurrent Media work.
- `git diff --check -- packages/nvl/auth tools/fixtures/auth-production-consumer tests/Contract/AuthProductionConsumerWorkflowTest.php` — passed.

### Correction-wave concerns

- No new Auth release concern was found. The previously disclosed broad PostgreSQL legacy timestamp failures and unrelated Translatable dependency findings remain outside this correction wave and were not rerun.
- The shared worktree contains concurrent Media changes. They were neither edited intentionally, staged, reset, nor included in the Auth correction commit; Auth paths are clean after the path-specific commit.

## Final I-2 / N-1 targeted closure

The follow-up review's remaining I-2 and new N-1 findings were confirmed and corrected without changing Media or the sealed consumer fixture.

- `TenantAuthenticationIntents::consume()` now requires the server-resolved expected tenant and compares it under the locked intent row before writing `consumed_at`. Missing, invalid, and conflicting completion selectors are denied and cannot consume a different tenant flow's nonce. The real magic-link HTTP mismatch test proves the intent remains unconsumed, then completes with the correct tenant and proves the intent is consumed exactly once.
- Generic `security-codes` request/verify routes again use `RequestSecurityCodeAction` and `VerifySecurityCodeAction`; verification returns proof without session establishment and continues to support host-managed recipients in disabled-tenancy mode.
- Explicit `security-codes/authentication` request/verify routes now own subject resolution, the exact `passwordless_login` purpose, tenant-intent attachment/consumption, and session establishment. Generic purposes cannot enter that authentication path.
- Required tenant-resolver injection is preserved for enabled flows. An Auth-owned disabled resolver is bound only when no host resolver exists, so disabled public routes remain injectable without silently bypassing a configured resolver.
- OpenAPI, route inventory, flow documentation, and the Auth public-contract baseline include the explicit passwordless surface.

### Targeted closure evidence

- Tenant HTTP intent and atomic-intent tests: `vendor/bin/pest --test-directory=packages/nvl/auth/tests --configuration=packages/nvl/auth/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/auth/tests/Feature/Tenancy/AuthenticationIntentHttpTest.php packages/nvl/auth/tests/Feature/Tenancy/AuthenticationIntentTest.php` — 8 passed, 52 assertions.
- Generic challenge, disabled HTTP compatibility, and OpenAPI tests: `vendor/bin/pest --test-directory=packages/nvl/auth/tests --configuration=packages/nvl/auth/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/auth/tests/Feature/ChallengeLifecycleTest.php packages/nvl/auth/tests/Feature/HttpApiTest.php packages/nvl/auth/tests/Unit/OpenApiContractTest.php` — 11 passed, 393 assertions.
- Route/feature manifest alignment: focused `FeatureManifestTest` route-alignment case — 1 passed, 278 assertions.
- Direct sealed-consumer source contract: `vendor/bin/pest --compact tests/Contract/AuthProductionConsumerWorkflowTest.php` — 4 passed, 151 assertions. The four-profile Composer consumer was not rerun because its fixture did not change.
- Auth PHPStan: `(cd packages/nvl/auth && ../../../vendor/bin/phpstan analyse --memory-limit=2G --debug)` — 0 errors.
- Public contracts: `composer contracts:check` — unchanged after the Auth-only baseline refresh.
- Pint: explicit changed Auth PHP paths with `vendor/bin/pint --format agent` — passed.

An intermediate combined HTTP run exposed that nullable controller method injection selected its `null` default even when the tenant resolver was bound. Required injection plus the disabled-only sentinel corrected that issue; the isolated affected groups above are the final evidence. No broad package, native database, or four-profile consumer matrix was rerun, per the targeted-fix instruction.

## Final public tenant-intent retry closure

The final I-2 re-review correctly identified that rewinding `Challenge::consumed_at` in a test did not prove a production caller could retry tenant selection. The database rewind was deleted and replaced with a real post-authentication completion seam.

- A denied selector now stores the exact nonce, session binding, subject, provider, purpose, and subject-binding flag only in the authenticated Laravel session. The client never submits or selects those values.
- `POST tenant-intents/complete` accepts no request body, requires an authenticated session, resolves only the trusted server tenant selector, and atomically consumes the pending intent for that expected tenant. Success removes the pending reference; replay returns `tenant_authentication_intent_unavailable`.
- The original magic-link, explicit passwordless-code, and passkey proof remains consumed exactly once. The real HTTP test proves a tenant-B completion leaves the tenant-A intent unconsumed, a subsequent unmodified tenant-A completion request succeeds, and replay fails. The same seam is exercised after mismatched explicit security-code and passkey completions.
- Disabled-tenancy defaults are unchanged: the new Sessions public route is disabled by default, and existing generic security-code behavior remains host-managed and unauthenticated.
- The passkey completion Action now has the single canonical `execute` entry point required by package-family rules, returning its existing typed completion result. The subject-bound security-code wrapper now documents its approved Action orchestration.

### Final retry evidence

- Tenant intent HTTP/store tests: 8 passed, 59 assertions.
- Challenge, passkey lifecycle, genuine WebAuthn ceremony, and HTTP compatibility tests: 20 passed, 122 assertions.
- OpenAPI/feature-manifest focused contracts: 2 passed, 576 assertions.
- All-enabled route inventory: 1 passed, 1 assertion.
- Disabled-tenancy challenge/provider compatibility: 7 passed, 35 assertions.
- Auth PHPStan: 0 errors.
- Pint on every changed Auth PHP path: passed.
- Package-family validator: Auth has zero violations. The command remains non-zero only for four concurrently owned Media findings (dependency inventory plus service-locator rules in `AppliesTenantBoundary.php`, `Media.php`, and `MediaPathResolver.php`).
- The generated package-contract baseline was intentionally left untouched for the parent-requested combined Auth/Media deterministic refresh after both code commits land. No consumer fixture changed and no broad consumer run was repeated.
