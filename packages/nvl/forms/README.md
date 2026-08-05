# NVL Forms

## Purpose

`nvl/forms` is a headless form-definition and submission engine for Laravel 12–13 on PHP 8.3–8.5. It owns secure form definitions, localized nested content, public rendering contracts, submissions, stored entries, analytics, and privacy operations. It does not ship an admin UI, frontend scaffold, mail provider, application-specific form types, or a required audit system.

Forms depends on `nvl/data`, `nvl/filterable`, `nvl/support`, and `nvl/translatable`. `nvl/activity` is an optional event-driven integration.

## Requirements and installation

```bash
composer require nvl/forms:^1.0
php artisan migrate
```

Laravel discovers `Nvl\Forms\Providers\FormsServiceProvider`. Clean-install migrations run by default. For an application with existing form tables, set `forms.migrations.enabled` to `false`, run the doctor, and follow [UPGRADING.md](UPGRADING.md) before enabling migrations.

Optional publish tags:

```bash
php artisan vendor:publish --tag=forms-config
php artisan vendor:publish --tag=forms-migrations
php artisan vendor:publish --tag=forms-translations
php artisan vendor:publish --tag=forms-skills
```

The skill is published as `.agents/skills/nvl-forms`.

## First working form

Create form definitions through `CreateFormAction` and `MutateFormPayload`; do not write form or translation tables directly:

```php
use Nvl\Forms\Actions\Form\CreateFormAction;
use Nvl\Forms\Data\Mutations\MutateFormPayload;
use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Enums\Resolvement;

$form = app(CreateFormAction::class)->execute(new MutateFormPayload(
    handle: 'contact',
    translations: [
        'en' => [
            'name' => 'Contact us',
            'submitButtonLabel' => 'Send',
            'content' => [
                'sections' => [
                    ['fields' => [['name' => 'email', 'type' => 'email']]],
                ],
            ],
        ],
    ],
    status: FormStatus::ACTIVE,
    resolvement: Resolvement::ENTRIES,
    type: FormType::IFRAME,
));
```

The DTO validates locale maps and nested content. Form identity, status, availability, security settings, origins, and options remain locale-neutral. Localized names, descriptions, labels, success copy, sections, fields, options, validation messages, and provider extension copy live in `forms_i18n`.

## Mutate safely

Use these Actions as the public write boundary:

- `CreateFormAction`, `UpdateFormAction`, `DuplicateFormAction`, `DeleteFormAction`
- `CreateFormEntryAction`, `MarkFormEntryAsSpamAction`, `MarkFormEntryAsLegitimateAction`
- `RedactFormEntryAction`, `AnonymizeFormEntryAction`, `DeleteFormEntryAction`

Updates require `MutateFormPayload::expectedRevision`. A stale revision is rejected instead of overwriting a concurrent edit. Translation mode defaults to patch; use replace only when omitted locales should be deleted.

After a transaction commits, Forms dispatches the sanitized `FormChangedEvent` and `FormEntryChangedEvent`. The entry event carries the entry identifier rather than serializing submission PII. Subscribe to those events for notifications, activity capture, indexing, or other application behavior.

## Extend definitions and rendering

- Register custom submission behavior with `FormHandlerRegistry` and `CustomFormHandler`.
- Register supplemental render data with `FormRenderDataRegistry` and `FormRenderDataProvider`.
- Register public error mappings with `FormErrorMapperRegistry` and `FormErrorMapper`.
- Register entry callbacks with `EntryCallbackRegistry`.

Registries reject duplicate keys and invalid capabilities. Providers and handlers are container-resolved; the package never imports an application model or module.

Entry callbacks run after the entry transaction. Each callback is isolated and reported independently: a failed integration does not make a persisted submission appear to have failed and does not stop later callbacks.

Forms registers `forms.forms` with `TranslationResourceRegistry`. Central gathering and synchronized translation edits therefore use the same field whitelist, authorization, and optimistic concurrency rules as other localized packages.

## Public submission security

Public submission flows through `HandlePublicFormSubmissionAction` and `SubmitFormPayload`. The action composes availability, origin, token, rate-limit, honeypot, spam, payload, idempotency, persistence, callback, and response behavior.

Before enabling public routes, configure:

- explicit allowed origins and CORS behavior per form;
- CSRF or short-lived signed public-token strategy;
- `submission.max_payload_bytes`, `max_depth`, and `max_items`;
- rate limits and block windows;
- honeypot names, spam weights, and thresholds;
- a stable idempotency key for retryable clients;
- retention and privacy policy appropriate to the collected data.

Minimum-submission timing uses trusted token issue time. A client-supplied timestamp is not trusted. Reusing an idempotency key with the same payload returns the original result; reusing it with a different payload is rejected.

Submission origin is request-derived. `submittedFrom` is not part of `SubmitFormPayload`, so a client cannot replace the trusted `Origin`, `Referer`, or request-host context with a payload value.

When `allowMultipleRegistrations` is false, Forms stores a SHA-256 registration fingerprint derived from the normalized email address or, when email is absent, the active session identifier. A submission without either identity is rejected. Database uniqueness prevents concurrent duplicates without storing the source identity in the fingerprint column.

Entry submissions persist idempotency state on the entry. Custom handlers use a separate durable receipt: completed retries replay the result, changed payloads conflict, and processing or failed attempts are not automatically re-executed because downstream side effects may already have occurred. Custom handlers should still make their own external operations idempotent.

Bind custom implementations of:

- `FormRateLimiter`
- `FormSpamDetector`
- `FormEntryPrivacyPolicy`
- `FormEntryDeletionPolicy`

The supplied privacy and deletion policies are permissive building blocks, not substitutes for application policy.

## CORS and iframe embedding

`FormType::IFRAME` is a presentation mode, not an authentication signal. Forms does not trust `Sec-Fetch-Dest` or custom iframe headers as proof of embedding. Restricted forms authorize the normalized request origin, and iframe responses expose a CSP `frame-ancestors` value for application middleware to apply.

Form and allowed-origin `corsSettings` use the typed `FormCorsSettings` contract:

```php
'corsSettings' => [
    'policy' => 'custom',
    'allowCredentials' => true,
    'allowWildcards' => false,
    'maxAge' => 600,
    'allowedMethods' => ['GET', 'POST', 'OPTIONS'],
    'allowedHeaders' => [
        'Content-Type',
        'X-CSRF-TOKEN',
        'X-Forms-Public-Token',
        'Idempotency-Key',
    ],
],
```

Unknown keys, unsupported methods, unsafe header names, and out-of-range preflight cache values are rejected. Real `OPTIONS` requests pass through the same availability and origin policy as render, schema, and submit requests. Allowed-origin settings override form defaults for the matching origin.

## Routes

Both route surfaces are disabled by default:

```php
'routes' => [
    'prefix' => 'api/v1',
    'middleware' => ['api'],
    'management' => [
        'enabled' => false,
        'middleware' => ['auth'],
    ],
    'public' => [
        'enabled' => false,
        'middleware' => ['throttle:forms-public'],
    ],
],
'authorization' => [
    'gate' => null,
],
```

Management routes use names beginning with `nvl.forms.management.`. Public render, schema, preflight, and submit routes use `nvl.forms.public.` and accept either a UUID or form handle. The `lang` query parameter selects a supported content locale. Availability, locale, origin, CORS, and throttling middleware apply consistently across the public surface.

Management authorization runs in route middleware before request DTO validation and is repeated at the controller boundary. The policy fails closed until `forms.authorization.gate` names a registered gate. The package does not assume an application middleware alias, frontend path, view directory, or user model.

## Public render and schema contracts

Render responses expose `PublicFormRenderPayload`: localized content and copy, status, type, locale, public restrictions, and display options. Administrative counters, usage timestamps, security secrets, and storage details are not part of the render contract. Provider translations appear only under `extension_translations`.

Schema responses expose `PublicFormSchemaPayload`. Every rule is converted to a stable string representation; PHP validation-rule objects are never serialized. The built-in schema describes the generic submission envelope and payload bounds. Application-specific fields inside `submissionData` remain the custom handler or consuming renderer's semantic validation responsibility.

## Entry privacy and operations

`ExportFormEntriesAction` exports only the selected authorized entry set. `RedactFormEntryAction` removes configured sensitive fields, `AnonymizeFormEntryAction` removes identifying values while preserving permitted aggregate data, and `DeleteFormEntryAction` delegates the final decision to `FormEntryDeletionPolicy`.

Queue large exports and retention jobs in the consuming application. Do not place complete submission payloads in logs or events sent to untrusted listeners.

## Database and identifiers

Package-owned rows use UUID primary keys. The schema separates forms, form translations, entries, custom-handler submission receipts, analytics, allowed origins, and rate-limit state. Lookup, status, availability, form/locale, idempotency, registration-fingerprint, and security query paths are indexed. Spam score is stored as a numeric zero-to-one-hundred value.

Set `forms.migrations.enabled=false` only for controlled adoption. A pre-existing table is not evidence that its columns, key types, indexes, or constraints match v1.

## Commands

```bash
php artisan nvl:forms:doctor
php artisan nvl:forms:doctor --strict --format=json
```

The doctor does not mutate state. It checks required tables, columns, numeric score storage, indexes, foreign keys, privacy/rate/spam bindings, application-key readiness, public throttling, management authentication, and the configured gate's registration.

## Generated TypeScript

Form DTO and enum sources register automatically with `nvl/data` under `Nvl.Forms.*`:

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
```

Use generated display DTOs for clients. Storage columns and internal security state are not a stable frontend contract.

## Failure behavior

Validation failures return safe mapped errors at HTTP boundaries. Origin, token, rate-limit, spam, availability, repeat-registration, stale-revision, and idempotency conflicts are distinct failures. Database mutations are transactional, and events that imply durable state are dispatched after commit. External listeners must be idempotent.

## Verification

The runnable examples above are mirrored by package tests. Before release, run the package Pest suite, Pint, PHPStan at maximum strictness, dependency analysis, generated-type checks, and package distribution validation.

See [UPGRADING.md](UPGRADING.md), [SECURITY.md](SECURITY.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE).
