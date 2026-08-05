<?php

declare(strict_types=1);

use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Validator;
use Nvl\Primitives\Data\LengthData;
use Nvl\Primitives\Data\MoneyData;
use Nvl\Primitives\Exceptions\ExchangeRateStale;
use Nvl\Primitives\Exceptions\InvalidPrimitive;
use Nvl\Primitives\Rules\ValidPhoneNumber;
use Nvl\Primitives\Rules\ValidPrimitive;
use Nvl\Primitives\Services\CurrencyConverter;
use Nvl\Primitives\Services\ReferenceCatalog;
use Nvl\Primitives\Tests\Fixtures\PrimitiveTestModel;
use Nvl\Primitives\ValueObjects\Coordinates;
use Nvl\Primitives\ValueObjects\CountryCode;
use Nvl\Primitives\ValueObjects\CurrencyCode;
use Nvl\Primitives\ValueObjects\DateTimeValue;
use Nvl\Primitives\ValueObjects\EmailAddress;
use Nvl\Primitives\ValueObjects\Iban;
use Nvl\Primitives\ValueObjects\Identifier;
use Nvl\Primitives\ValueObjects\Length;
use Nvl\Primitives\ValueObjects\LocaleCode;
use Nvl\Primitives\ValueObjects\Money;
use Nvl\Primitives\ValueObjects\Percentage;
use Nvl\Primitives\ValueObjects\PhoneNumber;
use Nvl\Primitives\ValueObjects\PostalAddress;
use Nvl\Primitives\ValueObjects\TimezoneId;
use Nvl\Primitives\ValueObjects\Url;
use Nvl\Primitives\ValueObjects\Weight;

it('normalizes and validates scalar identity primitives', function (): void {
    expect((string) EmailAddress::from(' Person@EXAMPLE.COM '))->toBe('Person@example.com')
        ->and(EmailAddress::from('person@example.com')->masked())->toBe('p****n@example.com')
        ->and((string) Url::from('HTTPS://Example.COM:443/path?q=1'))->toBe('https://example.com/path?q=1')
        ->and((string) CountryCode::from('bg'))->toBe('BG')
        ->and((string) CurrencyCode::from('eur'))->toBe('EUR')
        ->and((string) LocaleCode::from('zh_hans_cn'))->toBe('zh-Hans-CN');

    expect(fn () => EmailAddress::from('not-an-email'))->toThrow(InvalidPrimitive::class)
        ->and(fn () => Url::from('/relative'))->toThrow(InvalidPrimitive::class)
        ->and(fn () => CountryCode::from('ZZ'))->toThrow(InvalidPrimitive::class);
});

it('uses maintained numbering and iban registries', function (): void {
    $phone = PhoneNumber::fromRegion('044 668 18 00', 'CH');
    $iban = Iban::from('DE89 3704 0044 0532 0130 00');

    expect((string) $phone)->toBe('+41446681800')
        ->and($phone->region()?->storageValue())->toBe('CH')
        ->and($phone->rfc3966())->toStartWith('tel:+41')
        ->and($iban->storageValue())->toBe('DE89370400440532013000')
        ->and($iban->country()->storageValue())->toBe('DE')
        ->and($iban->masked())->toEndWith('3000');
});

it('performs exact money arithmetic and configured conversion', function (): void {
    config()->set('primitives.exchange_rates.rates', [
        'EUR/USD' => '1.10',
    ]);

    $price = Money::of('19.99', 'EUR');
    $tax = Money::of('4.00', 'EUR');
    $converted = app(CurrencyConverter::class)->convert(
        $price->add($tax),
        'USD',
        RoundingMode::HalfUp,
    );

    expect($price->minorAmount())->toBe('1999')
        ->and($price->add($tax)->amount())->toBe('23.99')
        ->and($price->multiply(3)->amount())->toBe('59.97')
        ->and($converted->amount())->toBe('26.39')
        ->and($converted->currency()->storageValue())->toBe('USD')
        ->and(MoneyData::fromMoney($price)->toArray())->toMatchArray([
            'amount' => '19.99',
            'minor' => '1999',
            'currency' => 'EUR',
        ]);

    expect(fn () => Money::of('1.005', 'EUR'))->toThrow(InvalidPrimitive::class)
        ->and(Money::of('1.005', 'EUR', RoundingMode::HalfUp)->amount())->toBe('1.01');
});

it('allocates, splits, compares, and negates exact money safely', function (): void {
    $amount = Money::of('10.00', 'EUR');
    $allocated = $amount->allocate([1, 2, 1]);
    $split = Money::of('-1.00', 'EUR')->split(3);

    expect(array_map(
        static fn (Money $money): string => $money->amount(),
        $allocated,
    ))->toBe(['2.50', '5.00', '2.50'])
        ->and(array_sum(array_map(
            static fn (Money $money): int => (int) $money->minorAmount(),
            $split,
        )))->toBe(-100)
        ->and($amount->negate()->isNegative())->toBeTrue()
        ->and($amount->negate()->absolute()->equals($amount))->toBeTrue()
        ->and(fn () => $amount->add(Money::of('1.00', 'USD')))
        ->toThrow(InvalidPrimitive::class)
        ->and(fn () => $amount->split(0))->toThrow(InvalidPrimitive::class);
});

it('provides exact measurement and structured primitives', function (): void {
    $sofia = Coordinates::from('42.6977', '23.3219');
    $plovdiv = Coordinates::from('42.1354', '24.7453');
    $address = new PostalAddress(
        line1: '1 Vitosha Blvd',
        line2: null,
        locality: 'Sofia',
        administrativeArea: 'Sofia City',
        postalCode: '1000',
        country: CountryCode::from('BG'),
    );

    expect($sofia->distanceTo($plovdiv))->toBeBetween(125.0, 140.0)
        ->and(Percentage::fromPercent(20)->ofRounded('125.00', 2, RoundingMode::HalfUp))
        ->toBe('25.00')
        ->and(Weight::kilograms('1.5')->inGrams())->toBe('1500.000')
        ->and(Weight::ounces('16')->inPounds())->toBe('1.000')
        ->and($address->toArray()['country'])->toBe('BG')
        ->and(DateTimeValue::from('2026-07-27T12:00:00+03:00')->jsonSerialize())
        ->toContain('09:00:00');
});

it('normalizes timezone, opaque identifier, address, and exact length measurements', function (): void {
    $length = Length::from('12', 'in');
    $address = new PostalAddress(
        line1: '  1 Main Street  ',
        line2: ' ',
        locality: ' Sofia ',
        administrativeArea: null,
        postalCode: ' 1000 ',
        country: CountryCode::from('BG'),
    );

    expect((string) TimezoneId::from('Europe/Sofia'))->toBe('Europe/Sofia')
        ->and((string) Identifier::from('01ARZ3NDEKTSV4RRFFQ69G5FAV'))
        ->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->and((string) Identifier::from(42))->toBe('42')
        ->and($length->in('cm', 2, RoundingMode::Unnecessary))->toBe('30.48')
        ->and(LengthData::fromLength($length)->toArray())
        ->toBe(['value' => '0.3048', 'unit' => 'm'])
        ->and($address->line1)->toBe('1 Main Street')
        ->and($address->line2)->toBeNull()
        ->and(fn () => TimezoneId::from('Europe/Nowhere'))->toThrow(InvalidPrimitive::class)
        ->and(fn () => Identifier::from('../unsafe'))->toThrow(InvalidPrimitive::class)
        ->and(fn () => Length::from('-1', 'm'))->toThrow(InvalidPrimitive::class);
});

it('fails deterministically for explicitly stale configured exchange rates', function (): void {
    config()->set('primitives.exchange_rates.rates', [
        'EUR/USD' => [
            'rate' => '1.10',
            'as_of' => '2026-01-01T00:00:00Z',
            'max_age_seconds' => 60,
        ],
    ]);

    expect(fn () => app(CurrencyConverter::class)->convert(
        Money::of('1.00', 'EUR'),
        'USD',
        RoundingMode::HalfUp,
        new DateTimeImmutable('2026-01-02T00:00:00Z'),
    ))->toThrow(ExchangeRateStale::class);
});

it('round trips primitives through Eloquent casts', function (): void {
    config()->set('primitives.phone.default_region', 'CH');

    $model = PrimitiveTestModel::query()->create([
        'email' => 'Person@EXAMPLE.com',
        'phone' => '044 668 18 00',
        'money' => Money::of('12.34', 'EUR'),
        'money_minor' => 1234,
        'money_decimal' => '12.34',
        'coordinates' => Coordinates::from('42.6977', '23.3219'),
        'postal_address' => new PostalAddress(
            line1: '1 Main Street',
            line2: null,
            locality: 'Hong Kong',
            administrativeArea: null,
            postalCode: null,
            country: CountryCode::from('HK'),
        ),
        'date_time' => '2026-07-27T12:00:00.123456+03:00',
        'timezone' => 'Europe/Sofia',
        'external_id' => 42,
        'length' => Length::from('2.5', 'm'),
    ])->fresh();

    expect($model?->email)->toBeInstanceOf(EmailAddress::class)
        ->and((string) $model?->email)->toBe('Person@example.com')
        ->and($model?->phone)->toBeInstanceOf(PhoneNumber::class)
        ->and($model?->money)->toBeInstanceOf(Money::class)
        ->and($model?->money->minorAmount())->toBe('1234')
        ->and($model?->money_minor)->toBeInstanceOf(Money::class)
        ->and($model?->money_minor->minorAmount())->toBe('1234')
        ->and($model?->money_decimal)->toBeInstanceOf(Money::class)
        ->and($model?->money_decimal->amount())->toBe('12.34')
        ->and($model?->coordinates)->toBeInstanceOf(Coordinates::class)
        ->and($model?->postal_address)->toBeInstanceOf(PostalAddress::class)
        ->and($model?->postal_address->postalCode)->toBeNull()
        ->and($model?->date_time)->toBeInstanceOf(DateTimeValue::class)
        ->and((string) $model?->date_time)->toBe('2026-07-27T09:00:00.123456Z')
        ->and($model?->timezone)->toBeInstanceOf(TimezoneId::class)
        ->and($model?->external_id)->toBeInstanceOf(Identifier::class)
        ->and($model?->length)->toBeInstanceOf(Length::class);
});

it('exposes searchable ISO and application reference catalogs', function (): void {
    config()->set('primitives.locales.supported', ['en', 'bg-BG']);
    config()->set('primitives.reference.banks', [
        'UNCRBGSF' => ['label' => 'UniCredit Bulbank', 'country' => 'BG'],
    ]);
    $catalog = app(ReferenceCatalog::class);

    expect($catalog->countries('Bulg', 'en', 5))->toHaveCount(1)
        ->and($catalog->countries('Bulg', 'en', 5)[0]->code)->toBe('BG')
        ->and($catalog->currencies('Euro', 'en', 5)[0]->code)->toBe('EUR')
        ->and($catalog->locales('Bulgarian', 'en', 5)[0]->code)->toBe('bg-BG')
        ->and($catalog->banks('credit')[0]->metadata['country'])->toBe('BG');
});

it('integrates primitives with Laravel validation', function (): void {
    $validator = Validator::make([
        'email' => 'invalid',
        'phone' => '123',
    ], [
        'email' => [new ValidPrimitive(EmailAddress::class, 'email address')],
        'phone' => [new ValidPhoneNumber('BG')],
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('email'))->toBeTrue()
        ->and($validator->errors()->has('phone'))->toBeTrue();
});
