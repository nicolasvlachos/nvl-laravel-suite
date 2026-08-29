# Publishing NVL Laravel Suite

This is the canonical maintainer guide for pushing changes and publishing a
stable `nvl/laravel-suite` release. The short instructions in the root README
and contributing guide link here so maintainers, automation, and coding agents
can discover one release procedure.

The executable contracts are:

- `.github/workflows/package-quality.yml` for pull-request and `main` checks.
- `.github/workflows/package-release.yml` for immutable version tags and GitHub
  Releases.
- `tools/check-release-changelogs.php` for the requested suite version and every
  package changed since the preceding stable source.
- `tools/consumer-api-deprecations.php` for the exact final-1.x-to-2.0 behavior
  and return-shape inventory.
- `tools/rehearse-final-1x-upgrade.sh` for the sealed prepared-final-1.x upgrade
  proof.
- `tests/Contract/PackageQualityWorkflowTest.php` for the workflow invariants.

If this guide and a workflow disagree, stop and update them together. Do not
invent a second release path.

## Release model

The repository publishes one Composer package and one shared version for all
modules under `packages/nvl`. Never create separate module tags.

- `main` contains the maintainable source, workbench, tests, fixtures, and
  release tooling.
- A stable `vX.Y.Z` tag contains the verified Composer distribution only.
- Packagist discovers the stable version from that Git tag.
- Consumers install the clean tagged distribution; a normal Composer install
  does not clone the development repository.

The release workflow creates an annotated tag that points to an archive-only
release commit whose parent is the verified `main` commit. Consequently, the
tagged commit SHA is intentionally different from the `main` SHA. Do not move,
replace, force-push, or manually recreate that tag.

## Required maintainer and automation behavior

Treat release preparation, pushing, and publication as separate authorization
boundaries. A commit is not a release, a push is not a version, and only the
successful release workflow may create the stable tag.

- **Prepare and commit:** choose the requested version, move the release notes
  from `Unreleased` into that version with the release date in the root and all
  affected module changelogs, run the local gates, review explicit staged paths,
  and commit. Leave a blank `Unreleased` section for future work. Do not push,
  dispatch a workflow, or create a tag unless the request also authorizes it.
- **Push:** push only the reviewed release-preparation commit to `main`, then
  wait for the corresponding **Package quality** run. Do not represent the push
  as a published release and do not dispatch while any required job is not
  green.
- **Publish, release, or tag:** confirm that the requested version matches the
  changelogs and does not already exist, ensure the release-preparation commit
  is on `main`, wait for its six quality jobs, and dispatch `Package release`
  with that exact version. Never substitute a nearby version or create a local
  tag as a shortcut.
- **Report state precisely:** after preparation, report the commit and that the
  previous stable tag remains current. After publication, report success only
  after the workflow, immutable tag, GitHub Release, archive, and Packagist
  version have been verified.

If a request is ambiguous about pushing or publishing, stop before the external
write and ask for that authorization. Never silently leave a named release under
`Unreleased`, claim that a commit changed the tag, or mutate an existing stable
tag.

## 1. Prepare the release change

Start from `main` with a clean worktree. If `git status` shows unrelated work,
commit it separately or finish it before preparing a release.

```bash
git switch main
git pull --ff-only origin main
git status --short --branch
```

Choose a semantic version without a `v` prefix, for example `1.1.0`. Follow
semantic versioning across the complete suite:

- Patch: compatible fixes, such as `1.0.1`.
- Minor: backward-compatible features, such as `1.1.0`.
- Major: breaking public API or contract changes, such as `2.0.0`.

Update `CHANGELOG.md`, affected module changelogs, upgrade notes, public API
documentation, and examples in the same change. Do not add a `version` field to
`composer.json`; Composer derives it from the Git tag.

## 2. Run the local release gates

Install the locked development dependencies and run the same release checks
before pushing:

```bash
composer install --no-interaction --prefer-dist
composer validate --strict
composer contracts:check
composer packages:validate
composer quality
composer audit --locked --no-interaction
```

For suite `1.0.2` and later, release/consumer CI may make the existing
warning-free TypeScript contract explicit with:

```bash
php artisan nvl:data:types:generate --fail-on-warning
php artisan nvl:data:types:check --fail-on-warning
```

Do not pass `--fail-on-warning` when rehearsing the published `1.0.1`
artifact; that version enforces transformer warnings internally but does not
expose the option in its Artisan signature. Always align release commands with
the exact artifact version under test.

Review intentional public-contract changes before running
`composer contracts:update`. A baseline update is part of the reviewed release
change, never an automatic way to silence a failure.

For the 2.0 boundary, `v1.0.7` is the published final 1.x tag. It did not publish the 2.0 deprecation warnings or all additive proof APIs needed for a
complete in-place rehearsal. Commit
`d8feceecc02f436772dca74b260704a535bceca6` is the immutable prepared-final-1.x
source checkpoint immediately before the breaking changes. Run:

```bash
tools/rehearse-final-1x-upgrade.sh
```

This builds both checkpoints as sealed Composer archives, installs the prepared
checkpoint into a Laravel 13 Auth proof consumer, renders all module decisions,
caches configuration and routes, migrates, generates and compiles TypeScript,
runs strict Doctor/audit and fixture smoke, upgrades the same application to
the candidate, and repeats those checks. This prepared evidence is not a published 1.x release and must never be described as proof that final-1.x
warnings reached external consumers.

Optionally rehearse the Composer archive with the chosen version:

```bash
COMPOSER_ROOT_VERSION=1.1.0 composer archive \
    --format=zip \
    --dir=/tmp/nvl-suite-archive
```

The authoritative release workflow rebuilds and verifies its own archive, so a
local archive must never be tagged or uploaded manually.

## 3. Review, commit, and push

Inspect both the worktree and the exact staged patch. Stage explicit paths so a
secret, generated artifact, local agent file, or unrelated change cannot enter
the release commit accidentally.

```bash
git status --short
git diff --check
git diff --stat

git add CHANGELOG.md README.md CONTRIBUTING.md docs packages/nvl

git diff --cached --check
git diff --cached
git commit -m "release: prepare v1.1.0"
git push origin main
```

Adjust the `git add` paths to the files actually changed. Do not blindly use
`git add -A`. Confirm that `.env`, `auth.json`, credentials, database dumps,
private keys, `.temp`, local agent configuration, `vendor`, and `node_modules`
are not staged.

Pushing `main` starts **Package quality**. It must finish with these six green
jobs:

1. Formatting, analysis, manifests and contracts.
2. PHP 8.4 / Laravel 13 / SQLite.
3. PHP 8.4 / Laravel 12 / lowest.
4. PostgreSQL stateful packages.
5. MySQL 8.4 and MariaDB 12.3 stateful packages.
6. Changed-package coverage.

Use the GitHub Actions page, or GitHub CLI:

```bash
gh run list --workflow package-quality.yml --branch main --limit 5
gh run watch RUN_ID --exit-status
```

Do not publish while the pushed `main` run is queued, running, cancelled, or
failing.

## 4. Create the automated version tag

Never run `git tag vX.Y.Z` or `git push origin vX.Y.Z` yourself. The release
workflow is the only supported tag publisher.

From GitHub, open **Actions -> Package release -> Run workflow**, select `main`,
and enter the version without the `v` prefix, such as `1.1.0`.

The equivalent GitHub CLI commands are:

```bash
gh workflow run package-release.yml --ref main -f version=1.1.0
gh run list --workflow package-release.yml --branch main --limit 5
gh run watch RUN_ID --exit-status
```

The workflow performs the complete publication transaction:

1. Validates the default branch and semantic version.
2. Reruns all six routine quality gates and a separate PHP 8.5 / Laravel 13
   test job.
3. Requires a dated version heading in the suite and every changed module,
   rejects future-target wording, and requires release-ready `Unreleased`
   sections to be blank.
4. Builds exactly one versioned Composer ZIP.
5. Rejects development-only paths and unexpected archive contents.
6. Installs that exact ZIP into a clean Laravel 13 application.
7. Verifies discovery, configuration and route caches, migrations, publish
   tags, strict doctor commands, and the Composer security audit.
8. Upgrades the prepared final-1.x Auth proof consumer to that same sealed ZIP
   and verifies explicit configuration, caches, migrations, generated types,
   strict Doctor/audit, queue-backed smoke behavior, and TypeScript compilation.
9. Creates and pushes the annotated clean `vX.Y.Z` tag.
10. Creates the GitHub Release and attaches the verified ZIP.

No tag is created when validation, quality, or archive verification fails.

## 5. Verify GitHub and Packagist

After the release workflow is green, synchronize the tag locally:

```bash
git fetch --tags --prune
git tag -n1 --list 'v1.1.0'
git ls-remote origin 'refs/tags/v1.1.0' 'refs/tags/v1.1.0^{}'
```

Verify all of the following before announcing the release:

- The GitHub Actions release run is green.
- `https://github.com/nicolasvlachos/nvl-laravel-suite/releases/tag/v1.1.0`
  exists, is not a draft or prerelease, and contains one
  `nvl-laravel-suite-1.1.0.zip` asset.
- [Packagist](https://packagist.org/packages/nvl/laravel-suite) lists `v1.1.0`
  as a stable version.

Packagist is configured to update automatically from GitHub. A new changelog
entry or pushed `main` commit is not a version: Packagist will continue to show
only `dev-main` until the release workflow publishes the Git tag. If the tag and
GitHub Release exist but Packagist has not updated after a short delay, use the
Packagist package page's **Update** action and inspect its update log.

Finally, install the public Packagist version into a fresh application:

```bash
composer create-project --no-interaction --no-dev \
    --prefer-dist laravel/laravel:^13.0 /tmp/nvl-suite-consumer
cd /tmp/nvl-suite-consumer
composer require --no-interaction --update-no-dev \
    --with-all-dependencies nvl/laravel-suite:^1.1
php artisan package:discover
php artisan config:cache
php artisan route:cache
php artisan migrate --force
```

That smoke test uses the default automatic vendor migration owner. Do not then
publish the `*-migrations` tags into that application: Laravel retimestamps
published migrations and would create a second migration identity. To test
host-owned migrations, use a fresh database, publish before the first migrate,
and set every relevant `<package>.migrations.enabled` value to `false`.

The automated archive gate exercises both ownership modes on fresh SQLite
databases. It also publishes the suite-level and package-level tags recorded in
`tools/package-contracts.json` and verifies materialized configuration,
migrations, translations, views, adoption templates, generated-type tooling,
and all 20 `.agents/skills/nvl-*` directories. The archive independently ships
the same 20 skills under `resources/boost/skills` so a consumer with Laravel
Boost may install them through `php artisan boost:install --skills` without
first publishing them.

Use the matching constraint for the release line, such as `^1.0` for `v1.0.1`
or `^2.0` for `v2.0.0`.

## Failure and retry rules

- If a routine quality job fails, fix it on `main`, commit, push, and wait for a
  new green run.
- If the release workflow fails before publishing, fix `main` and dispatch the
  same version again after the new quality run passes.
- If the tag was created but GitHub Release publication failed, rerun the same
  version. The workflow accepts the existing tag only when its archive tree and
  verified parent commit match exactly.
- If a published tag is wrong, do not mutate it. Diagnose the process and ship a
  new patch version.
- Never release directly from a feature branch, a dirty worktree, `dev-main`, or
  a locally assembled ZIP.
