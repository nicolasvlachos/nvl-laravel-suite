# NVL Primitives

Immutable application value objects, exact money, Eloquent casts, validation rules, currency conversion, and standards-backed reference catalogs for Laravel 12 and 13.

## Purpose and boundaries

Primitives replaces the reusable parts of a traditional application “Core” module without becoming a second application framework. It owns:

- immutable value objects and canonical serialization;
- Eloquent casts for scalar, JSON, and monetary values;
- exact money arithmetic and an exchange-rate provider boundary;
- international phone and IBAN parsing backed by maintained registries;
- current ISO country, currency, and language catalogs;
- application locale, city, and bank option boundaries;
- Spatie Data DTOs and generated TypeScript contracts.

It does not own users, settings, authorization, controllers, application routes, UI components, live exchange-rate HTTP clients, or a global cities/banks database.

## Requirements and installation

- PHP 8.3+
- Laravel 12–13
- `ext-mbstring`, `ext-json`, and `ext-ctype`
- optional `ext-intl` for locale-aware money formatting

The package uses Brick Money/Math, Google's numbering metadata through `giggsey/libphonenumber-for-php`, the SWIFT-derived IBAN registry through `jschaedl/iban-validation`, and Symfony Intl data.

```bash
composer require nvl/primitives:^1.0
php artisan vendor:publish --tag=primitives-config
php artisan vendor:publish --tag=primitives-skills
```

The package has no migrations and works without a database.

## Value objects

| Value object | Canonical storage | Important behavior |
|---|---|---|
| `EmailAddress` | validated address string | normalizes the domain and masks for display |
| `Url` | absolute HTTP(S) string | normalizes scheme, host, and default ports; rejects credentials |
| `CountryCode` | ISO alpha-2 uppercase string | validates current country data |
| `CurrencyCode` | known ISO 4217 uppercase string | name, symbol, and fraction digits |
| `LocaleCode` | normalized BCP 47 core tag | enforces language, script, region, then variants |
| `PhoneNumber` | E.164 string | international parsing and formatting |
| `Iban` | electronic IBAN string | country format/checksum and masking |
| `DateTimeValue` | `Y-m-d\TH:i:s.u\Z` | strict RFC 3339 input and canonical UTC output |
| `Percentage` | exact decimal ratio | exact percentage calculations |
| `Weight` | exact grams | kilograms, pounds, and ounces |
| `Length` | JSON metres | explicit scale and rounding for unit conversion |
| `Coordinates` | JSON string coordinates | WGS84 validation and distance |
| `PostalAddress` | JSON object | international components with an optional postal code |
| `Money` | `{"minor":"…","currency":"…"}` | exact arithmetic and explicit conversion rounding |

Every primitive is immutable, JSON serializable, stringable, and type-safe for equality.

## Eloquent casts

Declare the value-object class directly:

```php
protected function casts(): array
{
    return [
        'email' => EmailAddress::class,
        'phone' => PhoneNumber::class,
        'coordinates' => Coordinates::class,
        'price' => Money::class,
    ];
}
```

Scalar primitives use one string/decimal column. `Coordinates`, `PostalAddress`, and default `Money` use JSON. Null remains null; malformed persisted values fail during hydration.

### Money storage

Default JSON contains only integer minor units and currency:

```json
{"minor":"1999","currency":"EUR"}
```

For an explicitly fixed-currency schema:

```php
protected function casts(): array
{
    return [
        'price_minor' => Money::class.':minor,EUR',
        'price_decimal' => Money::class.':decimal,EUR',
    ];
}
```

Fixed modes accept a `Money` instance or a scalar representing that column's minor/decimal value. The cast rejects a different currency. For variable-currency relational schemas, keep amount and currency in separate application-owned columns and construct `Money` at the model boundary.

SQLite hydrates `DECIMAL` columns as floating-point values. Decimal-mode casts normalize only those database-hydrated floats to the currency's fraction digits; assigning a float remains invalid. Prefer JSON or fixed-currency minor-unit storage when amounts may exceed SQLite's exact numeric range.

## Exact money

Construct with decimal strings or integer minor units—never floats:

```php
use Brick\Math\RoundingMode;
use Nvl\Primitives\Services\MoneyFormatter;
use Nvl\Primitives\ValueObjects\Money;

$price = Money::of('19.99', 'EUR');
$shipping = Money::minor(500, 'EUR');

$total = $price->add($shipping);               // EUR 24.99
$triple = $price->multiply(3);                 // EUR 59.97
$share = $price->divide(3, RoundingMode::HalfUp);

$total->amount();                              // "24.99"
$total->minorAmount();                         // "2499"
$total->currency();                            // CurrencyCode("EUR")

$formatter->format($total, 'de-DE');           // locale-aware with ext-intl
```

Currency is always explicit. An unrepresentable amount or operation throws unless a rounding mode is explicit. Arithmetic across currencies fails rather than converting implicitly. `MoneyFormatter` uses its injected configuration repository and falls back deterministically to `amount currency` when native `ext-intl` is unavailable.

### Currency conversion

Configure deterministic rates:

```php
'exchange_rates' => [
    'implementation' => ConfiguredExchangeRateProvider::class,
    'rates' => [
        'EUR/USD' => '1.10',
        'EUR/BGN' => '1.95583',
    ],
],
```

```php
$usd = $converter->convert(
    money: Money::of('100.00', 'EUR'),
    target: 'USD',
    roundingMode: RoundingMode::HalfUp,
);
```

Rates mean “target major units for one source major unit.” Every direction must be configured explicitly; inverse rates are never inferred. Rate values must be positive decimal strings, not floats or booleans. Optional `as_of` and `max_age_seconds` metadata must be supplied together; future, stale, malformed, and missing rates fail explicitly.

For production rates, implement and bind `ExchangeRateProvider`. It may use a database/API but must return an auditable decimal string and respect the optional effective date. Network calls do not belong in `Money` or `CurrencyConverter`.

## Phones and IBANs

```php
$phone = PhoneNumber::fromRegion('044 668 18 00', 'CH');

(string) $phone;            // +41446681800
$phone->international();    // +41 44 668 18 00
$phone->national();         // 044 668 18 00
$phone->rfc3966();          // tel:+41-44-668-18-00
$phone->region();           // CountryCode("CH")

$iban = Iban::from('DE89 3704 0044 0532 0130 00');

$iban->storageValue();      // DE89370400440532013000
$iban->formatted();
$iban->masked();
$iban->country();           // CountryCode("DE")
```

National phone input requires an explicit region or `primitives.phone.default_region`. Prefer an explicit request-specific region.

## Locale and ISO codes

```php
$country = CountryCode::from('bg');        // BG
$currency = CurrencyCode::from('eur');     // EUR
$locale = LocaleCode::from('zh_hans_cn');  // zh-Hans-CN

$country->name('en');
$currency->symbol('de');
$currency->fractionDigits();
$locale->language();
$locale->script();
$locale->regionCode(); // alpha country or numeric UN M49 region
$locale->region();
```

Locale tags support a language followed by an optional script, optional alpha/numeric region, and valid variant subtags. Extensions and private-use subtags are intentionally outside the package contract. Application-supported locales live under `primitives.locales.supported`.

## Exact quantities and structured values

```php
$discount = Percentage::fromPercent(20);
$discount->decimal();               // "0.2"
$discount->of('125.00');            // "25"
$discount->ofRounded(
    '125.00',
    2,
    RoundingMode::HalfUp,
);                                  // "25.00"

$weight = Weight::kilograms('1.5');
$weight->inGrams();                 // "1500.000"
$weight->inOunces();                // "52.911"

$length = Length::from('12', 'in');
$length->in('cm', 2, RoundingMode::Unnecessary); // "30.48"

$sofia = Coordinates::from('42.6977', '23.3219');
$plovdiv = Coordinates::from('42.1354', '24.7453');
$sofia->distanceTo($plovdiv);       // kilometres
$sofia->googleMapsUrl();
```

`PostalAddress` keeps components separate for country-specific presentation and permits countries without postal codes. `DateTimeValue` accepts only timezone-qualified RFC 3339 input, preserves microseconds, and stores one canonical UTC value; convert only at display boundaries.

## Laravel validation

```php
'email' => [
    'required',
    new ValidPrimitive(EmailAddress::class, 'email address'),
],
'iban' => [
    'nullable',
    new ValidPrimitive(Iban::class, 'IBAN'),
],
'phone' => [
    'required',
    new ValidPhoneNumber('BG'),
],
```

Validation messages ship in English and Bulgarian and use Laravel's translation pipeline. Request validation improves messages, but domain code should still construct the primitive before persistence.

## Reference catalogs

`ReferenceCatalog` returns `ReferenceOption` DTOs:

```php
$countries = $catalog->countries('Bulg', 'en', 20);
$currencies = $catalog->currencies('Euro', 'en');
$languages = $catalog->languages('Bulgarian', 'en');
$locales = $catalog->locales('bg', 'en');
```

Countries, currencies, and languages come from installed Symfony Intl standards data. Locale labels include script and region qualifiers so supported variants remain distinguishable. Cities and banks are deployment-specific, so configure small application lists:

```php
'reference' => [
    'cities' => [
        'sofia-bg' => [
            'label' => 'Sofia',
            'country' => 'BG',
            'region' => 'Sofia City',
        ],
    ],
    'banks' => [
        'UNCRBGSF' => [
            'label' => 'UniCredit Bulbank',
            'country' => 'BG',
        ],
    ],
],
```

Malformed catalog entries and limits outside `1..250` fail immediately. Large or dynamic datasets should use an application service/repository and the same `ReferenceOption` response shape. Primitives intentionally avoids mandatory reference tables, seeders, and unauthenticated routes.

## Spatie Data and TypeScript

The package registers with `nvl/data`. Public contracts include `MoneyData`, `LengthData`, `CoordinatesData`, `PostalAddressData`, and `ReferenceOption`.

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
```

Expose DTOs rather than Brick, libphonenumber, or IBAN vendor objects in JSON APIs.

## Adoption

- Replace existing money values only after auditing every float construction and making rounding explicit.
- Replace custom locale/country/currency enums or casts with validated codes.
- Replace phone/IBAN regex and checksum logic with registry-backed objects.
- Keep settings in `nvl/settings`; Primitives does not own settings.
- Retain application reference tables only when persistence, translation, or domain metadata is necessary.
- Replace suggestion controllers with application controllers calling `ReferenceCatalog` or an application-owned directory.

Audit data before changing cast modes. JSON, decimal, and minor-unit money columns require an explicit migration.

## Quality

```bash
composer install
composer quality
```

The package gate runs Pint, PHPStan at maximum strictness, and isolated Testbench/Pest tests. The monorepo adds dependency analysis, Composer audit, integration tests, and Laravel 12/13 gates.

See [UPGRADING.md](UPGRADING.md), [SECURITY.md](SECURITY.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE).
