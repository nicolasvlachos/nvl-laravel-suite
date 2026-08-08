# NVL Support — API and usage

[← NVL Laravel Suite](../../../README.md)

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/laravel-suite:^1.0` |
| Module identifier | `nvl/support` |
| PHP namespace | `Nvl\Support` |
| Service provider | `Nvl\Support\Providers\SupportServiceProvider` |
| Configuration | None |

## Purpose

`nvl/support` is the smallest foundation in the NVL package family. It provides transport-neutral business exceptions and stable response-code contracts for Laravel 12–13 on PHP 8.3–8.4.

It deliberately has no internal NVL dependency, configuration, migrations, models, routes, controllers, DTOs, pagination, TypeScript registry, global state, or consumer-domain helper.

## Requirements and installation

```bash
composer require nvl/laravel-suite:^1.0
```

Laravel auto-discovers `Nvl\Support\Providers\SupportServiceProvider`. The provider has no runtime boot side effects. Agent guidance is optional:

```bash
php artisan vendor:publish --tag=support-skills
```

This publishes `.agents/skills/nvl-support`.

## Define a stable response code

Response codes are backed enums implementing `ResponseCode`:

```php
use Nvl\Support\Contracts\ResponseCode;

enum AccountResponseCode: string implements ResponseCode
{
    case Locked = 'account.locked';
}
```

The backed value is the stable machine code. Renaming an enum case does not change the public contract; changing its backed value does.

## Throw a transport-neutral failure

```php
use Nvl\Support\Exceptions\BusinessException;

throw new BusinessException(
    message: 'The account is locked.',
    responseCode: AccountResponseCode::Locked,
    suggestedStatus: 423,
    publicContext: ['retryable' => false],
    diagnosticContext: ['rule' => 'failed-attempt-limit'],
);
```

The exception exposes:

- `responseCode()`: the backed machine code or `null`;
- `suggestedStatus()`: presentation guidance from 100 through 599;
- `publicContext()`: data a consumer adapter may serialize;
- `context()`: internal diagnostic data for logging and reporting;
- the standard previous-exception chain.

`BusinessException` does not render HTTP, JSON, CLI, or queue responses. The consuming application maps it to the appropriate transport and may choose a different presentation status.

## Context safety

Only deliberately safe scalar or structured values belong in `publicContext`. Do not include:

- credentials, tokens, or secrets;
- stack traces or exception objects;
- SQL or database connection information;
- storage paths;
- unredacted personal data;
- arbitrary model serialization.

Diagnostic context is never automatically safe to expose. Applications must keep it on trusted reporting and logging paths.

## Failure behavior

Constructing an exception with a suggested status outside 100–599 throws `SupportException`. A missing response code is valid for failures that have no stable public machine contract. The original exception can be retained through `previous`.

## Non-goals

- HTTP exception rendering
- validation response construction
- DTOs or pagination
- models, persistence, and migrations
- package discovery registries
- consumer-specific error catalogs

Pagination belongs to `nvl/data`. Domain response codes belong to the package or application that owns the behavior.

## Verification

The package tests cover standalone boundaries and discovery, backed response-code enforcement, status boundaries, public and diagnostic context separation, serialization safety, exception chaining, skill publication, and architecture constraints. Release checks run Pest, Pint, PHPStan at maximum strictness, Composer validation, dependency analysis, and distribution validation.

See [UPGRADING.md](UPGRADING.md), [SECURITY.md](SECURITY.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE).
