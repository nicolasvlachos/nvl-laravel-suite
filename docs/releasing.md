# Publishing NVL Laravel Suite

This is the canonical maintainer guide for pushing changes and publishing a
stable `nvl/laravel-suite` release. The short instructions in the root README
and contributing guide link here so maintainers, automation, and coding agents
can discover one release procedure.

The executable contracts are:

- `.github/workflows/package-quality.yml` for pull-request and `main` checks.
- `.github/workflows/package-release.yml` for immutable version tags and GitHub
  Releases.
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
composer quality
composer audit --locked --no-interaction
```

Review intentional public-contract changes before running
`composer contracts:update`. A baseline update is part of the reviewed release
change, never an automatic way to silence a failure.

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

Pushing `main` starts **Package quality**. It must finish with these five green
jobs:

1. Formatting, analysis, manifests and contracts.
2. PHP 8.4 / Laravel 13 / SQLite.
3. PHP 8.3 / Laravel 12 / lowest.
4. PostgreSQL stateful packages.
5. Changed-package coverage.

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
2. Reruns all five routine quality gates.
3. Builds exactly one versioned Composer ZIP.
4. Rejects development-only paths and unexpected archive contents.
5. Installs that exact ZIP into a clean Laravel 13 application.
6. Verifies discovery, configuration and route caches, migrations, publish
   tags, strict doctor commands, and the Composer security audit.
7. Creates and pushes the annotated clean `vX.Y.Z` tag.
8. Creates the GitHub Release and attaches the verified ZIP.

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
