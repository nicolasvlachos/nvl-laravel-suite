---
name: backend-forms
description: Use when implementing, translating, securing, submitting, rendering, extending, testing, or reviewing nvl/forms forms, entries, public APIs, custom handlers, allowed origins, throttling, spam detection, analytics, or localized nested form content.
---

# Backend Forms

Keep form administration, public rendering, and submission processing on package Actions. Treat form identity/copy and arbitrary nested field content as translatable content.

## Create and update forms

- Validate `MutateFormPayload`.
- Use `CreateFormAction`, `UpdateFormAction`, and `DuplicateFormAction`.
- Keep handle, status, type, resolvement, availability, security, analytics counters, origins, and redirects nonlocalized.
- Store localized name, description, submit label, success title, success message, and arbitrary nested content in `form_translations`.
- Preserve the legacy `translations` JSON only during the compatibility window.
- Use patch translation semantics by default and require explicit replacement.

## Render publicly

- Apply `forms-locale` middleware to establish `ContentLocale`.
- Resolve forms with `GetFormForRenderAction`.
- Render with `TransformFormDataForRenderAction`.
- Use `displayName`, `displayDescription`, `localizedContent`, and explicit translated fields.
- Keep extension-provider translations under `extension_translations`; retain the legacy top-level key only for compatibility.
- Never choose an arbitrary locale or decode legacy JSON in controllers.

## Submit securely

- Use `HandlePublicFormSubmissionAction`.
- Preserve host validation, public tokens, CSRF policy, availability checks, per-form throttling, honeypots, spam scoring, and multiple-registration behavior.
- Register custom submission behavior through `FormHandlerRegistry`.
- Register supplemental render data through `FormRenderDataRegistry`.
- Keep callbacks and providers container-resolved and owned by their consuming modules.

## Migrate

Run `forms:normalize-translations --dry-run`, inspect the report, then run without `--dry-run`. The command normalizes double-encoded legacy JSON and idempotently backfills dedicated rows in chunks.

## Verify

Test administrative authorization, render locale/fallback, nested content preservation, patch/replace writes, backfill idempotency, host/CORS rules, token expiry, throttling, spam boundaries, custom handler failures, entry persistence, analytics, duplicate resets, and after-commit activity/events.
