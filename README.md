# NVL Laravel Suite

The NVL Laravel Suite is a monorepo for developing and verifying an independently installable Laravel package family against one integration workbench. Composer path repositories symlink every package from `packages/nvl`, while each package retains its own manifest, provider, migrations, tests, documentation, and optional Laravel Boost skill.

## Packages

| Package | Responsibility |
|---|---|
| `nvl/activity` | Activity capture and merged model timelines |
| `nvl/auth` | Headless authentication, invitations, authenticators, recovery, sessions, RBAC, and security audit |
| `nvl/comments` | Polymorphic threads, replies, reactions, moderation, reports, and attachments |
| `nvl/content` | Schema-driven translatable content blocks, placements, Media/reference fields, and rendering |
| `nvl/csv` | Typed CSV analysis, validation, transformation, import, export, streaming, and queued chunk processing |
| `nvl/data` | Shared Spatie Data and TypeScript transformation support |
| `nvl/filterable` | Validated Eloquent filtering and sorting |
| `nvl/forms` | Form CRUD, public rendering/submission, security, analytics, and localized content |
| `nvl/mail-notifications` | Provider-neutral mail tracking, optional scheduling, MailerSend integration, privacy, and safe interception |
| `nvl/media` | Uploads, storage, associations, variations, delivery, and localized metadata |
| `nvl/metafields` | Typed polymorphic custom fields and localized definition/value data |
| `nvl/pages` | Localized hierarchical pages, dynamic resources, Content composition, SEO, and sitemaps |
| `nvl/primitives` | Immutable value objects, exact money, validation, and ISO/reference catalogs |
| `nvl/seo` | Localized metadata, canonical/social/structured output, robots, and sitemaps |
| `nvl/settings` | Typed database-backed application-wide settings |
| `nvl/support` | Transport-neutral business exceptions and stable response codes |
| `nvl/taxonomy` | Hierarchical attachable vocabularies and localized terms |
| `nvl/templates` | Versioned Content compositions, validated payloads, PDF/HTML rendering, assignments, and queues |
| `nvl/translatable` | Shared locale validation, request-scoped content locale, fallback, queries, and writes |
| `nvl/translations` | File/database UI-string translation workflows |

Package-specific installation and public APIs are documented in each package README.

Each package is independently publishable, auto-discoverable, and supports Laravel 12 or 13 on PHP 8.3+. Package source/config must not reference a host `App\`/`Modules\` class or named host middleware. Packages that own translated Eloquent content declare `nvl/translatable:^1.0`; all other cross-package requirements are explicit and bounded.

## Composer installation

Tagged releases are published as individual package archives through the suite's
static Composer repository. Register it once in a consumer application:

```bash
composer config repositories.nvl composer \
    https://nicolasvlachos.github.io/nvl-laravel-suite/
```

Install only the required packages; Composer resolves their NVL dependencies
from the same repository:

```bash
composer require nvl/auth:^1.0 --with-all-dependencies
composer require nvl/media:^1.0 --with-all-dependencies
```

The monorepo remains the only source repository. A coordinated `vX.Y.Z` tag
publishes the same version for every package without creating split repositories.

## Translation architecture

`nvl/translatable` is the single runtime for model-backed content:

- Metafield definitions and eligible owner values use dedicated translation rows.
- Media title, alternative text, and caption use dedicated translation rows.
- Taxonomy name and description use `terms_i18n`.
- Form name, description, submit/success copy, and arbitrary nested content use `forms_i18n`.
- Schema-driven Content block fields use `content_blocks_i18n`.
- Laravel language files remain responsible for interface strings and validation messages.

The central `TranslationResourceRegistry` gathers Forms, Media, Metafields, SEO, Taxonomy, and application resources from one place. Use `php artisan nvl:translatable:gather --json` for catalog/coverage output.

Read [the translation architecture and rollout guide](references/translation-architecture.md) before adding another translated model or adopting an existing localized schema.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

The root `composer.json` declares `packages/*/*` as a Composer path repository. Root requirements intentionally allow development versions; individual publishable package manifests use the coordinated `^1.0` dependency line and alias `dev-main` to `1.x-dev`.

Laravel Herd serves this workspace automatically; do not start a separate PHP development server.

## Development rules

- Follow `AGENTS.md` and the package-specific skill for the domain being changed.
- Keep Controllers as HTTP adapters, Actions as transaction owners, Services as focused collaborators, Models lean, and DTOs explicit.
- Add a migration instead of editing a migration that may already be deployed.
- Add or update Pest coverage for every behavior change.
- Keep package dependencies declared directly and bounded.
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
declared package and extension dependencies, family architecture/distribution
rules, the frozen public package contracts, and the complete Pest suite with a
1 GB memory limit. The root integration suite is an executable reference
consumer: one application model composes Activity, Comments, Content, Media,
Metafields, SEO, and Taxonomy and exercises the shared registries, strict
doctors, and a constant eager-loading query budget. Each package exposes the
same isolated gate:

```bash
composer quality --working-dir=packages/nvl/media
```

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

Each provider exposes its packaged skill directory through a stable `*-skills` publish tag. Newer packages use the Laravel Boost layout:

```text
resources/boost/skills/<skill-name>/
├── SKILL.md
└── agents/openai.yaml
```

Publish a package skill into a consumer application:

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

Packages that expose DTO or enum contracts register their source paths with `nvl/data`; infrastructure-only packages do not acquire a data dependency just for discovery. Applications may add their own paths in `config/nvl-data.php`. Generate declarations during build:

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
php artisan nvl:data:types:manifest
```

The opt-in generated-type surface serves only manifest-listed, pre-generated
artifacts and a bounded streamed archive. It is disabled by default, protected
by configured middleware when enabled, and never generates during a request.

## Release workflow

1. Choose a coordinated semantic version and update the changelog/release notes in the release system.
2. Review `composer contracts:check`; acknowledge intentional compatible or breaking changes before updating the baseline.
3. Run migrations and backfills against a representative existing database.
4. Run `composer quality` and the PHP 8.3–8.5, Laravel 12–13, lowest/highest dependency, MySQL, PostgreSQL, coverage, and standalone-consumer CI matrices.
5. Run strict Composer validation and security audit for every package.
6. Build each package archive with an explicit release version:

   ```bash
   COMPOSER_ROOT_VERSION=1.0.0 composer archive \
       --working-dir=packages/nvl/forms \
       --format=zip \
       --dir=/tmp/nvl-package-archives
   ```

7. Run `tools/inspect-package-archive.php` for each archive and
   `tools/build-archive-repository.php` once for the complete archive directory.
8. Let the distribution CI install those exact ZIPs in a clean Laravel 13
   consumer, publish every NVL resource tag, cache configuration/routes, run
   and roll back migrations, execute every strict doctor, and audit the lock.
9. Before tagging, manually run `.github/workflows/release-rehearsal.yml` with
   the prior release ref/version and candidate version. It performs an
   archive-to-archive upgrade on SQLite, MySQL, and PostgreSQL and repeats the
   full post-upgrade validation.
10. Ensure GitHub Pages uses **GitHub Actions** as its publishing source, then
    push one annotated coordinated tag such as `v1.0.0`.
11. The tagged package-quality workflow waits for every quality, compatibility,
    database, coverage, standalone-consumer, and archive job before creating the
    GitHub Release and deploying the merged `packages.json` index to GitHub Pages.
12. Verify the release assets, the Pages deployment, and a clean consumer
    installation before announcing the release.

Do not publish `dev-main` as a stable dependency. Stable packages depend on the `^1.0` line.
Published NVL migrations retain their package timestamps so they have the same
identity as auto-loaded migrations and cannot execute twice.

## License

The monorepo and every distributable package are released under the [MIT License](LICENSE).
