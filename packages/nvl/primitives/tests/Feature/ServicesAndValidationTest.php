<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Validator;
use Nvl\Primitives\Contracts\ExchangeRateProvider;
use Nvl\Primitives\Exceptions\ExchangeRateStale;
use Nvl\Primitives\Exceptions\ExchangeRateUnavailable;
use Nvl\Primitives\Exceptions\InvalidPrimitive;
use Nvl\Primitives\Providers\PrimitivesServiceProvider;
use Nvl\Primitives\Rules\ValidPhoneNumber;
use Nvl\Primitives\Rules\ValidPrimitive;
use Nvl\Primitives\Services\ConfiguredExchangeRateProvider;
use Nvl\Primitives\Services\CurrencyConverter;
use Nvl\Primitives\Services\MoneyFormatter;
use Nvl\Primitives\Services\ReferenceCatalog;
use Nvl\Primitives\Support\BrickMathCompatibility;
use Nvl\Primitives\ValueObjects\CurrencyCode;
use Nvl\Primitives\ValueObjects\EmailAddress;
use Nvl\Primitives\ValueObjects\Money;

it('requires configured exchange rates in the requested direction', function (): void {
    config()->set('primitives.exchange_rates.rates', ['EUR/USD' => '1.10']);
    $provider = app(ConfiguredExchangeRateProvider::class);

    expect($provider->rate(CurrencyCode::from('EUR'), CurrencyCode::from('USD')))->toBe('1.10')
        ->and(fn () => $provider->rate(CurrencyCode::from('USD'), CurrencyCode::from('EUR')))
        ->toThrow(ExchangeRateUnavailable::class);
});

it('validates configured exchange-rate types and freshness metadata', function (
    mixed $configured,
    string $exception,
): void {
    config()->set('primitives.exchange_rates.rates', ['EUR/USD' => $configured]);
    $provider = app(ConfiguredExchangeRateProvider::class);

    expect(fn () => $provider->rate(
        CurrencyCode::from('EUR'),
        CurrencyCode::from('USD'),
        new DateTimeImmutable('2026-07-29T12:00:00Z'),
    ))->toThrow($exception);
})->with([
    'boolean rate' => [true, InvalidPrimitive::class],
    'floating-point rate' => [1.1, InvalidPrimitive::class],
    'missing maximum age' => [[
        'rate' => '1.10',
        'as_of' => '2026-07-29T11:59:30Z',
    ], InvalidPrimitive::class],
    'invalid effective instant' => [[
        'rate' => '1.10',
        'as_of' => 'tomorrow',
        'max_age_seconds' => 60,
    ], InvalidPrimitive::class],
    'future effective instant' => [[
        'rate' => '1.10',
        'as_of' => '2026-07-29T12:00:01Z',
        'max_age_seconds' => 60,
    ], ExchangeRateUnavailable::class],
    'stale rate' => [[
        'rate' => '1.10',
        'as_of' => '2026-07-29T11:58:59Z',
        'max_age_seconds' => 60,
    ], ExchangeRateStale::class],
]);

it('requires callers to choose conversion rounding', function (): void {
    config()->set('primitives.exchange_rates.rates', ['EUR/USD' => '1.005']);

    expect(app(CurrencyConverter::class)->convert(
        Money::of('1.00', 'EUR'),
        'USD',
        BrickMathCompatibility::halfUp(),
    )->amount())->toBe('1.01');
});

it('formats money through an injected service with a deterministic fallback', function (): void {
    $formatter = app(MoneyFormatter::class);
    $formatted = $formatter->format(Money::of('19.99', 'EUR'), 'en');

    if (extension_loaded('intl')) {
        expect($formatted)->toContain('19.99');
    } else {
        expect($formatted)->toBe('19.99 EUR');
    }
});

it('builds distinct locale labels and fails fast on invalid reference config', function (): void {
    config()->set('primitives.locales.supported', ['en', 'en-US', 'zh-Hans-CN', 'es-419']);
    $catalog = app(ReferenceCatalog::class);

    expect(array_column($catalog->locales(displayLocale: 'en'), 'label'))->toBe([
        'English',
        'English (United States)',
        'Chinese (Simplified, China)',
        'Spanish (419)',
    ])->and($catalog->locales(displayLocale: 'en')[2]->metadata)->toBe([
        'script' => 'Hans',
        'region' => 'CN',
    ])->and(fn () => $catalog->countries(limit: 0))->toThrow(InvalidPrimitive::class);

    config()->set('primitives.reference.banks', ['INVALID' => ['country' => 'BG']]);

    expect(fn () => $catalog->banks())->toThrow(InvalidPrimitive::class);
});

it('validates the primitive rule class at construction time', function (): void {
    expect(fn () => new ValidPrimitive(stdClass::class))
        ->toThrow(InvalidArgumentException::class);
});

it('uses translated package validation messages', function (): void {
    app()->setLocale('bg');

    $validator = Validator::make([
        'email' => 'invalid',
        'phone' => '123',
    ], [
        'email' => [new ValidPrimitive(EmailAddress::class, 'имейл адрес')],
        'phone' => [new ValidPhoneNumber('BG')],
    ]);

    expect($validator->errors()->first('email'))->toContain('валидна стойност')
        ->and($validator->errors()->first('phone'))->toContain('телефонен номер');
});

it('constructs services through their explicit config dependency', function (): void {
    $repository = app(Repository::class);

    expect(new ConfiguredExchangeRateProvider($repository))->toBeInstanceOf(ConfiguredExchangeRateProvider::class)
        ->and(new ReferenceCatalog($repository))->toBeInstanceOf(ReferenceCatalog::class)
        ->and(new MoneyFormatter($repository))->toBeInstanceOf(MoneyFormatter::class);
});

it('covers configured exchange-rate success and rejection boundaries', function (): void {
    $provider = app(ConfiguredExchangeRateProvider::class);
    $eur = CurrencyCode::from('EUR');
    $usd = CurrencyCode::from('USD');

    expect($provider->rate($eur, $eur))->toBe('1');

    config()->set('primitives.exchange_rates.rates', 'invalid');
    expect(fn () => $provider->rate($eur, $usd))->toThrow(InvalidPrimitive::class);

    config()->set('primitives.exchange_rates.rates', []);
    expect(fn () => $provider->rate($eur, $usd))->toThrow(ExchangeRateUnavailable::class);

    config()->set('primitives.exchange_rates.rates', [
        'EUR/USD' => [
            'rate' => '1.10',
            'as_of' => '2026-07-29T11:59:30Z',
            'max_age_seconds' => 60,
        ],
    ]);
    expect($provider->rate($eur, $usd, new DateTimeImmutable('2026-07-29T12:00:00Z')))
        ->toBe('1.10');

    config()->set('primitives.exchange_rates.rates', ['EUR/USD' => '-1']);
    expect(fn () => $provider->rate($eur, $usd))->toThrow(InvalidPrimitive::class);

    config()->set('primitives.exchange_rates.rates', ['EUR/USD' => 'invalid']);
    expect(fn () => $provider->rate($eur, $usd))->toThrow(InvalidPrimitive::class);

    config()->set('primitives.exchange_rates.rates', [
        'EUR/USD' => [
            'rate' => '1.10',
            'as_of' => '2026-07-29T11:59:30Z',
            'max_age_seconds' => -1,
        ],
    ]);
    expect(fn () => $provider->rate($eur, $usd, new DateTimeImmutable('2026-07-29T12:00:00Z')))
        ->toThrow(InvalidPrimitive::class);
});

it('covers converter and formatter configuration boundaries', function (): void {
    config()->set('primitives.exchange_rates.rates', ['EUR/USD' => '1.1']);
    $converter = app(CurrencyConverter::class);
    $formatter = app(MoneyFormatter::class);
    $formatted = $formatter->format(Money::of('1.00', 'EUR'), 'en');

    expect($converter->convert(
        Money::of('1.00', 'EUR'),
        CurrencyCode::from('USD'),
        BrickMathCompatibility::halfUp(),
    )->amount())->toBe('1.10');

    if (extension_loaded('intl')) {
        expect($formatted)->toContain('1.00');
    } else {
        expect($formatted)->toBe('1.00 EUR');
    }

    config()->set('primitives.money.default_locale', ['invalid']);
    expect(fn () => $formatter->format(Money::of('1.00', 'EUR')))
        ->toThrow(InvalidPrimitive::class)
        ->and(fn () => $formatter->format(Money::of('1.00', 'EUR'), 'invalid-locale'))
        ->toThrow(InvalidPrimitive::class);
});

it('covers every reference catalog family and malformed configuration', function (): void {
    $catalog = app(ReferenceCatalog::class);
    config()->set('primitives.locales.supported', ['en']);
    config()->set('primitives.reference.cities', [
        'sofia-bg' => [
            'label' => 'Sofia',
            'country' => 'BG',
            'population' => 1_200_000,
            'capital' => true,
        ],
    ]);

    expect($catalog->countries('BG', 'en', 5)[0]->code)->toBe('BG')
        ->and($catalog->currencies('EUR', 'en', 5)[0]->code)->toBe('EUR')
        ->and($catalog->languages('Bulgarian', 'en', 5)[0]->code)->toBe('bg')
        ->and($catalog->cities('sofia')[0]->metadata)->toBe([
            'country' => 'BG',
            'population' => 1_200_000,
            'capital' => true,
        ]);

    config()->set('primitives.locales.supported', 'invalid');
    expect(fn () => $catalog->locales())->toThrow(InvalidPrimitive::class);

    config()->set('primitives.locales.supported', [42]);
    expect(fn () => $catalog->locales())->toThrow(InvalidPrimitive::class);

    config()->set('primitives.reference.cities', 'invalid');
    expect(fn () => $catalog->cities())->toThrow(InvalidPrimitive::class);

    config()->set('primitives.reference.cities', [
        'sofia' => ['label' => 'Sofia', 'nested' => []],
    ]);
    expect(fn () => $catalog->cities())->toThrow(InvalidPrimitive::class)
        ->and(fn () => $catalog->countries(limit: 251))->toThrow(InvalidPrimitive::class)
        ->and(fn () => $catalog->countries(displayLocale: 'invalid-locale'))
        ->toThrow(InvalidPrimitive::class);
});

it('covers validation rule success and non-scalar rejection', function (): void {
    $valid = Validator::make([
        'email' => 'person@example.com',
        'phone' => '+41446681800',
    ], [
        'email' => [new ValidPrimitive(EmailAddress::class, 'email address')],
        'phone' => [new ValidPhoneNumber],
    ]);
    $invalid = Validator::make([
        'email' => [],
        'phone' => [],
    ], [
        'email' => [new ValidPrimitive(EmailAddress::class, 'email address')],
        'phone' => [new ValidPhoneNumber],
    ]);

    expect($valid->passes())->toBeTrue()
        ->and($invalid->fails())->toBeTrue()
        ->and($invalid->errors()->has('email'))->toBeTrue()
        ->and($invalid->errors()->has('phone'))->toBeTrue();
});

it('rejects an invalid configured exchange-rate implementation', function (): void {
    config()->set('primitives.exchange_rates.implementation', stdClass::class);
    $provider = new PrimitivesServiceProvider($this->app);

    expect(fn () => $provider->register())->toThrow(InvalidArgumentException::class);
    expect(app(ExchangeRateProvider::class))->toBeInstanceOf(ConfiguredExchangeRateProvider::class);
});
