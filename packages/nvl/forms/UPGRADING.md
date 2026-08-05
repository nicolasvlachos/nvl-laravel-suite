# Upgrading NVL Forms

## Upgrading to 1.0

Version 1.0 stores localized definition content only in dedicated translation rows, disables all routes, removes frontend scaffolding, and makes Activity optional.

1. Set `forms.migrations.enabled=false` for an existing schema.
2. Run `php artisan nvl:forms:doctor --strict --format=json`.
3. Backfill dedicated translation rows in an application-owned reversible bridge.
4. Replace old submission DTOs with `SubmitFormPayload`; do not send `submittedFrom`, which is now request-derived.
5. Supply expected revisions when mutating definitions.
6. Convert CORS JSON to `FormCorsSettings` camelCase keys and restrict methods to `GET`, `POST`, and `OPTIONS`.
7. Decide whether each form allows repeat registration. Forms that disable it require an email or active session and need the new registration-fingerprint uniqueness constraint.
8. Migrate custom resolvement integrations to durable submission receipts and send an idempotency key for retryable clients.
9. Replace `FormCreatedEvent` and `FormEntryCreatedEvent` listeners with `FormChangedEvent` and sanitized `FormEntryChangedEvent`.
10. Remove references to `forms.secret_key`, handler tokens, thank-you configuration, `FormHandlerTokenMiddleware`, `FormLookupService`, and `PublicFormPresentationService`.
11. Bind privacy, deletion, spam, handler, and renderer extensions as needed.
12. Enable public and management routes independently, with explicit authentication, a registered management gate, public throttling, and origin policy.

Public render/schema consumers should adopt `PublicFormRenderPayload` and `PublicFormSchemaPayload`. Render extension translations now have one canonical key: `extension_translations`.

Run the strict doctor after schema adoption. It now requires the custom submission receipt table, registration indexes, numeric `spam_score`, foreign keys, application key, security bindings, public throttle middleware, management authentication, and a registered management gate.
