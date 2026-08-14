# NVL Packages

The `nvl/laravel-suite` Composer package contains 20 internal Laravel modules. The installable suite supports PHP 8.4+ and Laravel 12–13, is headless by default, retains explicit dependency boundaries, and includes module-specific configuration, API, operational, and testing documentation.

## `nvl/activity`

Generic activity capture and readable timelines for Laravel models, built on Spatie Activitylog without application-specific business rules.

- Records structured model activity with subjects, actors, visibility, importance, changed attributes, and safe context.
- Supports automatic Eloquent create, update, and delete capture.
- Converts raw audit records into semantic timeline entries through registered mappings, formatters, labels, and headline templates.
- Merges activity records with additional registered timeline providers.
- Supports integer, UUID, ULID, and string subject or actor identifiers.
- Provides bounded pagination, retention and purge operations, schema diagnostics, and an optional authorized management API.

## `nvl/auth`

A headless, pipeline-driven identity, authentication, and access-control
foundation for applications that retain ownership of their concrete User model.

- Projects host authenticatables into package principals, verified contacts,
  devices, sessions, authenticators, invitations, recovery cases, and a
  sanitized security-event ledger.
- Supports password policy contracts, one-time security codes, scanner-safe
  magic links, multiple replay-protected TOTP authenticators, recovery codes,
  and WebAuthn passkey ceremonies.
- Separates cross-device login handoff, authenticator transfer, and account
  recovery into explicit trust transitions.
- Provides configurable invitation purposes, independent recovery evidence,
  security-version rotation, and atomic session/device revocation.
- Uses Spatie Permission for RBAC while keeping permission catalogs and role
  templates host-owned.
- Delivers structured message intents through a replaceable dispatcher and
  exposes after-commit events for optional Activity and notification adapters.
- Keeps public, account, and management APIs independently configurable,
  throttled, and disabled by default.
- Provides named pipelines and cache-safe contract/adapter registration without
  a package facade or application namespace dependency.

## `nvl/data`

The shared DTO and PHP-to-TypeScript boundary for the NVL package family, built around Spatie Laravel Data and Spatie TypeScript Transformer.

- Provides consistent DTO-to-persistence transformation for optional, nullable, enum, date, collection, and nested values.
- Distinguishes create/default, patch, and explicit-null mutation semantics.
- Supplies a stable typed pagination envelope.
- Registers application and package TypeScript source directories deterministically.
- Generates declarations, integrity manifests, checksums, and stale-output reports.
- Optionally serves existing generated artifacts through protected, bounded, non-generating HTTP routes.
- Validates configured roots against traversal and symlink escape.

## `nvl/comments`

A polymorphic discussion engine for public conversations, private notes, and internal review threads.

- Attaches comments and bounded reply trees to integer, UUID, ULID, or string-keyed Eloquent resources.
- Stores original author locale without treating user-authored speech as centrally editable translated content.
- Supports plain text and Markdown, tags, pinning, optimistic revisions,
  idempotent creation, audited delete/restore, and terminal anonymization.
- Separates viewer-independent public, viewer-aware member, and privileged
  management projections with strict structural tombstones.
- Applies canonical target/query scoping before filters, pagination, counts, or
  identifier resolution and supports batched safe author presentation.
- Provides deterministic configured reactions, lifetime/open report semantics,
  actionable target-scoped moderation queues, and immutable revision restore.
- Attaches ownership-authorized private Media files with safe association
  payloads, exact idempotent detach, and a fixed concurrency lock order.
- Ships a dry-run-first reconciliation command for counters and thread lineage,
  expanded readiness diagnostics, and versioned after-commit events.
- Keeps public, member, and management route groups independently configurable
  and disabled by default.

## `nvl/content`

A schema-driven, headless content-block engine for reusable, translatable application content, structured fields, Media/reference values, and owner compositions.

- Loads source-authoritative definitions from configuration or deterministic, root-confined, file-count/size-bounded `*.content.php` and `*.content.json` files.
- Supports text, rich text, numbers, booleans, dates, choices, JSON Schema values, objects, lists, repeaters, tables, Media, Media collections, references, and custom field adapters.
- Stores localized field values in `content_blocks_i18n` through `nvl/translatable`.
- Creates reusable scoped blocks with lifecycle state, optimistic revisions, immutable schema/view snapshots, and revision history.
- Lists schemas for headless editors and places or unplaces blocks on registered consumer owners with stable keys, same-region subtrees, sorting, overrides, placement-count/depth limits, cycle prevention, and revision checks.
- Validates payload size, depth, item count, unknown fields, rich text, Media ownership/visibility, references, and JSON Schema Draft 2020-12.
- Renders deterministic transport-safe DTO compositions and optional source-controlled Blade views while pruning whole hidden/private/unpublished subtrees.
- Captures bounded immutable composition snapshots that bind owner identity and validate parents, regions, depth, size, and cycles while resolving current Media/reference delivery at render time.
- Preserves locales on Media associations, exposes typed private projections with short-lived URLs, and requires atomic Content/Media writes to share one named database connection.
- Keeps management and public APIs independently configurable, authorized, and disabled by default.

## `nvl/csv`

A typed, filesystem-aware CSV toolkit for analysis, validation, transformation, import, export, streaming, and queued processing.

- Detects delimiter, BOM, encoding, line endings, headers, column types, duplicates, structural issues, and processing recommendations.
- Imports local paths or Laravel filesystem streams with explicit dialect, encoding, mapping, validation, row-limit, error, duplicate, and transaction policies.
- Streams rows or processes bounded synchronous batches without loading the complete source.
- Exports arrays, collections, Eloquent queries, and provider streams with exact headings, fields, nested keys, callbacks, formats, encodings, BOMs, indexes, and line endings.
- Stages bounded async chunks on the local disk and dispatches serializable Laravel batch jobs with progress, completion, tracking, and cancellation.
- Provides typed Data objects, enums, validators, transformers, filters, configurations, mappings, and result value objects.
- Owns no routes, controllers, models, tables, or application-specific persistence.

## `nvl/filterable`

Safe, allowlisted Eloquent filtering and sorting for application and package queries.

- Defines filters and sorts through explicit aliases rather than exposing database column names.
- Converts HTTP query parameters into transport-neutral typed `FilterSet` objects.
- Supports string, boolean, integer, decimal, enum, date, date-time, null, set, and range filtering.
- Supports registered relation filters with nesting and complexity limits.
- Rejects unsupported operators, malformed values, undeclared aliases, and unsafe sorting.
- Provides database-portable filtering behavior for SQLite, PostgreSQL, and MySQL.

## `nvl/forms`

A secure, extensible, localized form-definition and submission engine.

- Creates and manages forms, nested sections, fields, options, validation messages, and provider extension content.
- Stores localized form content in `forms_i18n` through `nvl/translatable`.
- Protects definition updates with optimistic concurrency.
- Secures public submissions with origin controls, CORS policy, throttling, CSRF or signed tokens, payload limits, idempotency, honeypots, and pluggable spam detection.
- Stores entries and analytics with export, redaction, anonymization, retention, and deletion operations.
- Supports custom handlers, render-data providers, field behavior, error mappings, and callbacks through validated registries.
- Emits after-commit events for optional notification, activity, indexing, and application integrations.
- Keeps public and management routes independently configurable and disabled by default.

## `nvl/mail-notifications`

Provider-neutral, explicitly opt-in outbound mail tracking and optional
scheduled delivery that extend Laravel Mail without replacing it.

- Tracks only Mailables that implement the package contract and concern; ordinary Laravel Mailables remain unaffected.
- Records correlation, normalized recipients, pending/accepted states, provider lifecycle transitions, and idempotent provider events.
- Dispatches observational lifecycle events after commit without allowing host listeners to alter mail delivery.
- Includes an optional SDK-free MailerSend adapter while preserving replaceable provider contracts, signed webhook verification, and transport-neutral identifiers.
- Schedules versioned host-owned message factories with bounded payloads, deterministic retries, token-fenced claims, cancellation, and stale-claim recovery.
- Provides bounded, status-aware retention commands while protecting active scheduled work.
- Redacts sensitive metadata, stores bounded failure context, and supports environment-safe recipient interception.
- Excludes configured mailers independently of the global feature switch and preserves normal Laravel transport behavior.
- Ships cache-safe configuration, automatic or publishable migrations, publishable mail views, and no package-owned HTTP routes.
- Provides strict diagnostics for configuration, schemas, redaction, provider mappings, scheduling, and production interception safety.

## `nvl/media`

A comprehensive media subsystem for private one-to-one files, reusable public assets, S3-compatible storage, delivery, and image processing.

- Supports server-proxied uploads and direct multipart/resumable object-storage uploads.
- Supports local disks, Amazon S3, and S3-compatible Laravel Flysystem disks with private-at-rest defaults.
- Models pending upload, pending scan, quarantined, available, variation processing, failed, and deleted lifecycle states.
- Verifies checksums, supports pluggable malware scanning, and blocks quarantined media from association, transformation, reuse, or delivery.
- Enforces atomic one-to-one replacement for private collections and policy-bounded deduplication for reusable public assets.
- Attaches media polymorphically to owners with ordered collections and placement metadata.
- Stores localized title, alternative text, caption, and description in `px_media_i18n`.
- Generates queued, idempotent image variations with configurable WebP and AVIF quality, compression, proportional sizing, presets, and filenames.
- Includes overrideable `thumb`, `small`, `medium`, and `optimized` presets, with proportional output bounded up to 1200px by default.
- Delivers public and signed private assets with GET, HEAD, byte ranges, ETags, conditional responses, content disposition, and appropriate cache policies.
- Provides reconciliation, regeneration, disk migration, orphan detection, checksum verification, and strict doctor commands.

## `nvl/metafields`

Typed, validated, queryable, and optionally localized custom fields for registered Eloquent owners.

- Registers owner and reference aliases without persisting consumer class names in public contracts.
- Creates, updates, archives, and assigns reusable metafield definitions.
- Supports string, text, rich text, integer, decimal, boolean, enum, date, date-time, JSON, single-reference, and reference-list values.
- Validates structured values with payload-size, depth, item-count, schema, and recursion limits.
- Supports patch, replace, reset, and bulk synchronization with optimistic concurrency.
- Stores localized definition copy in `metafields_definitions_i18n` and eligible localized values in `metafields_i18n`.
- Resolves and authorizes references through application-provided registries.
- Provides safe indexed query helpers and an optional authorized management API.

## `nvl/pages`

A localized, hierarchical page and dynamic-resource routing package that composes Content, SEO, Metafields, and sitemap generation.

- Creates UUID-backed static and dynamic pages with structural locale-independent paths, model-cast kind/status values, soft deletion, and optimistic revisions.
- Supports a configurable hierarchy from one to four levels and prevents cycles, excessive depth, cross-site parents, and path collisions.
- Stores translated titles, navigation labels, and summaries in `pages_i18n` through `nvl/translatable`.
- Places ordered Content blocks on the registered `page` owner, preserving Content’s region, tree, visibility, translation, Media, and snapshot rules.
- Registers pages as SEO and Metafields owners without duplicating either package’s persistence or validation behavior.
- Resolves static paths first and then allowlisted dynamic resource handlers with explicit patterns, parameter rules, constrained Eloquent queries, fetch conditions, authorization, and sanitized DTO output.
- Never serializes a dynamic Eloquent resource directly; every handler owns its public projection and may fail closed for missing or unpublished rows.
- Generates canonical URLs through a replaceable URL contract with optional locale prefixes.
- Streams static and handler-provided dynamic sitemap entries while deferring SEO-profile-owned URLs to the SEO sitemap source to prevent duplicates.
- Invalidates sitemap artifacts after committed page mutations.
- Keeps public resolution and management route groups independently configurable, authorized, and disabled by default.
- Provides typed create, update, move, delete, list, inspect, and resolve Actions plus a strict non-mutating doctor command.

## `nvl/primitives`

Immutable application value objects, exact arithmetic, validation rules, Eloquent casts, and reference catalogs.

- Provides value objects for money, currencies, countries, locales, time zones, phone numbers, email addresses, URLs, IBANs, coordinates, percentages, weights, postal addresses, and date-times.
- Implements exact money arithmetic, allocation, comparison, currency precision, explicit rounding, and safe decimal or minor-unit storage.
- Provides validated canonical construction, normalization, equality, string serialization, and JSON serialization.
- Supplies Eloquent casts and Laravel validation rules for supported primitives.
- Uses maintained ISO, phone-number, currency, and IBAN reference data.
- Exposes exchange-rate and catalog extension contracts with explicit unavailable or stale-data behavior.
- Generates stable TypeScript representations for DTO-facing primitives.
- Has no database tables or migrations.

## `nvl/seo`

A complete localized SEO runtime for model resources, routes, social previews, structured data, redirects, robots, and sitemaps.

- Attaches site-scoped SEO profiles to arbitrary Eloquent resources.
- Stores localized paths and metadata in `seo_profiles_i18n` with deterministic locale fallback.
- Resolves and renders titles, descriptions, canonical URLs, hreflang links, robots directives, Open Graph tags, and Twitter cards.
- Builds bounded JSON-LD graphs from persisted nodes and resource-aware structured-data providers.
- Resolves social images through direct URLs or an optional media integration.
- Detects duplicate paths and applies deterministic profile-resolution precedence.
- Provides optional redirects with locale/site scope, expiry, loop detection, chain flattening, and hit metadata.
- Generates cached, bounded sitemap files and sitemap indexes with invalidation and last-modified tracking.
- Provides configurable public crawler routes and a separately configurable, authorized management API.
- Protects profile and redirect mutations with optimistic concurrency and after-commit cache invalidation.

## `nvl/settings`

A source-defined, typed runtime settings engine with synchronized database overrides.

- Discovers `*.settings.php` and `*.settings.json` files from configurable directories.
- Validates discovery roots, source sizes, JSON depth, duplicate namespaces, symlinks, types, defaults, and validation rules.
- Keeps source-controlled definitions authoritative while storing runtime overrides and synchronization metadata in the database.
- Supports string, text, integer, decimal, boolean, enum, date, date-time, JSON, and nullable values.
- Provides typed get, list, effective-value, set, reset, validate, synchronize, status, cache, and clear operations.
- Supports scopes, scheduled validity windows, fallback values, orphan policies, and optimistic concurrency.
- Emits after-commit change events without exposing setting values.
- Can apply explicitly allowlisted settings as Laravel configuration overrides.
- Provides an optional authorized management API with configurable route path, route-name prefix, and middleware.

## `nvl/support`

The minimal shared foundation for transport-neutral business failures and stable response codes.

- Defines stable machine-readable response-code contracts.
- Provides `BusinessException` with a suggested presentation status.
- Separates deliberately safe public context from internal diagnostic context.
- Preserves the previous-exception chain.
- Validates response status ranges and exception construction.
- Has no models, migrations, controllers, routes, DTOs, global state, or internal NVL dependencies.

## `nvl/taxonomy`

Reusable translated vocabularies and hierarchical terms for categories, tags, and other classification systems.

- Registers vocabulary behavior, allowed owners, hierarchy rules, maximum depth, metadata rules, and membership policy.
- Creates, updates, moves, reparents, merges, prunes, and deletes UUID-backed terms.
- Stores translated term names and descriptions in `terms_i18n` while keeping slugs structural and locale-independent.
- Prevents cycles, invalid depth, cross-vocabulary parents, and duplicate sibling slugs.
- Attaches, detaches, and synchronizes terms polymorphically to registered owners.
- Supports deterministic tree and subtree loading without translation N+1 queries.
- Protects mutations with optimistic concurrency and provides dry-run maintenance and doctor commands.

## `nvl/translatable`

The central runtime for deterministic locale-specific Eloquent content across NVL packages and consumer applications.

- Defines the `<owner_table>_i18n` convention for dedicated localized model rows.
- Validates and normalizes locale identifiers.
- Resolves exact locale, normalized base locale, configured fallbacks, default locale, and deterministic final fallback.
- Keeps content locale state scoped to the current request, job, or execution context.
- Provides eager-loading scopes and explicit translated-field access.
- Supports patch, replace, upsert, and delete operations through validated translation writers.
- Registers translatable resources centrally with editable fields, labels, queries, authorization, and version calculation.
- Gathers resources, locale coverage, missing translations, and bounded paginated translation data.
- Rejects stale synchronized writes with expected version hashes and emits after-commit translation events.
- Remains HTTP-free so applications can build their own authorized management interface.

## `nvl/templates`

A composable Blade/PDF rendering core with a complete database-backed implementation over `nvl/content` and `nvl/media`.

- Exposes a directly constructible and extendable `Nvl\Templates\Template` class with typed template, PDF, margin, page-size, and orientation options.
- Provides injectable `BaseTemplate` and `BasePdfTemplate` adapters that preserve the reusable class-template configuration, variation, frame/sticker, rendering, response, and storage workflow.
- Provides read-only Content and asset view accessors, a pluggable asset-handle resolver, guarded local/inline/remote inputs, atomic local saves, private disk storage, and production-gated PDF diagnostics.
- Uses one renderer context and output-verification pipeline for direct templates, stored publications, synchronous APIs, and queued renders.
- Registers executable renderer aliases, source-controlled views, payload schemas, profiles, composition constraints, and owner resolvers.
- Bundles print-oriented mPDF rendering while preserving an engine contract for browser PDF, mail, text, or remote renderers.
- Configures page geometry, fonts, metadata, DPI, image quality, header/footer views, watermark, compression, PDF/A, resource policy, and output limits.
- Ships and safely publishes basic HTML/PDF documents, headers, footers, sections, tables, and page breaks to default or guarded custom paths.
- Returns verified output bytes with MIME, size, checksum, filename, subject, and protected inline/download response helpers.
- Validates a bounded JSON Schema subset before render and allows consumers to bind a complete schema implementation.
- Stores localized template labels in `templates_i18n`; version copy, structured values, and Media IDs use Content fields and locale rows.
- Captures immutable Content composition snapshots at publication while re-resolving current Media/reference delivery at render time.
- Enforces required regions and allowlisted Content definition keys per template.
- Supports drafts, publication, retirement, snapshot hashes, optimistic revisions, and after-commit events.
- Assigns templates or pinned versions to arbitrary registered owners and profiles.
- Renders synchronously or through idempotent queued render records with encrypted payloads.
- Bounds PDF options and HTML, uses a dedicated temp root, and fail-closes remote assets behind HTTPS host allowlists.
- Persists generated output as private one-to-one Media when enabled.
- Supplies Blade by default while allowing PDF, mail, or other renderers through an explicit contract.
- Keeps render and management APIs independently configurable, authorized, and disabled by default.

## `nvl/translations`

A file-authoritative workspace for scanning, editing, synchronizing, and resaving Laravel PHP and JSON language files.

- Reads grouped PHP translation files and locale JSON files from configurable application, module, vendor, and custom roots.
- Synchronizes file strings into editable database workspace rows without replacing Laravel’s runtime file loader.
- Tracks source hashes, database edits, missing entries, synchronization state, and conflicts.
- Supports fail, prefer-file, prefer-database, and interactive conflict strategies.
- Exports deterministic PHP arrays and UTF-8 JSON to source directories or named configured targets.
- Performs bounded, atomic file writes and can create backups before destructive replacement or pruning.
- Supports dry runs, process locks, machine-readable plans, scoped imports, scoped exports, and safe pruning.
- Scans application source for literal translation-key usage.
- Provides filterable management Actions and an optional authorized API disabled by default.
- Rejects path traversal, symlink escape, overlapping unsafe scopes, accidental vendor writes, malformed files, and unsupported encodings.
