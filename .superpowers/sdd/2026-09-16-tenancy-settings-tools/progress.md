# SDD ledger — plan: docs/superpowers/plans/2026-09-16-tenancy-settings-tools.md

Prepared during Foundation F2. No Settings/Tools implementation has started. U1/U2 execute after reviewed F7, before F8. Later tasks wait for their program dependencies.

## Preflight task consistency

| Task | Self-consistency / binding decision |
|---|---|
| U1 | Narrow platform config projection before isBooted; no Platform lease. Legacy store proof precedes U3 real adopted store proof. |
| U2 | Neutral Filterable/Data proof now; domain DTO prohibitions remain in each early adopter commit once its tenant API exists. No Tenancy dependency in either neutral package. |
| U3 | Actual adoption/schema with tenant values; cache callback captures persisted ownership. Native runner transaction lifetime ruling also applies here; see below. |
| U4 | Source Actions remain explicit platform operations; separate tenant override table and artifact paths. Resource/adoption registration is required in this task, then rehearsed by U6. |
| U5 | Scalar class-resolved CSV handlers and JSON manifests; closure rejection precedes staging. Foundation queue context must precede deserialization. |
| U6 | Final adapter/adoption/distribution evidence completes earlier adapters rather than recreating them; infrastructure availability gates real proof. |

## Shared-file/interface pairs

| Pair | Produced / consumed boundary | Check |
|---|---|---|
| U1/U2 | Foundation-neutral input contracts | No runtime dependency added to neutral packages by U1 provider closure. |
| U1/U3 | Settings provider, PlatformSettingsReader, legacy/adopted storage | U3 replaces legacy global reads with explicit ownership_key=platform, preserving narrow boot authorization. |
| U1/U6 | Settings dependency, manifest/config/doctor | U6 verifies actual registration; U1 must be independently installable first. |
| U2/U3 | Settings DTO server-owned fields | Generic projection does not replace explicit owning DTO validation. |
| U2/U4 | Translation DTO ownership fields | Domain Action admission and validation remain in Translations. |
| U2/U5 | CSV trusted handler and caller query | Neutral filter correctness does not authorize arbitrary handler/raw SQL. |
| U3/U6 | Settings registrar, adapter, migration, docs | Create real adapter alongside value ownership; U6 completes rehearsals and distribution, no duplicate schema definition. |
| U4/U6 | Translations registrar, adapter, migration, docs | Source and override resources stay distinct; final rehearsals reuse actual adapter. |
| U5/U6 | CSV adoption package with no Eloquent root | resources() remains empty; readiness records manifest/queue cutover without inventing tenant table. |
| F2/U3 | Runner transaction lifecycle and afterCommit invalidation | Native runner cannot leak a transaction across a different context; isolated observer proof may stress captured identity with test-only context. |
| F4–F7/U1–U6 | Resource registry, adoption, queue, compatibility | Consume reviewed foundation APIs; never seed active markers manually in downstream database tests. |

Ruling: The plan's prohibition on runtime changes applies to its planning-review phase; the user's subsequent "do it" authorizes this execution — otherwise every implementation checkbox would contradict the accepted execution request — if wrong, local reversible commits remain isolated and no external release is made.

Ruling: U3 must retain F2's native runner transaction-lifetime protection. Its requested post-context afterCommit invalidation proof uses a controlled test-only context with a real outer commit to prove captured cache identity independently, and a separate native runner test proves leaked transactions are rejected — allowing a transaction to outlive a native context would reopen maintenance queue escape — if wrong, that isolated stress fixture must be adjusted; production context transitions remain fail-closed.

Prepared briefs: task-1-brief.md through task-6-brief.md extracted verbatim by the skill helper. These extracts contain task text only: append Global Constraints, relevant delivery/test setup, current reviewed dependency state, and the F2/U3 transaction ruling before dispatch. No task is complete yet.

During F6 implementation, appended exact Global Constraints/delivery conventions and authorized-execution context to U1/U2 briefs. Both await reviewed F7/F6 handoff; no implementation started. U3–U6 extracts still need full fixture/registration context when dispatched.

During F6 implementation, controller appended exact global/shared interface/test setup context to remaining briefs 3,4,5,6. These are prepared requirements only, not dispatched or completed. Before EACH dispatch add current reviewed dependency/API handoff and task-specific preflight rulings; read ledger. Full source plan need not be reread by worker.

Ruling: U1 may add the bounded cross-package internal guard TenantInstallationState::assertUnadopted(Connection $connection, ?string $resource = null): void, sharing the existing exact-Connection marker probe/cache; a null key denies any persisted adoption marker, a concrete key denies that resource's marker regardless of state — U1's pre-registrar platform projection and L1's disabled undeclared owner need to prove legacy storage without dummy registration — if wrong, the conservative null-key path may block unrelated legacy owners on an adopted connection and can later be refined using explicit ownership declarations. The guard grants no Tenant/Platform privilege, must propagate schema/connection errors, never read a newly selected core connection instead of supplied canonical storage, and must not relax assertUsable. U1 reader separately rejects partial ownership schema; U3 declared adopted resources continue through assertUsable plus explicit platform partition.

U1 starting from reviewedc713b5b (F7fullreview+fixclean); exactbriefupdated. New Settings bootstrap plus approvedboundedlegacyguard only; U3ownsrealSettingsadopter. Fresh standardmodel implementer ownsserialrunner.

U1 agent /root/tenancy_u1_implementation (Sol/high) dispatched fromc713b5b, ownsserialrunner. Full report expectedtask-1-report.md; review notyetstarted. ReviewedF7producer/batch handoff appendedto laterqueue-owningtasks topreventusingobsoleteambientcaptureassumptions.

Ruling: U1 may add package-internal PlatformSettingsReader::available(): bool; false means only absent, unadopted Settings storage on the canonical model connection, while a present empty table is true so mapped defaults apply — the frozen records(): Collection signature cannot express this distinction without duplicating schema discovery across callers — if wrong, the internal seam can later become a typed read result without changing existing public signatures. Both available and records independently enforce persisted adoption/schema guards; connection/partial-schema/prepared/adopted failures propagate, never become false. Bootstrap/runtime applier check availability before invoking the shared writer.

U1 initialmilestone: relevant skills/Boost complete, APIs/fixtures mapped, no RED yet; available() ambiguity now ruled. Implementer told to raise bounded issues promptly and proceed with named RED/GREEN. No tool/runner blocker.

U1 milestone: initial namedruntimeRED confirmed missingTenantBoundaryViolation. Implementation seams in place; focusedSettings7/8behaviorassertions pass, unavailableDB yields intendedQueryException but failedTestbenchrefresh leaves badDBconfigfor teardown. Test-onlyfinally restorationofvalidisolatedDB/application approved asroutinecleanup, preservingoriginalproductionexception. TenantInstallationGuard focused30/49GREEN includingnewnull/concreteassertUnadopted andexistingassertUsablecompatibility. AwaitingfinalGREEN/gates/report/commit.

Controller named-risk check duringU1: a platform-mapped deployment flag changing after ScopedTenantContext initialization cannot create enabled legacy fallback. Unchanged TenantBoundary::admit always uses current enabled flag for readiness/state validation and then rejects Disabled/Unresolved snapshots in enabled mode (lines105-121); ScopedTenantContext captures initialmode. No defect found, no production/scope expansion or extra default deny rules introduced.

U1 milestone: focused Settings 11 tests/19 assertions and installation guard 30/49 pass after test-only failed-refresh cleanup. Package PHPStan attempts returned exit1 without diagnostics; controller/implementer found per-package vendor links absent, unlike prior successful F7 setup. Implementer owns temporary-link verification and all serial gates; no passing static result claimed yet. Root reminded implementer to synchronize settings internal dependency metadata with new Tenancy requirement.

U1 verification milestone: full Settings86/403 and Tenancy308/1159 pass. Temporary package vendor links restored actual configured PHPStan output; after one Collection generic fix, Settings and Tenancy max-level0errors. Links removed. Metadata/contracts/family, finalselfreview/report/commit pending; no blocker.

Ruling: The U1 dependency-audit failure in unchanged Tenancy declarations/test fixtures is carried into F8, which explicitly owns the foundation dependency audit and archive release gate; U1 may complete only with its own Settings audit clean and the full failure recorded — expanding this bootstrap prerequisite into unrelated foundation packaging would obscure the scoped review, while F8 precedes all domain adopters — if wrong, U1 review may require moving a directly caused finding back into its fix loop; the whole-program dependency gate is not considered passing until resolved.

U1 controller named handoff check: PlatformSettingsReader::available currently rejects ownership_key but omits tenant_id-only partial schema. Sent concrete partial-schema requirement back to implementer before commit for focused regression/correction; root made no production edit. Also appended U3 continuation pointer to its reader/reboot proof. Exact Tenancy dependency audit diagnostics pending implementer report; carried explicitly to F8 release gate, not silently waived.

U1 partial-schema guard corrected with real tenant_id-only RED1/2 then rebootGREEN7/9; runtime safetyGREEN6/12. Settings PHPStan0errors/Pintpass after finalchange. U1 autoload case moved to matching PSR-4path. Wholeprogramautoload/dependency checks stillfail on the exact 3preexistingF7testlocalclasses +7Tenancyshadowdeps nowrecordedinF8brief/ledger. Finalreport/commitpending.

U1 scopedimplementationcommit bedfbf0f5a561cc0037301690791bc416ef43318 created, worktreeclean. Controllerprepared full review-c713b5b..bedfbf0.diff (1commit,44861bytes), awaitingtask-1-report/DONEbefore independentreview. Notmarkedcomplete.

U1 report received DONE_WITH_CONCERNS (only recordedF8dependency/autoloadgate debt); reportcomplete, focusedfinalSettings6/12+7/9, guard30/49, fullSettings86/403andTenancy308/1159beforelastnarrowchecks, configuredPHPStan0errorsboth. Fresh Sol/high reviewer /root/tenancy_u1_review dispatched on fullc713b5b..bedfbf0diff, read-only/taskscoped; task-1-review.md expected. Serialrunnerfree, no suite reruns requested.

Ruling: U2 keeps its prescribed fixture model/DTO names, fields, and behavioral assertions, but places named fixture classes in package-local PSR-4 fixture files when required by the repository autoload/dependency gates — the inline plan snippets demonstrate self-contained package behavior, while prior queue-local classes show that non-autoloadable fixtures fail the release checks — if wrong, only test file organization needs revision; neutral runtime contracts and test scenarios remain unchanged.

U1 independent review: spec compliant, quality Approved, no Critical/Important findings. Minor deferred: positive ConfigOverrideApplier path through TenantRunner::platform lacks a direct regression (ConfigOverrideApplier.php:33); assigned to U3 real platform/adopted Settings fixture and final review.

U1 cannot-verify resolution, mail: controller inspected unchanged MailNotificationsServiceProvider::boot (calls MailTestingInterceptor::apply), MailTestingInterceptor::apply (sets global mail.to and forgets cached mailers), and existing MailTrackingTest recipient/CC/BCC/explicit-mailer proofs. U1 changes no mail-notifications file and extracts the existing config mapping/denial loop into PlatformConfigWriter without changing it. Source preservation is verified; no new Settings+mail end-to-end run is claimed. W5 remains the required real tenant Settings/SMTP integration proof. Boost documentation process evidence requested as report-only addendum from original implementer; initial milestone already recorded it as completed.

U1 Boost cannot-verify item resolved: original implementer appended prior search-docs arguments and returned Laravel13/Pest4 source topics to task-1-report.md. This records the actual before-code search, not a retroactive rerun.
Task 1: complete (commits c713b5b..bedfbf0, review approved; one deferred Minor assigned U3; existing foundation dependency/autoload release debt assigned F8).

U2 starting from reviewed bedfbf0f5a561cc0037301690791bc416ef43318. Requirements in task-2-brief.md; fresh standard-model implementer will own the serial runner.

U2 agent /root/tenancy_u2_implementation (Sol/high) dispatched from bedfbf0. It owns the serial runner. Full report expected task-2-report.md; review has not started.

U2 setup milestone: applicable skills and Laravel13 Boost logical-grouping docs read; current production callback inventory in Media/Forms/Translations confirmed predicate-only; PSR-4 fixture convention confirmed. No unresolved requirement or runner blocker. Implementer proceeding to the minimal PredicateRecord custom-OR RED before broader matrix.

U2 named RED confirmed: PredicatePreservationTest expected IDs [1] but returned [1,2] (one failed test/assertion), demonstrating custom orWhere broadens the caller owner=a predicate. Evidence recorded live in task-2-report.md; fixture is package-local PSR-4. Implementer now applies minimal nested where and immediate focused GREEN.

U2 live-report milestone: expanded Filterable proof passes 5 tests/12 assertions (negative/null custom predicates, multiple handlers, related ownership/corrupt cross-owner row, sort/count/pagination, empty and ordinary consumers). Data projection passes immediately 1/1, with no runtime Data edit. Docs and canonical Filterable skill describe trusted predicate-only callbacks. Remaining package/gate/self-review/commit work belongs to implementer; controller requested stale GREEN-pending report wording and Boost query evidence be corrected.

U2 quality tooling observation: run-package-quality.php filterable data --continue-on-error passed both formatters/full suites but reported silent PHPStan failure. Direct replays of the generated configs/paths/options succeeded with0errors, including exact absolute paths; no --debug, formatter, or env change. Root confirmed generated config uses absolute Larastan/Carbon includes and process cwd root, so this is distinct from U1 missing package vendor links. No root cause established and no runner mutation made. F8/final release verification must keep wrapper status separate from valid direct static results and investigate a recurrence with focused process evidence, not silently count the wrapper as passing.

U2 implementation complete at 81d2e61a37f8b1bd2d4484a380a22c6e8df9db5d; worktree clean. Report task-2-report.md records Filterable98/217, Data57/346, Forms3/37 and Translations1/12; final fixture-only amendments covered by focused5/12 plus fixture max-level/Pint/autoload. Direct production and fixture static analysis0errors; shared wrapper/autoload/dependency concerns explicitly retained for F8. Full review package review-bedfbf0..81d2e61.diff (1commit,27442bytes). Fresh Sol/high reviewer /root/tenancy_u2_review dispatched, report task-2-review.md expected. No task completion until independent verdict. Serial runner free.

Controller focused wrapper diagnostic: exact Symfony Process analysis replay and actual runner-generated fresh-config analysis both return0/0errors/emptystderr. No whole suite run, no source/dependency edit, no current reproduction. Full wrapper still awaits required F8 gate. Runner released. Details appended to task-2-report.md.

U2 review milestone reports one Important verification gap: no direct MediaFilters/applyFilterSet consumer execution proof, despite brief requiring current consumer coverage. Core production/spec otherwise appears correct. Reviewer is finalizing original-range report. Fix round 1/5 started from 81d2e61 with original implementer /root/tenancy_u2_implementation, finding sent verbatim; focused new MediaFilterContractTest.php plus PredicatePreservationTest.php and changed-file gates required. No production change unless a real failure is demonstrated. This is expected characterization coverage, no artificial RED. Original review remains on bedfbf0..81d2e61; fix gets separate scoped review. Runner belongs to implementer.

U2 original review finalized in task-2-review.md: spec issues/quality Needs fixes solely for Important Media applyFilterSet/orWhereJsonContains consumer proof. No Critical or Minor findings. Final finding forwarded to active fix implementer. Cannot-verify items are existing F8 dependency/autoload debt plus non-reproducible wrapper observation; both already have explicit controller release handoffs and supporting direct checks, not newly discovered U2 defects.

U2 fix round1 implementation commit 061e0c04937badb5455a302ee71a000a7f3bbc7a adds only MediaFilterContractTest. Report records real applyFilterSet filename/type/JSON-tag branches, disk=public caller restriction, matching s3 row exclusion, in-scope decoy exclusion. Media1/5 and genericFilterable5/12 pass; changed-file PHPStan0errors and Pint pass; knownF8autoloaddebt unchanged. Full fix diff review-81d2e61..061e0c0.diff (1commit,2569bytes) sent to fresh Sol/medium reviewer /root/tenancy_u2_rereview1. Awaiting task-2-rereview-1.md. Runner free.

Task 2: fix round 1/5 (1 addressed, 0 open; commits 81d2e61..061e0c0). Scoped independent re-review clean, no new findings; existing F8 tooling observations unchanged.
Task 2: complete (commits bedfbf0..061e0c0, review clean after one fix round). U1/U2 prerequisite block is complete; next program task F8. U3–U6 remain unimplemented and wait for program dependencies.

Ruling: W1 proves Activity recording and deferred capture for the existing value-free Settings subject protocol (`nvl_setting` plus scalar id) using package-local model-free fixtures; U3 owns the real tenant SettingRepository → SettingChanged → Activity integration proof after Settings adoption exists — U1 deliberately has no Settings registrar/adopter, so loading Settings into a real tenant fixture before U3 must fail F6 readiness and Activity must not gain a Settings runtime dependency merely for tests — if wrong, the real integration proof must move earlier with its genuine prerequisites; no dummy Settings registration, fake active marker, or claim of completed tenant Settings integration is allowed.

## Implementation-first continuation — U3-U6

The user explicitly required all remaining implementation surfaces before any runtime verification. Accordingly, authored tests below are implementation artifacts only; no RED/GREEN, suite, consumer, matrix, independent review, PHPStan, Pint, contract, package-validation, or broad verification result is claimed.

- U3 implemented in `223812b`: mixed platform/tenant Settings ownership, immutable platform definitions, real registrar/adopter/schema/Doctor, captured cache invalidation identity, native transaction-lifetime protection, and value-free Activity `SettingChanged` bridge.
- U4 implemented in `877e2c5`: platform-only source translation tooling, separate tenant copy overrides/artifacts, literal allowlist/revision guards, registrar/adopter, Doctor/docs/skill surfaces.
- U5 implemented in `b8f20bf`: scalar class-resolved CSV handlers, closure rejection before staging, JSON manifests and context restoration before deserialization, exact cleanup, fresh handler instances, and empty-resource adoption readiness.
- U6 authored: adoption registration coverage, Settings/Translations/CSV diagnostics, package-family and production-consumer metadata, CI composition, docs/upgrading/changelog/skills, and explicit implementation report.
- Deferred commands: every plan test command, consumer, database/cache/worker matrix, independent review, PHPStan, Pint, contracts, package validation, Composer/archive install, and config/route cache run is **UNRUN (deferred by user)**.
- `tools/package-contracts.json` refresh is **UNRUN and intentionally deferred by user**.
- Completion record: `.superpowers/sdd/2026-09-16-tenancy-settings-tools/settings-tools-implementation-report.md`.
