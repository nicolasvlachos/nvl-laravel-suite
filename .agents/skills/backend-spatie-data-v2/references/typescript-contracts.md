# TypeScript Contract Guide

## Table Of Contents

- Source Of Truth Order
- Attribute Rules
- Nullability And Optionality
- Arrays And Records
- Custom Transformer Bridge
- Review Checklist

## Source Of Truth Order

Use the narrowest stable source first:

1. Native PHP scalar/object/enum types.
2. Native nullable and union types.
3. Named Spatie Data classes for nested business shapes.
4. PHPDoc/PHPStan generics and array shapes.
5. `#[LiteralTypeScriptType(...)]` for TypeScript-only literals or dynamic records.
6. Custom transformer bridge code only for migration compatibility.

## Attribute Rules

Good native inference:

```php
public readonly string $name;
public readonly ?string $email;
public readonly bool $isCancelable;
public readonly BookingWorkflowEligibility $eligibility;
public readonly ?BookingShowVoucher $voucher;
```

Avoid redundant primitive literals:

```php
#[LiteralTypeScriptType('string')]
public readonly string $name;
```

Use literal TypeScript only when PHP cannot express the contract:

```php
#[LiteralTypeScriptType("'none' | 'warning' | 'critical'")]
public readonly string $severity;
```

Use enum PHP types directly when possible:

```php
public readonly BookingStatus $status;
public readonly ?BookingStatus $previousStatus;
```

Avoid `#[TypeScriptType(BookingStatus::class)]` unless installed transformer behavior requires it and generated output is verified.

## Nullability And Optionality

PHP truth must match TypeScript truth.

If PHP says:

```php
public readonly ?BookingShowVoucher $voucher;
```

Generated TypeScript must say:

```ts
voucher: BookingShowVoucher | null
```

If the generated type is:

```ts
voucher: BookingShowVoucher
```

then the contract is wrong.

Rules:

- `Type|Optional` means the field may be omitted.
- `Type|Optional|null` means omitted or explicitly null.
- `?Type` means always present but nullable.
- `#[TypeScriptOptional]` is only for TypeScript optionality not already represented by `Optional` or package inference.
- Do not use `#[TypeScriptType(SomeClass::class)]` on nullable object properties unless generated output includes `| null`.

## Arrays And Records

Use PHPDoc shapes for known arrays:

```php
/** @var array<int, string> */
public readonly array $tags;

/** @var array{code: string, label: string} */
public readonly array $status;
```

Use `Record<string, unknown>` only for dynamic/provider-owned data:

```php
#[LiteralTypeScriptType('Record<string, unknown> | null')]
public readonly ?array $payload;
```

If frontend reads known keys, create a named Data class.

## Custom Transformer Bridge

The current app has custom TypeScript bridge code in `app/Support/Typescript`. Treat it as compatibility support, not a target authoring style.

Allowed bridge uses:

- Preserve legacy generated namespaces.
- Support migration away from removed or deprecated attributes.
- Split generated declarations into stable files.
- Keep existing frontend imports working during incremental migration.

Do not add new bridge processors for one-off DTO authoring problems until native PHP types, PHPDoc, named DTOs, or upstream attributes have been tried.

## Review Checklist

After changing a DTO contract:

- Run `php artisan typescript:transform`.
- Inspect generated declaration for changed classes.
- Confirm `?Type` generated `| null`.
- Confirm `Optional` generated optional `?` when expected.
- Confirm app-owned fields are camelCase.
- Confirm records are not hiding known business keys.
- Confirm no Eloquent model generated as meaningful frontend type.
- Run `npm run types` when frontend generated contracts changed.
