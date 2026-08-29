---
name: nvl-forms
description: Implement, integrate, test, or review nvl/forms in Laravel 13. Use for headless localized form definitions, secure public submissions, entries, idempotency, origins, throttling, spam contracts, renderer or handler registries, privacy operations, management authorization, or optional activity integration.
---

# NVL Forms

Keep definitions, public rendering, submissions, and entry operations behind package Actions. Forms is headless and must install without `nvl/activity`.

## Manage definitions

- Validate `MutateFormPayload`.
- Use `CreateFormAction`, `UpdateFormAction`, `DuplicateFormAction`, and `DeleteFormAction`.
- Require the expected revision for edits.
- Store all localized form, section, field, option, and message copy through `nvl/translatable`.
- Register custom handlers, render data, and error mappers through their registries; duplicate keys must fail.

## Submit safely

- Use `HandlePublicFormSubmissionAction` with `SubmitFormPayload`.
- Keep public routes disabled unless explicitly enabled.
- Treat submission origin as request-derived; never accept `submittedFrom` from public payloads.
- Configure allowed origins and typed `FormCorsSettings`; enforce iframe embedding with origin policy and CSP rather than spoofable request headers.
- Configure CSRF or signed tokens, rate limits, payload bounds, idempotency keys, repeat-registration identity, and spam detection deliberately.
- Treat trusted token issue time as the minimum-submission-time source.
- When repeat registrations are disabled, require normalized email or an active session and preserve the fingerprint uniqueness constraint.
- Preserve custom-handler receipts. Completed retries replay; changed, processing, or failed receipts conflict instead of re-running unknown side effects.
- Return safe validation and rejection responses without leaking handler or storage details.

## Protect stored entries

- Use `ExportFormEntriesAction`, `RedactFormEntryAction`, `AnonymizeFormEntryAction`, and `DeleteFormEntryAction`.
- Bind `FormEntryPrivacyPolicy` and `FormEntryDeletionPolicy` for application decisions.
- Deliver notifications and optional audit activity from `FormChangedEvent` and sanitized `FormEntryChangedEvent`.
- Keep entry callbacks best-effort and isolated after durable persistence.
- Run `nvl:forms:doctor --strict --format=json` before enabling routes or adopting tables.

## Verify

Test installation without Activity, revisions, nested translations, public DTO serialization, UUID/handle routes, locale middleware, origins, real preflight behavior, throttling, tokens, spam, payload limits, registration and idempotency races, callback isolation, privacy policies, sanitized after-commit events, pre-validation route authorization, and query counts.
