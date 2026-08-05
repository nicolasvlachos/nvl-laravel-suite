---
name: nvl-primitives
description: Implement, integrate, test, or review nvl/primitives application value objects in Laravel 12–13. Use for exact money and exchange rates, Eloquent value-object casts, email/URL/phone/IBAN validation, ISO country/currency/locale codes, coordinates, percentages, length, weight, postal addresses, date-time instants, reference selectors, and Spatie Data/TypeScript boundaries.
---

# NVL Primitives

Treat primitives as immutable validated values, not bags of formatting helpers. Preserve their canonical storage representation at every persistence boundary.

## Choose the primitive

- Use `Money` for monetary values. Never use floats for construction, arithmetic, exchange rates, or storage.
- Use `CurrencyCode`, `CountryCode`, and `LocaleCode` for standards-backed codes.
- Use `PhoneNumber` for E.164 storage and `Iban` for electronic IBAN storage.
- Use `EmailAddress` and `Url` when the value must be syntactically valid at construction.
- Use `Coordinates`, `PostalAddress`, `Percentage`, `Length`, `Weight`, and `DateTimeValue` for their corresponding domain values.
- Create a new primitive only when validation, canonicalization, equality, or operations are meaningfully stronger than the underlying scalar.

## Persist values

Declare the value-object class directly in `casts()`:

```php
protected function casts(): array
{
    return [
        'email' => EmailAddress::class,
        'coordinates' => Coordinates::class,
        'price' => Money::class,
    ];
}
```

`Money::class` stores `{"minor":"…","currency":"…"}`. Use `Money::class.':minor,EUR'` or `Money::class.':decimal,EUR'` only for an explicitly fixed-currency column. Store variable-currency money as JSON or as two application-owned columns; never silently infer record currency.

## Calculate money

- Construct from decimal strings or integer minor units with an explicit currency.
- Require an explicit `RoundingMode` whenever an operation cannot be represented exactly.
- Convert through injected `CurrencyConverter` and `ExchangeRateProvider`.
- Configure every exchange-rate direction explicitly; never derive an inverse rate.
- Format for display through injected `MoneyFormatter`.
- Bind a live or database provider at the application boundary; do not call an external exchange-rate API from a value object.
- Keep the source amount and currency auditable outside the converter when legal or accounting provenance requires them.

## Validate input

Use `ValidPrimitive` for scalar primitives and `ValidPhoneNumber` when a national-number region is required. Construct the value object again at the DTO/domain boundary; request validation alone is not a domain invariant.

## Use reference catalogs

Use `ReferenceCatalog` for current ISO countries, currencies, languages, and configured application locales. Cities and banks are deployment-specific: configure small lists or bind an application-owned catalog. Do not copy global city or bank datasets into this package.

## Expose API data

Use `MoneyData`, `LengthData`, `CoordinatesData`, `PostalAddressData`, and `ReferenceOption` for stable Spatie Data and generated TypeScript contracts. Do not expose vendor library objects in JSON responses.

## Verify

Test invalid construction, canonical storage, equality, Eloquent round trips, precision and rounding, currency mismatch, missing exchange rates, region-specific phones, current IBAN metadata, locale normalization, and TypeScript DTO shapes. Run the package Pest suite, Pint, PHPStan at maximum strictness, and the dependency audit.
