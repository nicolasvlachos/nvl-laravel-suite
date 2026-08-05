# Spatie Docs And Package Facts

## Table Of Contents

- Official Documentation
- TypeScript Transformer V3 Facts
- Laravel Data V4 Facts
- Repository Package Versions

## Official Documentation

TypeScript Transformer v3:

- Introduction: `https://spatie.be/docs/typescript-transformer/v3/introduction`
- First run: `https://spatie.be/docs/typescript-transformer/v3/getting-started/first-run`
- Attributes: `https://spatie.be/docs/typescript-transformer/v3/getting-started/attributes`
- Typing properties: `https://spatie.be/docs/typescript-transformer/v3/getting-started/typing-properties`
- Custom writers: `https://spatie.be/docs/typescript-transformer/v3/advanced/custom-writers`

Laravel Data v4:

- Creating data objects: `https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/creating-a-data-object`
- Nesting: `https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/nesting`
- Collections: `https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/collections`
- Casts: `https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/casts`
- Optional properties: `https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/optional-properties`
- Mapping property names: `https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/mapping-property-names`
- Defaults: `https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/defaults`
- Computed: `https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/computed`
- Manual rules: `https://spatie.be/docs/laravel-data/v4/validation/manual-rules`
- Working with the validator: `https://spatie.be/docs/laravel-data/v4/validation/working-with-the-validator`
- Wrapping: `https://spatie.be/docs/laravel-data/v4/as-a-resource/wrapping`
- Nesting validation: `https://spatie.be/docs/laravel-data/v4/validation/nesting-data`
- Dates: `https://spatie.be/docs/laravel-data/v4/advanced-usage/working-with-dates`
- TypeScript: `https://spatie.be/docs/laravel-data/v4/advanced-usage/typescript`
- Eloquent casting: `https://spatie.be/docs/laravel-data/v4/advanced-usage/eloquent-casting`

## TypeScript Transformer V3 Facts

- The transformer discovers classes, reflects them, runs configured transformers in order, and writes transformed symbols.
- `#[TypeScript]` marks a class for transformation and can rename or relocate the generated type.
- Native PHP primitives map to TypeScript primitives.
- Nullable PHP types should become `| null`.
- Union and intersection types are supported.
- Untyped arrays are too broad; typed PHPDoc arrays generate better TypeScript.
- String-keyed arrays become records.
- PHPDoc array shapes can become TypeScript object shapes.
- Object references resolve to transformed type references when the object is transformed.
- Unknown untransformed object references become unknown unless replaced.
- `#[LiteralTypeScriptType]` is for literal TypeScript and supports reference placeholders/imports.
- Custom writers implement `Writer` and should use Spatie reference resolution actions when resolving transformed references.

## Laravel Data V4 Facts

- `Data::from()` can create objects from arrays, requests, models, Arrayable objects, JSON, and magic `from*` factories.
- `Data::optional(null)` returns null where `from(null)` cannot.
- Magic creation methods must be public static, start with `from`, and not be named exactly `from`.
- Nested Data objects are supported.
- Data object collections can be ordinary arrays, Laravel collections, paginators, or Spatie Data collections.
- Laravel Data v4 recommends annotations/generics for collection item types because IDEs and static analyzers understand them.
- `DataCollectionOf` is still supported, but v4 docs recommend annotations for analyzer support.
- `Data::collect()` replaces the old `collection()` method.
- `DataCollection` remains useful when using include/exclude/partial Data behaviors.
- `Optional` means the payload key may be absent; it is omitted when transformed.
- Mapping can be input-only, output-only, or both.
- Manual `rules()` for a property replace inferred rules for that property unless merging is enabled.
- `withValidator()` runs only on the root data object.
- Wrapping applies when Data objects are returned as resources/responses, not ordinary `toArray()` unless wrapping execution is explicitly enabled.
- Date casts and transformers can use global formats or local format/timezone options.
- Eloquent casts can store Data objects and DataCollection values in JSON columns.

## Repository Package Versions

At the time this future skill was drafted, the repository had:

- `spatie/laravel-data` 4.23.0
- `spatie/laravel-typescript-transformer` 3.2.0
- `spatie/typescript-transformer` 3.2.0

Re-check with:

```bash
composer show spatie/laravel-data spatie/laravel-typescript-transformer spatie/typescript-transformer
```
