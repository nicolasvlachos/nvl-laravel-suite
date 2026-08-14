# Contributing to NVL Laravel Suite

Thank you for improving the suite. This repository publishes one Composer
package, `nvl/laravel-suite`; directories under `packages/nvl` are embedded
modules, not independently released packages.

## Development setup

Requirements are PHP 8.4 or newer, Composer 2, and the PHP extensions declared
by the root `composer.json`.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Keep changes inside the module that owns the behavior. Shared behavior must use
an explicit contract, registry, event, or service-provider dependency instead
of reaching into another module's implementation.

## Quality requirements

- Add or update Pest coverage for every behavior change.
- Do not edit a migration that may have shipped; add a new migration.
- Keep configuration cache-safe and call `env()` only from configuration files.
- Keep package source free of consumer `App\\` and `Modules\\` namespaces.
- Update the relevant README, module changelog, and upgrade guide when public
  behavior changes.
- Treat the public-contract baseline as a semantic-versioning gate, not a file
  to regenerate automatically.

Run the complete release gate before opening a pull request:

```bash
composer quality
composer validate --strict
composer audit --locked --no-interaction
```

Pull requests should explain the owning module, user-visible impact, migration
or upgrade implications, and verification performed. Report vulnerabilities
through the private process in [SECURITY.md](SECURITY.md), never through a
public issue.

## Maintainer releases

Maintainers must follow the canonical [push and automated release
guide](docs/releasing.md). Push the reviewed release-preparation commit to
`main`, wait for all five routine quality jobs, and dispatch `Package release`
with a semantic version that does not include the `v` prefix. Do not create,
move, or push version tags manually.
