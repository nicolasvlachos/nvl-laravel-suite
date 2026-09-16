# F8 — standalone foundation distribution and utility composition

## Status

DONE — implemented and verified from clean reviewed `061e0c04937badb5455a302ee71a000a7f3bbc7a` on `codex/configurable-tenancy`. No external upgrades or installs into the repository, migration changes, merge, or push. Serial runner released after the scoped task commit.

## Scope and source evidence

- Read the F8 brief, implementer procedure, U1 dependency handoff, U2 report and controller diagnostic, frozen execution contract binding decisions, and existing package archive/consumer conventions.
- Applied backend architecture, Laravel, Data, Filterable, Pest, testing practices, TDD, and verification skills.
- Boost `search-docs` queried `laravel/framework` for `package discovery configuration caching` and `service container bindings`; Laravel 13 confirms package discovery and `bindIf` precedence.
- Tenancy already has Suite configuration-inspector metadata and public/infrastructure seam documentation. F8 verifies these rather than inventing new runtime APIs.
- Production ext-filter/mbstring/PDO and Symfony HttpFoundation/HttpKernel imports were inspected. Mockery and Symfony Process are test-only. Declarations reflect already installed baseline packages with Laravel 13-compatible constraints; root lock changes are limited to the content hash and ext-pdo platform placement, with all selected package records unchanged.

## TDD and characterization

Commands run from the tenancy worktree, serially. Runtime environment is `DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= CACHE_STORE=array QUEUE_CONNECTION=sync APP_ENV=testing`.

### Named RED

`php vendor/bin/pest --test-directory=packages/nvl/tenancy/tests --configuration=packages/nvl/tenancy/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/tenancy/tests/Feature/TenantConsumerContractTest.php`

Exit 1: 1 failed / 2 assertions; registered package source descriptors did not contain `nvl/tenancy`.

`php vendor/bin/pest --compact tests/Contract/TenancyConsumerWorkflowTest.php`

Initial exit 1: 1 failed / 1 assertion; Tenancy catalog `typescript` was false. Expanded archive rehearsal first encountered a fixture setup error (missing host `app` directory), fixed before counting RED. Corrected archive RED: 3 failed / 23 assertions; minimal and explicit Filterable archives reached real cached application boot with isolated dependencies, then failed on missing Tenancy type registration. The third failure was the catalog flag.

### Initial GREEN

- Named package test plus `TenantConfigurationTest.php`: exit 0, 13 tests / 51 assertions.
- Root consumer contract: exit 0, 3 tests / 39 assertions. Both actual archive profiles install an offline Composer-resolved closure, discover packages, cache configuration, execute Doctor, and read classes from the extracted NVL archives. No Suite/Auth loader or package is admitted; minimal profile also excludes Filterable. The Filterable profile additionally proves custom OR and relation predicates retain the caller's tenant restriction, including a corrupt cross-owner relation. Data projection drops forged ownership fields.
- Host binding test captures the exact original `getBindings()` record and compares it after provider registration/boot; the deliberately throwing adapter is never resolved. This is an already-green characterization strengthening the F6 Minor.

## Archive design

`tests/Fixtures/TenancyArchiveConsumer.php` creates Composer ZIP archives for only Support/Data/Tenancy (plus Filterable in its explicit profile), rejects shipped tests/vendor, extracts them into a unique temporary directory, and exposes baseline external packages as offline path distributions. Packagist is disabled and `COMPOSER_DISABLE_NETWORK=1`. Composer resolves the runtime closure into the temporary consumer with `--no-dev --no-plugins --no-scripts --no-audit`; then `check-platform-reqs --no-dev`, package discovery, config cache, and a fresh PHP consumer process run. The monorepo `vendor/autoload.php` is never used by the consumer. All artifacts are removed in `finally`.

The consumer copies U2's existing neutral host fixtures explicitly; these are consumer-owned test input and do not alter runtime package autoload or dependency direction. External source files come from the installed baseline through path distributions, so this is an offline distribution/autoload proof, not a claim of a new remote install or minimum-version compatibility matrix.

## Verification

Final commands run serially from the tenancy worktree. Package runtime commands use SQLite in memory, array cache, sync queue, testing environment, one test process; no external SQL/Redis/S3 service was used or stopped.

| Gate | Exact command / scope | Result |
| --- | --- | --- |
| Changed neutral Filterable proof | `php vendor/bin/pest --test-directory=packages/nvl/filterable/tests --configuration=packages/nvl/filterable/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/filterable/tests/Feature/PredicatePreservationTest.php` | 6 tests / 13 assertions, 123 ms |
| Changed neutral Data projection | `php vendor/bin/pest --test-directory=packages/nvl/data/tests --configuration=packages/nvl/data/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/data/tests/Feature/OwnershipInputProjectionTest.php` | 4 tests / 4 assertions, 85 ms |
| Full Filterable/Data quality | `php tools/run-package-quality.php tenancy filterable data --continue-on-error` | Filterable Pint + max PHPStan 0 errors + 99 tests / 218 assertions; Data Pint + max PHPStan 0 errors + 60 tests / 349 assertions. Initial Tenancy analysis failed; see diagnosis below. |
| Named boundary characterization after narrow static correction | `php vendor/bin/pest --test-directory=packages/nvl/tenancy/tests --configuration=packages/nvl/tenancy/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/tenancy/tests/Feature/TenantBoundaryTest.php` | 24 tests / 63 assertions, 515 ms |
| Final canonical Tenancy quality | `php tools/run-package-quality.php tenancy --continue-on-error` with the same runtime environment, outside the sandbox | Pint passed; max PHPStan 0 errors; 309 tests / 1,162 assertions, 8,323 ms test time, 10,190 ms whole gate |
| Changed Suite catalog static | `vendor/bin/phpstan analyse --no-progress --error-format=table --memory-limit=3G src/Support/SuiteModuleCatalog.php` outside the sandbox | 0 errors |
| Affected root contracts and Suite diagnostics/skills | `php vendor/bin/pest --compact tests/Contract/ConsumerReadinessTest.php tests/Contract/PackageArchiveToolsTest.php tests/Contract/PackageMigrationQualityTest.php tests/Contract/PackageQualityWorkflowTest.php tests/Contract/TenancyConsumerWorkflowTest.php tests/Feature/SuiteConfigurationWriterTest.php tests/Feature/SuiteSkillsTest.php` | 100 tests / 9,168 assertions, 11,119 ms |
| Final archive consumer with added sole-loader/namespace assertions | `php vendor/bin/pest --compact tests/Contract/TenancyConsumerWorkflowTest.php` | 3 tests / 65 assertions, 5,126 ms; minimal and Filterable profiles passed |
| Composer | `composer validate --no-check-publish`; `composer validate --working-dir=packages/nvl/tenancy --no-check-publish`; `composer autoload:check` | Both manifests valid; strict optimized autoload passes, 16,004 classes |
| Dependency declarations | `composer dependencies:check` | All 21 package audits passed; all assigned shadow dependencies and unknown test classes resolved |
| Distribution/contracts | `composer packages:validate`; `composer contracts:check` | 21 distributions valid; all 21 PHP public signature inventories unchanged |
| Types | `php artisan nvl:data:types:generate --no-interaction`; `composer types:check` | Source registration generates the four existing Tenancy enum unions; PHP freshness and `tsc --noEmit` pass |
| Skills | `composer skills:sync`; SuiteSkillsTest; family validation | 21 mirrors synchronized; structural/publication/mirror checks pass |
| Style | `vendor/bin/pint --dirty --format agent`; `git diff --check` | Pass |

The optional skill-creator Python quick validator could not import PyYAML in either system or bundled Python; no external installation was attempted. The existing Suite skill tests/family gate passed, and a separate Symfony YAML parse verified the unchanged frontmatter's allowed keys, exact name, description type/length, and absence of unfinished placeholders. This small guidance extension was not dispatched to a pressure-test agent because the explicit F8 handoff prohibits subagent dispatch.

### Release metadata corrections and additional RED evidence

- Initial affected root run: 76/78 tests passed, 8,958 assertions. Existing PackageArchiveToolsTest found Settings catalog dependencies omitted `tenancy` even though the reviewed U1 manifest/family metadata required it. The second failure came from the distribution validator findings below. The corrected full root run passed 100/100.
- Initial family validation: four expected packaging findings — root runtime requirements lacked ext-pdo, Tenancy Symfony constraints were narrower than the existing Suite constraints, and the validator only accepted a Suite installation row. Root promoted its already-used ext-pdo to runtime, Tenancy direct Symfony constraints now match the existing `^7.2 || ^8.0` range (combined with Laravel 13's own constraints), and the validator explicitly accepts the standalone Tenancy row while retaining exact Suite installation rows for every other module.
- Root approved the two-line Boundary static correction, Settings catalog synchronization, owning validator edit, and root PDO promotion. No new dependency package was installed into the repository.
- `COMPOSER_DISABLE_NETWORK=1 composer update --lock --no-install --no-scripts --no-audit --no-interaction` exited 100 because Packagist metadata was unavailable with network disabled. Composer's own `Composer\Package\Locker::getContentHash()` then refreshed the hash; the ext-pdo key moved from `platform-dev` to `platform`. Final diff is limited to these approved lock fields. Parsed `packages` and `packages-dev` records compare identically to HEAD; no selected version changed.

### Static-analysis diagnosis, without hiding failures

1. Initial wrapper: Tenancy formatter and all 309 tests passed, analysis failed silently; Filterable/Data full quality passed. This invocation remains a failure.
2. Exact command obtained from the actual `PackageQualityRunner::commandsFor('tenancy')`, freshly generated config, unchanged cwd/environment/options: exit 1, empty stdout/stderr. A normal direct shell replay also failed silently.
3. `--debug` was used only diagnostically. It exposed two pre-existing `argument.type` findings at TenantBoundary's Builder<T>::qualifyColumn calls for canonical infrastructure columns `tenant_id` and `ownership_key`.
4. With controller approval, the boundary qualifies these strings through the canonical model. The framework Builder delegates string qualification to that same model; generated SQL is unchanged. Named boundary tests passed, then debug analysis passed 0 errors. No casts, ignores, baselines or runner configuration changes were introduced.
5. Canonical process after the correction still returned exit 1, `signaled=false`, `signal=0`, empty stdout/stderr. Raw formatter and verbose diagnostics were also silent. A pre-correction replay after debug had exposed the same two actual findings with exit 1 / no signal / empty stderr; these are preserved separately in evidence.
6. The exact canonical generated subprocess was replayed outside the sandbox, with identical argv/config/environment: exit 0, `signaled=false`, `signal=0`, stdout `{"tool":"phpstan","result":"passed","errors":0}`, stderr empty. This isolates a sandbox-sensitive execution limitation; local worker IPC is a plausible mechanism, not a proven root cause.
7. Final full canonical Tenancy wrapper outside the sandbox passed all steps. Changed root catalog analysis also passed outside the sandbox. No speculative quality-runner rewrite was made, and earlier failed invocations are not reported as passes.

The canonical analysis argv was:

```text
<worktree>/vendor/bin/phpstan analyse
--configuration=<worktree>/storage/framework/cache/package-quality/tenancy/phpstan.neon
--no-progress --error-format=table --memory-limit=3G
<worktree>/packages/nvl/tenancy/src
<worktree>/packages/nvl/tenancy/tests/Fixtures
```

`task-8-evidence.log` preserves the first package wrapper, debug findings, exact process diagnostics, final canonical wrapper, initial/final root tests, final dependency output, and type generation output.


## Files changed

- Minimal runtime correction: Tenancy provider source registration; equivalent canonical model column qualification in TenantBoundary.
- Tenancy package manifest; root manifest and lock platform metadata; SuiteModuleCatalog; package-family metadata and explicit standalone README validator.
- New package TenantConsumerContractTest and root TenancyConsumerWorkflowTest with isolated archive helper/process fixture; stronger host binding identity assertion; three PSR-4 queue fixtures moved from TenantQueueContextTest.
- Existing neutral Filterable predicate and Data ownership projection tests extended without production imports or reverse dependencies.
- Root PackageArchiveToolsTest wording updated to reflect the Suite artifact alongside the standalone foundation.
- Tenancy README/CHANGELOG/UPGRADING/canonical skill; root README/CHANGELOG/UPGRADING; consumer-readiness/adoption docs and canonical skill mirror.
- Generated TypeScript entrypoint, four-enum tenancy declaration, integrity manifest and transformer manifest.
- This report and raw evidence log. `tools/package-contracts.json` was checked and intentionally left unchanged: no PHP public signature changed, so inventing inventory churn would be misleading.

## Self-review

- Re-read all scoped diffs and the F8 acceptance criteria. The consumer Composer resolver admits only the declared selected NVL package closure; it cannot consult Packagist or load the Suite loader. Its root process asserts a single local Composer loader, an exact allowed NVL prefix set, archived class paths, and absent Auth/Suite/minimal Filterable classes. Archive extraction rejects tests/vendor and checks required runtime/docs/license/skill contents. Temporary artifacts are removed in `finally`.
- Actual discovered provider, config cache, Doctor, empty disabled schema, Data projection, colliding-key OR queries and relation queries are behavior assertions, not manifest-only approximations.
- U2 neutral fixtures are copied explicitly as host proof input and no runtime dependency direction changes were made to Support/Data/Primitives/Filterable.
- Host binding identity compares the original concrete closure/binding record after registration and boot without resolving the throwing fixture.
- The existing inspector validates a valid open resources map and identifies an unknown config key. Existing public entry points are checked against the unchanged reflected inventory and documented with private infrastructure boundaries.
- The standalone installation example requires no Auth. Domain tenancy adoption is explicitly still future work. Core/released migrations were not edited.
- All selected dependency package records/versions are unchanged. Generated type changes are limited to new Tenancy discovery and generated timestamps/hashes; no unrelated declaration content changed.
- Final style and diff checks pass. No functional concern remains.

## Limitations and handoff

- The archive rehearsal uses existing installed external versions as offline path distributions; it proves Composer resolution/autoload/discovery/cache/runtime at the current baseline, not a fresh remote/minimum-version compatibility matrix.
- Final canonical analysis required sandbox escalation. The exact restriction mechanism is not established, and the original failed sandbox results remain in evidence.
- This task adds no PostgreSQL/MySQL/MariaDB or Redis/S3 infrastructure run. Ordinary SQLite/disabled archive proof required none; existing foundation database-engine evidence is not restated as freshly executed.
- F8 completes the foundation prerequisite. It does not mark later Settings/Auth/domain adopters shipped.
