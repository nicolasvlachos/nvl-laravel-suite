# NVL Laravel Suite

[![Packagist](https://img.shields.io/packagist/v/nvl/laravel-suite)](https://packagist.org/packages/nvl/laravel-suite)
[![Downloads](https://img.shields.io/packagist/dt/nvl/laravel-suite)](https://packagist.org/packages/nvl/laravel-suite)
[![Package quality](https://github.com/nicolasvlachos/nvl-laravel-suite/actions/workflows/package-quality.yml/badge.svg?branch=main)](https://github.com/nicolasvlachos/nvl-laravel-suite/actions/workflows/package-quality.yml)
[![License](https://img.shields.io/packagist/l/nvl/laravel-suite)](LICENSE)

The NVL Laravel Suite is one installable Composer package containing 20 focused Laravel modules and an integration workbench. The modules remain isolated under `packages/nvl`, retain their namespaces, providers, migrations, tests, documentation, and Laravel Boost skills, and ship together under one version.

## Packages and API documentation

Each module has one canonical API and usage page. These pages cover installation,
configuration, primary PHP entry points, optional HTTP surfaces, commands,
extension contracts, operational behavior, and verification where applicable.

| Module identifier | Responsibility | API and usage |
|---|---|---|
| `nvl/activity` | Activity capture and merged model timelines | [Documentation](packages/nvl/activity/README.md) |
| `nvl/auth` | Headless authentication, invitations, authenticators, recovery, sessions, RBAC, and security audit | [Documentation](packages/nvl/auth/README.md) |
| `nvl/comments` | Polymorphic threads, replies, reactions, moderation, reports, and attachments | [Documentation](packages/nvl/comments/README.md) |
| `nvl/content` | Schema-driven translatable content blocks, placements, Media/reference fields, and rendering | [Documentation](packages/nvl/content/README.md) |
| `nvl/csv` | Typed CSV analysis, validation, transformation, import, export, streaming, and queued chunk processing | [Documentation](packages/nvl/csv/README.md) |
| `nvl/data` | Shared Spatie Data and TypeScript transformation support | [Documentation](packages/nvl/data/README.md) |
| `nvl/filterable` | Validated Eloquent filtering and sorting | [Documentation](packages/nvl/filterable/README.md) |
| `nvl/forms` | Form CRUD, public rendering/submission, security, analytics, and localized content | [Documentation](packages/nvl/forms/README.md) |
| `nvl/mail-notifications` | Provider-neutral mail tracking, optional scheduling, MailerSend integration, privacy, and safe interception | [Documentation](packages/nvl/mail-notifications/README.md) |
| `nvl/media` | Uploads, storage, associations, variations, delivery, and localized metadata | [Documentation](packages/nvl/media/README.md) |
| `nvl/metafields` | Typed polymorphic custom fields and localized definition/value data | [Documentation](packages/nvl/metafields/README.md) |
| `nvl/pages` | Localized hierarchical pages, dynamic resources, Content composition, SEO, and sitemaps | [Documentation](packages/nvl/pages/README.md) |
| `nvl/primitives` | Immutable value objects, exact money, validation, and ISO/reference catalogs | [Documentation](packages/nvl/primitives/README.md) |
| `nvl/seo` | Localized metadata, canonical/social/structured output, robots, and sitemaps | [Documentation](packages/nvl/seo/README.md) |
| `nvl/settings` | Typed database-backed application-wide settings | [Documentation](packages/nvl/settings/README.md) |
| `nvl/support` | Transport-neutral business exceptions and stable response codes | [Documentation](packages/nvl/support/README.md) |
| `nvl/taxonomy` | Hierarchical attachable vocabularies and localized terms | [Documentation](packages/nvl/taxonomy/README.md) |
| `nvl/templates` | Versioned Content compositions, validated payloads, PDF/HTML rendering, assignments, and queues | [Documentation](packages/nvl/templates/README.md) |
| `nvl/translatable` | Shared locale validation, request-scoped content locale, fallback, queries, and writes | [Documentation](packages/nvl/translatable/README.md) |
| `nvl/translations` | File/database UI-string translation workflows | [Documentation](packages/nvl/translations/README.md) |

See the [package capability catalog](packages.md) for a concise comparison of
all modules. The linked module pages are the source of truth for callable APIs
and integration guidance.

The suite is auto-discoverable and supports Laravel 12 or 13 on PHP 8.3+. Module source and configuration must not reference a host `App\`/`Modules\` class or named host middleware. Internal dependency boundaries remain explicit and are validated in CI, but Composer installs and versions only `nvl/laravel-suite`.

## Staged module adoption

The auto-discovered suite provider can register a safe subset of modules. Publish
the module-selection configuration, disable modules that are not ready for the
host schema, and clear the configuration cache:

```bash
php artisan vendor:publish --tag=suite-config
php artisan config:clear
```

Configure `config/nvl-suite.php` before running migrations. Enabling a module
automatically registers its transitive NVL dependencies in canonical order; it
does not enable unrelated modules. For example, enabling only `auth` registers
`data` followed by `auth`.

When package discovery itself must be disabled, register
`Nvl\Data\Providers\DataServiceProvider` before
`Nvl\Auth\Providers\AuthServiceProvider`. Auth also registers its Data
foundation defensively, so direct Auth-only registration remains supported.

## Composer installation

Install a stable suite release from [Packagist](https://packagist.org/packages/nvl/laravel-suite):

```bash
composer require nvl/laravel-suite:^1.0
```

Composer installs the clean distribution archive for the selected tag by
default. A normal installation does not clone the development repository.

When intentionally testing the development branch, require the Packagist
development version explicitly:

```bash
composer require nvl/laravel-suite:dev-main
```

No custom Composer repository entry is required.

One `vX.Y.Z` tag versions every internal module and produces one release archive.

### Dependency-major preflight

Suite v1 installs `spatie/typescript-transformer` and
`spatie/laravel-typescript-transformer` 3.3. Consumers upgrading from v2 must
remove `RecordTypeScriptType` and use
`LiteralTypeScriptType('Record<string, unknown>')` (or a narrower record shape).
Version 3 also removed v2 writer APIs such as `SplitWriter`; NVL Data owns split
declaration output through its configured writer. Run static analysis and both
`nvl:data:types:generate` and `nvl:data:types:check` immediately after Composer
changes. Do not rely on packages that happened to be installed transitively by
the previous transformer version; declare every directly used package.

## Repository and distribution contents

The `main` branch is the maintainable source repository. It intentionally keeps
the integration workbench, tests, fixtures, static-analysis configuration,
lockfiles, and GitHub Actions needed to verify releases. Repository-local AI,
MCP, and editor configuration is ignored and is not part of the tracked source.

Stable version tags are built from the verified Composer archive. Packagist
consumers receive only the root manifest, license, changelogs and documentation,
the suite provider, and runtime contents under `packages/nvl`; development
workbench configuration, tests, fixtures, and repository tooling are not
included.

## Translation architecture

`nvl/translatable` is the single runtime for model-backed content:

- Metafield definitions and eligible owner values use dedicated translation rows.
- Media title, alternative text, and caption use dedicated translation rows.
- Taxonomy name and description use `terms_i18n`.
- Form name, description, submit/success copy, and arbitrary nested content use `forms_i18n`.
- Schema-driven Content block fields use `content_blocks_i18n`.
- Laravel language files remain responsible for interface strings and validation messages.

The central `TranslationResourceRegistry` gathers Forms, Media, Metafields, SEO, Taxonomy, and application resources from one place. Use `php artisan nvl:translatable:gather --json` for catalog/coverage output.

Read [the translation architecture and rollout guide](docs/translation-architecture.md) before adding another translated model or adopting an existing localized schema.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

The root `composer.json` is both the published package manifest and the local workbench manifest. The `Nvl\Workbench` namespace keeps local application fixtures separate from consumer `App\` namespaces.

Laravel Herd serves this workspace automatically; do not start a separate PHP development server.

## Development rules

- Keep changes within the owning module and follow its established architecture and naming conventions.
- Keep Controllers as HTTP adapters, Actions as transaction owners, Services as focused collaborators, Models lean, and DTOs explicit.
- Add a migration instead of editing a migration that may already be deployed.
- Add or update Pest coverage for every behavior change.
- CI holds each package at its measured coverage baseline, requires 90% line
  coverage for newly changed source lines on pull requests and branch pushes,
  and ratchets package baselines upward as tests improve.
- Keep module boundaries and external dependencies declared directly and bounded.
- Keep the v1 API free of pre-release aliases; document breaking upgrades explicitly.
- Use package Actions and Services from consumers; do not reach into persistence internals.

## Tests and formatting

Run one focused package:

```bash
vendor/bin/pest \
    --test-directory=packages/nvl/forms/tests \
    --configuration=packages/nvl/forms/phpunit.xml.dist \
    --bootstrap=vendor/autoload.php \
    --compact \
    packages/nvl/forms/tests
```

Run the full monorepo with enough memory for Media binary fixtures:

```bash
composer quality
```

The root quality gate checks formatting, Larastan/PHPStan at maximum strictness,
declared module and extension dependencies, suite architecture/distribution
rules, the frozen public contracts, and the complete Pest suite with a
1 GB memory limit. The root integration suite is an executable reference
consumer: one application model composes Activity, Comments, Content, Media,
Metafields, SEO, and Taxonomy and exercises the shared registries, strict
doctors, and a constant eager-loading query budget. Module-specific test suites
remain independently runnable through their `phpunit.xml.dist` files.

Public and protected extension contracts, command signatures, provider
discovery, publish tags, autoload files, configuration, routes, and migrations
are recorded in `tools/package-contracts.json`. Compatibility checks fail when
that surface moves unexpectedly:

```bash
composer contracts:check
composer contracts:update
```

Only run `contracts:update` after reviewing the semantic-version impact of an
intentional contract change.

During development, format only changed PHP or validate one manifest with:

```bash
vendor/bin/pint --dirty --format agent
composer validate --strict packages/nvl/forms/composer.json
```

## Agent skills

Each module provider exposes its skill directory through a stable `*-skills` publish tag. Modules use the Laravel Boost layout:

```text
resources/boost/skills/<skill-name>/
├── SKILL.md
└── agents/openai.yaml
```

Publish a module skill into a consumer application:

```bash
php artisan vendor:publish --tag=forms-skills
```

Available package skill tags are:

- `activity-skills`
- `auth-skills`
- `comments-skills`
- `content-skills`
- `csv-skills`
- `data-skills`
- `filterable-skills`
- `forms-skills`
- `mail-notifications-skills`
- `media-skills`
- `metafields-skills`
- `pages-skills`
- `primitives-skills`
- `seo-skills`
- `settings-skills`
- `support-skills`
- `taxonomy-skills`
- `templates-skills`
- `translatable-skills`
- `translations-skills`

Skill sources must describe current package code, validate with the Codex skill validator, and avoid historical namespaces or proposed architectures.

Every package uses this one layout. Historical top-level skill directories and
future-proposal guidance are rejected by the family validator.

## Generated TypeScript contracts

Modules that expose DTO or enum contracts register their source paths with the Data module; infrastructure-only modules do not acquire a data dependency just for discovery. Applications may add their own paths in `config/nvl-data.php`. Generate declarations during build:

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
php artisan nvl:data:types:manifest
```

The opt-in generated-type surface serves only manifest-listed, pre-generated
artifacts and a bounded streamed archive. It is disabled by default, protected
by configured middleware when enabled, and never generates during a request.

## Release workflow

The canonical [push, automated tagging, and release guide](docs/releasing.md)
covers preparation, explicit staging, local gates, pushing `main`, dispatching
the release workflow, Packagist synchronization, clean-consumer verification,
and safe retry rules.

The short path is:

```bash
composer quality
composer validate --strict
composer audit --locked --no-interaction
git add <reviewed-paths>
git diff --cached
git commit -m "release: prepare v1.1.0"
git push origin main

gh workflow run package-release.yml --ref main -f version=1.1.0
```

Wait for the five `Package quality` jobs to pass before dispatching `Package
release`. Supply `1.1.0`, not `v1.1.0`. Never create or push a version tag
manually: the workflow builds and installs the clean archive, creates the
annotated `v1.1.0` tag, publishes the GitHub Release, and lets Packagist discover
the stable version.

Do not publish `dev-main` as a stable dependency. Consumers should use the `^1.0` line.
Published NVL migrations retain their package timestamps so they have the same
identity as auto-loaded migrations and cannot execute twice.

## License

The suite is released under the [MIT License](LICENSE).

See the project-wide [changelog](CHANGELOG.md), [contributing guide](CONTRIBUTING.md),
and [security policy](SECURITY.md) for maintenance and disclosure guidance.
