# Changelog

All notable changes to `nvl/forms` are documented here.

## [Unreleased]

## [1.0.2] - 2026-08-12

- Serializes atomic submission-counter timestamps through Eloquent's
  connection-aware date format for PostgreSQL, MySQL, MariaDB, and SQLite.
- Consolidated every pre-release form schema addition into the clean-install create migrations.
- Standardized the localized-content table as `forms_i18n`.
- Made form mutation DTO validation independent from requests, route bindings, and database reads.
- Injected application environment state and added the documented `forms-migrations` publish tag.
- Enforced management authorization before DTO validation on every management route.
- Applied public availability and locale middleware consistently and allowed UUID-or-handle public URLs.
- Replaced ad-hoc render and schema arrays with public-safe generated DTO contracts.
- Removed client-controlled submission origin and derive origin only from trusted request context.
- Implemented durable repeat-registration fingerprints and custom-handler idempotency receipts.
- Replaced hard-coded CORS headers with validated form and allowed-origin policy resolution and real preflight handling.
- Isolated post-entry callbacks and removed duplicate events that serialized full entry models.
- Removed unused form secrets, handler-token middleware, presentation/lookup services, and inert configuration.
- Stored spam score numerically and expanded the doctor to inspect indexes, foreign keys, bindings, route security, gates, and signing readiness.

## [1.0.0] - 2026-08-08

- Added headless localized form definitions and secure public submissions.
- Added revision checks, idempotency, payload bounds, trusted timing, origins, throttling, and spam contracts.
- Added entry export, redaction, anonymization, and deletion policies.
- Removed consumer defaults, frontend scaffolding, legacy JSON localization, and the hard Activity dependency.
