# Upgrading NVL Primitives

## Upgrading to 1.0

Version 1.0 is database-free and contains reusable values only.

1. Replace application-specific Core values with the matching immutable value objects.
2. Construct money from decimal strings or minor units with an explicit currency, never floats.
3. Store variable-currency money canonically as `{"minor":"…","currency":"…"}`; use `MoneyData` when an API also needs the major-unit amount.
4. Select JSON, fixed-currency minor, or fixed-currency decimal storage explicitly for `MoneyCast`.
5. Pass a `RoundingMode` to every currency conversion and configure exchange rates in each required direction.
6. Use `MoneyFormatter` for display instead of formatting through the value object.
7. Use `Length` and `LengthData` for length values; unit conversion requires an explicit scale and rounding mode.
8. Supply timezone-qualified RFC 3339 strings to `DateTimeValue`; its storage, JSON, and string form are canonical UTC with microseconds.
9. Treat `PostalAddress::postalCode` as nullable and preserve that nullability in API and TypeScript contracts.
10. Bind `ExchangeRateProvider` when conversion is used.
11. Keep application catalogs, repositories, orchestration, and database reference tables outside this package.
12. Verify cast round trips before converting existing columns.

Changed canonical serialization is a data migration and must be handled by the consuming application.
