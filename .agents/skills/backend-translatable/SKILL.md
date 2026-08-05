---
name: backend-translatable
description: Use when designing, adding, migrating, querying, mutating, testing, diagnosing, auditing, or reviewing locale-specific Eloquent data with nvl/translatable, including related translation tables, grouped same-table translations without owner rows, typed model declarations, fallback policies, request-scoped locale state, central resource registration, schema diagnostics, and package integrations.
---

# Backend Translatable

Treat `nvl/translatable` as the single model-content translation runtime. Keep
Laravel UI strings in language files managed by `nvl/translations`.

## Work in this order

1. Inspect the owner or grouped-row model, its migration, connection, casts,
   fillable fields, and existing domain mutation action.
2. Choose related or self storage from the domain's canonical identity.
3. Create or update explicit domain-owned models and migrations.
4. Add a typed `defineTranslations()` declaration.
5. Register a stable resource key, visibility scope, display/search metadata,
   and authorization.
6. Route writes through the owning transaction and mutation policy.
7. Run the doctor, focused tests, static analysis, formatting, and TypeScript
   declaration checks.

Do not generate or discover models, migrations, fields, or storage strategies
from translation declarations. Definitions do not encode SQL types,
nullability, defaults, indexes, casts, connection ownership, relationships,
or migration history. Use explicit schema plus
`php artisan nvl:translatable:doctor` as the verification boundary.

## Select the storage strategy

Use related rows when a canonical owner has meaningful locale-independent
state. Implement `TranslatableModel`, use `Translatable`, and return
`RelatedTranslationDefinition`:

```php
protected function defineTranslations(): RelatedTranslationDefinition
{
    return new RelatedTranslationDefinition(
        translationModel: ArticleTranslation::class,
        foreignKey: 'article_id',
        fields: ['title', 'summary'],
    );
}
```

Use self rows when no canonical owner exists and the logical resource is a
group of localized rows. Implement `SelfTranslatableModel`, use
`SelfTranslatable`, and return `SelfTranslationDefinition`:

```php
protected function defineTranslations(): SelfTranslationDefinition
{
    return new SelfTranslationDefinition(
        groupKey: 'entry_key',
        fields: ['name', 'description'],
        sharedFields: ['type'],
    );
}
```

Never auto-detect fields or infer storage from table names.

Declare `mutationPolicy: TranslationMutationPolicy::DomainActionOnly` whenever
translation writes require package validation, optimistic concurrency,
related-record synchronization, activity, or domain events. Such resources
remain centrally discoverable and reportable, but must be written through
their package Actions. Use `Direct` only when `TranslationWriter` alone owns
the complete mutation invariant.

## Enforce persistence invariants

For related rows:

1. Use a dedicated translation table with UUID, owner foreign key, locale
   string length 35, translated columns, timestamps, owner/locale unique
   index, and cascading deletion.
2. Use `HasUuids` on every model backed by a UUID primary key.
3. Keep the translation model's table, fillable fields, casts, relationships,
   key strategy, and connection aligned with its migration.
4. Keep owner and translation models on the same named connection.
5. Keep canonical handles, routing slugs, hashes, namespaces, and structural
   state on the owner.
6. Never declare Eloquent-managed primary key, timestamp, or soft-delete
   columns as translated fields.

For self rows:

1. Require an explicit stable, nonlocalized group key.
2. Require a group/locale unique index.
3. Separate locale-varying `fields` from copied structural `sharedFields`.
4. Reject deletion of the final row unless the definition explicitly permits
   an empty logical resource.
5. Use instance writes or package mutation APIs; bulk query updates bypass
   model invariants and must never change group or locale identity.

Run `php artisan nvl:translatable:doctor` after declaration, schema, connection,
or global locale changes.

## Read translations

- Use `translated()` for a value and `resolveTranslation()` for provenance.
- Use `getTranslatedAttributes()` for declared fields.
- Use `getTranslation($locale, withFallback: false)` for exact rows.
- Eager-load related translations for collections.
- Use `locale()` to select one requested or fallback self row per group.
- Use only declared fields in `whereTranslated()` and
  `orderByTranslated()`.
- Preserve empty strings and other non-null falsey values.

Use `TranslationFallbackPolicy::Configured` by default. Use
`TranslationFallbackPolicy::ExactOnly` when fallback is forbidden. Use
`TranslationFallbackPolicy::AnyAvailable` only when explicitly required.
Never select a row by insertion order.

## Write translations

- Validate locale-keyed HTTP payloads with `SupportedLocaleMapRule`.
- Validate programmatic input through the definition and `LocaleRegistry`.
- Call `TranslationWriter` inside a transaction on
  `$model->getConnection()`.
- For model-local self-row mutations, use `setTranslation()`,
  `cloneTranslation()`, and `deleteTranslation()`; these preserve immutable
  identity, grouped locking, final-row protection, retries, and loaded state.
- Prefer central mutation actions for registered resources.
- Default to `TranslationSyncMode::Patch`; require explicit
  `TranslationSyncMode::Replace`.
- Require expected versions for central writes.
- Dispatch mutation side effects after the same connection commits.
- Derive `TranslationActorData` on the server; never trust a client-supplied
  system actor.

Do not write translation rows from controllers, DTOs, observers,
presentation services, or arbitrary model `fill()` / `save()` calls.

## Resolve content locale

- Use scoped `ContentLocale`; keep it separate from Laravel's UI locale.
- Set it in middleware or another explicit request/job boundary.
- Configure global locales, fallback policy, fallbacks, labels, and limits in
  `config/translatable.php`.
- Configure `transactions.attempts` for centralized deadlock retries.
- Bind `ContentLocalePreferenceResolver` for persisted user preferences.

## Audit existing integrations

For every model using `Translatable` or `SelfTranslatable`, verify:

- The contract, trait, and typed definition agree on storage.
- Declared fields exist and exclude structural and Eloquent-managed columns.
- Locale keys are canonical and model locales only narrow the global catalog.
- The migration enforces owner/locale or group/locale uniqueness.
- Related rows cascade on owner deletion and use the owner's connection.
- The provider registers one stable key with valid searchable, display, order,
  visibility, authorization path, and page-size metadata.
- Every `TranslationWriter` call is inside a transaction on the model
  connection, or is called by an action that owns that transaction.
- `DomainActionOnly` resources are not mutated by generic central actions.
- No controller, DTO, observer, mass query update, or arbitrary `save()` writes
  translated payloads or changes self-row identity.
- Configuration contains no closures and no code scans model directories.

## Verify

Test both strategies for exact reads, normalized base fallback, configured
fallback, null fallback, intentional empty values, invalid locales and fields,
patch/replace behavior, self final-row protection, eager-loading query counts,
grouped scope composition, request isolation, non-default connections,
concurrent locale creation, payload bounds, stale versions, and after-commit
events.
