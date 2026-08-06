<?php

declare(strict_types=1);

use Nvl\Primitives\Data\CoordinatesData;
use Nvl\Primitives\Data\LengthData;
use Nvl\Primitives\Data\PostalAddressData;
use Nvl\Primitives\Exceptions\InvalidPrimitive;
use Nvl\Primitives\Support\BrickMathCompatibility;
use Nvl\Primitives\Support\BrickMoneyCompatibility;
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

it('uses one canonical UTC representation for instants', function (): void {
    $instant = DateTimeValue::from('2026-07-27T12:00:00.123456+03:00');

    expect($instant->storageValue())->toBe('2026-07-27T09:00:00.123456Z')
        ->and($instant->jsonSerialize())->toBe('2026-07-27T09:00:00.123456Z')
        ->and((string) $instant)->toBe('2026-07-27T09:00:00.123456Z')
        ->and(DateTimeValue::from('2026-07-27t09:00:00.123456z')->equals($instant))
        ->toBeTrue()
        ->and(DateTimeValue::from($instant->storageValue())->equals($instant))->toBeTrue();
});

it('rejects non-rfc3339 date-time input', function (string $value): void {
    expect(fn () => DateTimeValue::from($value))->toThrow(InvalidPrimitive::class);
})->with([
    'relative input' => 'tomorrow',
    'timezone-less input' => '2026-07-27 12:00:00',
    'invalid calendar date' => '2026-02-30T12:00:00Z',
    'date only' => '2026-07-27',
]);

it('parses bcp 47 core language tags in canonical order', function (
    string $input,
    string $canonical,
    ?string $script,
    ?string $region,
): void {
    $locale = LocaleCode::from($input);

    expect((string) $locale)->toBe($canonical)
        ->and($locale->script())->toBe($script)
        ->and($locale->regionCode())->toBe($region);
})->with([
    ['zh_hans_cn', 'zh-Hans-CN', 'Hans', 'CN'],
    ['de-1901', 'de-1901', null, null],
    ['es-419', 'es-419', null, '419'],
    ['sl-rozaj-biske', 'sl-rozaj-biske', null, null],
]);

it('rejects malformed or out-of-order locale tags', function (string $value): void {
    expect(fn () => LocaleCode::from($value))->toThrow(InvalidPrimitive::class);
})->with([
    'script after region' => 'en-US-Latn',
    'duplicate region' => 'en-US-GB',
    'duplicate script' => 'en-Latn-Cyrl',
    'extension' => 'en-u-ca-gregory',
    'private use' => 'en-x-private',
    'empty subtag' => 'en--US',
]);

it('keeps url normalization idempotent and rejects credentials', function (): void {
    $url = Url::from('HTTPS://Example.COM:443/path?q=1#fragment');

    expect(Url::from((string) $url)->equals($url))->toBeTrue()
        ->and(fn () => Url::from('https://user:secret@example.com/path'))
        ->toThrow(InvalidPrimitive::class);
});

it('serializes money with one canonical storage shape', function (): void {
    $money = Money::of('19.99', 'EUR');

    expect($money->toArray())->toBe([
        'minor' => '1999',
        'currency' => 'EUR',
    ])->and(Money::fromArray([
        'minor' => '1999',
        'amount' => '19.99',
        'currency' => 'EUR',
    ])->equals($money))->toBeTrue()
        ->and(fn () => Money::fromArray([
            'minor' => '999',
            'amount' => '19.99',
            'currency' => 'EUR',
        ]))->toThrow(InvalidPrimitive::class);
});

it('requires explicit currencies and conversion rounding in public contracts', function (): void {
    expect((new ReflectionMethod(Money::class, 'of'))->getNumberOfRequiredParameters())->toBe(2)
        ->and((new ReflectionMethod(Money::class, 'minor'))->getNumberOfRequiredParameters())->toBe(2)
        ->and((new ReflectionMethod(Money::class, 'zero'))->getNumberOfRequiredParameters())->toBe(1)
        ->and((new ReflectionMethod(Money::class, 'convert'))->getNumberOfRequiredParameters())->toBe(3);
});

it('normalizes arithmetic failures to the primitive exception', function (): void {
    expect(fn () => Money::of('1.00', 'EUR')->divide(3))
        ->toThrow(InvalidPrimitive::class)
        ->and(fn () => Money::of('1.00', 'EUR')->convert('USD', '1.005', BrickMathCompatibility::unnecessary()))
        ->toThrow(InvalidPrimitive::class);
});

it('keeps percentage construction and arithmetic exact', function (): void {
    $percentage = Percentage::fromPercent('1.2345678901234');

    expect($percentage->decimal())->toBe('0.012345678901234')
        ->and($percentage->percent())->toBe('1.2345678901234')
        ->and(Percentage::fromPercent('10')->of('3'))->toBe('0.3')
        ->and(Percentage::fromPercent('20')->ofRounded('125.00', 2, BrickMathCompatibility::halfUp()))
        ->toBe('25.00');
});

it('names length explicitly and requires explicit conversion precision', function (): void {
    $length = Length::from('12', 'in');

    expect($length->in('cm', 2, BrickMathCompatibility::unnecessary()))->toBe('30.48')
        ->and(LengthData::fromLength($length)->toArray())->toBe([
            'value' => '0.3048',
            'unit' => 'm',
        ]);
});

it('allows international addresses without a postal code', function (): void {
    $address = new PostalAddress(
        line1: '1 Main Street',
        line2: null,
        locality: 'Hong Kong',
        administrativeArea: null,
        postalCode: null,
        country: CountryCode::from('HK'),
    );

    expect($address->postalCode)->toBeNull()
        ->and($address->toArray()['postalCode'])->toBeNull()
        ->and(fn () => PostalAddress::fromArray([
            'line1' => '1 Main Street',
            'line2' => 123,
            'locality' => 'Sofia',
            'country' => 'BG',
        ]))->toThrow(InvalidPrimitive::class);
});

it('uses the configured phone region consistently in fallible parsing', function (): void {
    config()->set('primitives.phone.default_region', 'CH');

    expect((string) PhoneNumber::tryFrom('044 668 18 00'))->toBe('+41446681800');
});

it('exposes complete country and currency code contracts', function (): void {
    $country = CountryCode::from('bg');
    $currency = CurrencyCode::from('eur');

    expect(CountryCode::tryFrom('BG')?->equals($country))->toBeTrue()
        ->and(CountryCode::tryFrom('ZZ'))->toBeNull()
        ->and($country->name('en'))->toBe('Bulgaria')
        ->and($country->storageValue())->toBe('BG')
        ->and($country->jsonSerialize())->toBe('BG')
        ->and((string) $country)->toBe('BG')
        ->and($country->equals(CountryCode::from('DE')))->toBeFalse()
        ->and(CurrencyCode::tryFrom('EUR')?->equals($currency))->toBeTrue()
        ->and(CurrencyCode::tryFrom('BTC'))->toBeNull()
        ->and($currency->name('en'))->toBe('Euro')
        ->and($currency->symbol('en'))->toBe('€')
        ->and($currency->fractionDigits())->toBe(2)
        ->and($currency->toBrick()->getCurrencyCode())->toBe('EUR')
        ->and($currency->storageValue())->toBe('EUR')
        ->and($currency->jsonSerialize())->toBe('EUR')
        ->and((string) $currency)->toBe('EUR')
        ->and($currency->equals(CurrencyCode::from('USD')))->toBeFalse();
});

it('exposes complete email URL identifier and timezone contracts', function (): void {
    $email = EmailAddress::from('a@example.com');
    $url = Url::from('http://Example.com:8080/path');
    $identifier = Identifier::from(42);
    $timezone = TimezoneId::from('Europe/Sofia');

    expect($email->localPart())->toBe('a')
        ->and($email->domain())->toBe('example.com')
        ->and($email->masked())->toBe('a*@example.com')
        ->and($email->storageValue())->toBe('a@example.com')
        ->and($email->jsonSerialize())->toBe('a@example.com')
        ->and((string) $email)->toBe('a@example.com')
        ->and(EmailAddress::tryFrom('invalid'))->toBeNull()
        ->and($email->equals(EmailAddress::from('b@example.com')))->toBeFalse()
        ->and($url->scheme())->toBe('http')
        ->and($url->host())->toBe('example.com')
        ->and($url->isSecure())->toBeFalse()
        ->and($url->storageValue())->toBe('http://example.com:8080/path')
        ->and($url->jsonSerialize())->toBe('http://example.com:8080/path')
        ->and(Url::tryFrom('/relative'))->toBeNull()
        ->and($url->equals(Url::from('https://example.com')))->toBeFalse()
        ->and($identifier->storageValue())->toBe('42')
        ->and($identifier->jsonSerialize())->toBe('42')
        ->and((string) $identifier)->toBe('42')
        ->and(Identifier::tryFrom('../invalid'))->toBeNull()
        ->and($identifier->equals(Identifier::from(43)))->toBeFalse()
        ->and($timezone->timezone()->getName())->toBe('Europe/Sofia')
        ->and($timezone->storageValue())->toBe('Europe/Sofia')
        ->and($timezone->jsonSerialize())->toBe('Europe/Sofia')
        ->and((string) $timezone)->toBe('Europe/Sofia')
        ->and(TimezoneId::tryFrom('Europe/Nowhere'))->toBeNull()
        ->and($timezone->equals(TimezoneId::from('UTC')))->toBeFalse();
});

it('exposes complete phone and IBAN contracts', function (): void {
    $phone = PhoneNumber::fromRegion('044 668 18 00', 'CH');
    $iban = Iban::from('DE89 3704 0044 0532 0130 00');

    expect($phone->region()?->storageValue())->toBe('CH')
        ->and($phone->international())->toContain('+41')
        ->and($phone->national())->toContain('044')
        ->and($phone->rfc3966())->toStartWith('tel:+41')
        ->and($phone->storageValue())->toBe('+41446681800')
        ->and($phone->jsonSerialize())->toBe('+41446681800')
        ->and(PhoneNumber::tryFrom('invalid', 'CH'))->toBeNull()
        ->and($phone->equals(PhoneNumber::fromRegion('044 668 18 01', 'CH')))->toBeFalse()
        ->and($iban->checksum())->toBe('89')
        ->and($iban->bban())->toBe('370400440532013000')
        ->and($iban->bankIdentifier())->toBe('37040044')
        ->and($iban->formatted())->toContain('DE89')
        ->and($iban->storageValue())->toBe('DE89370400440532013000')
        ->and($iban->jsonSerialize())->toBe('DE89370400440532013000')
        ->and((string) $iban)->toContain('DE89')
        ->and(Iban::tryFrom('invalid'))->toBeNull()
        ->and($iban->equals(Iban::from('GB82 WEST 1234 5698 7654 32')))->toBeFalse();
});

it('exposes complete coordinate and address contracts', function (): void {
    $coordinates = Coordinates::fromString('42.6977, 23.3219');
    $address = new PostalAddress(
        line1: '1 Main Street',
        line2: 'Floor 2',
        locality: 'Sofia',
        administrativeArea: 'Sofia City',
        postalCode: '1000',
        country: CountryCode::from('BG'),
    );

    expect($coordinates->latitude())->toBe('42.6977000')
        ->and($coordinates->longitude())->toBe('23.3219000')
        ->and($coordinates->toArray())->toBe([
            'latitude' => '42.6977000',
            'longitude' => '23.3219000',
        ])
        ->and($coordinates->jsonSerialize())->toBe($coordinates->toArray())
        ->and((string) $coordinates)->toBe('42.6977000,23.3219000')
        ->and($coordinates->googleMapsUrl()->host())->toBe('www.google.com')
        ->and($coordinates->equals(Coordinates::from('0', '0')))->toBeFalse()
        ->and(CoordinatesData::fromCoordinates($coordinates)->toArray())->toBe([
            'latitude' => '42.6977000',
            'longitude' => '23.3219000',
        ])
        ->and($address->jsonSerialize())->toBe($address->toArray())
        ->and((string) $address)->toBe('1 Main Street, Floor 2, 1000 Sofia, Sofia City, BG')
        ->and($address->equals(PostalAddress::fromArray($address->toArray())))->toBeTrue()
        ->and(PostalAddressData::fromAddress($address)->toArray())->toBe($address->toArray());
});

it('rejects malformed coordinate and address payloads', function (): void {
    expect(fn () => Coordinates::from('91', '0'))->toThrow(InvalidPrimitive::class)
        ->and(fn () => Coordinates::from('0', '181'))->toThrow(InvalidPrimitive::class)
        ->and(fn () => Coordinates::fromString('1,2,3'))->toThrow(InvalidPrimitive::class)
        ->and(fn () => Coordinates::fromArray(['latitude' => '1']))
        ->toThrow(InvalidPrimitive::class)
        ->and(fn () => new PostalAddress('', null, 'Sofia', null, null, CountryCode::from('BG')))
        ->toThrow(InvalidPrimitive::class)
        ->and(fn () => PostalAddress::fromArray(['line1' => '1', 'locality' => 'Sofia']))
        ->toThrow(InvalidPrimitive::class)
        ->and(fn () => PostalAddress::fromArray([
            'line1' => '1',
            'line2' => 2,
            'locality' => 'Sofia',
            'country' => 'BG',
        ]))->toThrow(InvalidPrimitive::class);
});

it('exposes complete length weight and percentage contracts', function (): void {
    $length = Length::fromArray(['value' => '1.5', 'unit' => 'm']);
    $weight = Weight::pounds('2');
    $percentage = Percentage::fromDecimal('0.2');

    expect($length->toArray())->toBe(['value' => '1.5', 'unit' => 'm'])
        ->and($length->jsonSerialize())->toBe($length->toArray())
        ->and((string) $length)->toBe('1.5 m')
        ->and($length->equals(Length::from('150', 'cm')))->toBeTrue()
        ->and($weight->inGrams())->toBe('907.185')
        ->and($weight->inKilograms())->toBe('0.907')
        ->and($weight->inPounds())->toBe('2.000')
        ->and($weight->inOunces())->toBe('32.000')
        ->and($weight->add(Weight::grams(1))->inGrams())->toBe('908.185')
        ->and($weight->subtract(Weight::grams(1))->inGrams())->toBe('906.185')
        ->and($weight->storageValue())->toBe('907.18474')
        ->and($weight->jsonSerialize())->toBe('907.18474')
        ->and((string) $weight)->toBe('907.185 g')
        ->and($weight->equals(Weight::ounces('32')))->toBeTrue()
        ->and($percentage->decimal())->toBe('0.2')
        ->and($percentage->percent())->toBe('20')
        ->and($percentage->percentRounded(2, BrickMathCompatibility::unnecessary()))->toBe('20.00')
        ->and($percentage->add(Percentage::fromPercent(5))->percent())->toBe('25')
        ->and($percentage->subtract(Percentage::fromPercent(5))->percent())->toBe('15')
        ->and($percentage->isNormalized())->toBeTrue()
        ->and(Percentage::fromPercent(150)->isNormalized())->toBeFalse()
        ->and(Percentage::zero()->decimal())->toBe('0')
        ->and(Percentage::full()->decimal())->toBe('1')
        ->and($percentage->storageValue())->toBe('0.2')
        ->and($percentage->jsonSerialize())->toBe('0.2')
        ->and((string) $percentage)->toBe('20%')
        ->and($percentage->equals(Percentage::fromPercent(20)))->toBeTrue();
});

it('rejects malformed measurement operations', function (): void {
    expect(fn () => Length::fromArray(['value' => 1.5, 'unit' => 'm']))
        ->toThrow(InvalidPrimitive::class)
        ->and(fn () => Length::from('1', 'invalid'))->toThrow(InvalidPrimitive::class)
        ->and(fn () => Length::from('invalid'))->toThrow(InvalidPrimitive::class)
        ->and(fn () => Length::from('1')->in('m', -1, BrickMathCompatibility::unnecessary()))
        ->toThrow(InvalidPrimitive::class)
        ->and(fn () => Length::from('1')->in('invalid', 2, BrickMathCompatibility::unnecessary()))
        ->toThrow(InvalidPrimitive::class)
        ->and(fn () => Weight::grams('invalid'))->toThrow(InvalidPrimitive::class)
        ->and(fn () => Weight::grams('-1'))->toThrow(InvalidPrimitive::class)
        ->and(fn () => Weight::grams(1)->subtract(Weight::grams(2)))
        ->toThrow(InvalidPrimitive::class)
        ->and(fn () => Weight::grams(1)->inGrams(-1))->toThrow(InvalidPrimitive::class)
        ->and(fn () => Percentage::fromDecimal('invalid'))->toThrow(InvalidPrimitive::class)
        ->and(fn () => Percentage::fromPercent('invalid'))->toThrow(InvalidPrimitive::class)
        ->and(fn () => Percentage::full()->percentRounded(-1, BrickMathCompatibility::halfUp()))
        ->toThrow(InvalidPrimitive::class);
});

it('exposes complete money operations and normalizes their failures', function (): void {
    $money = Money::of('10.00', 'EUR');

    expect($money->subtract(Money::of('2.00', 'EUR'))->amount())->toBe('8.00')
        ->and($money->isZero())->toBeFalse()
        ->and($money->isPositive())->toBeTrue()
        ->and($money->isNegative())->toBeFalse()
        ->and(Money::zero('EUR')->isZero())->toBeTrue()
        ->and($money->compare(Money::of('9.00', 'EUR')))->toBe(1)
        ->and($money->toBrick()->getAmount()->__toString())->toBe('10.00')
        ->and($money->jsonSerialize())->toBe(['minor' => '1000', 'currency' => 'EUR'])
        ->and((string) $money)->toBe('EUR 10.00')
        ->and(fn () => $money->subtract(Money::of('1.00', 'USD')))
        ->toThrow(InvalidPrimitive::class)
        ->and(fn () => $money->compare(Money::of('1.00', 'USD')))
        ->toThrow(InvalidPrimitive::class)
        ->and(fn () => $money->allocate([]))->toThrow(InvalidPrimitive::class)
        ->and(fn () => $money->convert('USD', '0', BrickMathCompatibility::halfUp()))
        ->toThrow(InvalidPrimitive::class);
});

it('bridges the legacy Brick Money allocation signatures', function (): void {
    $legacyMoney = new class
    {
        /**
         * @return list<int>
         */
        public function allocate(int ...$ratios): array
        {
            return $ratios;
        }

        /**
         * @return list<int>
         */
        public function split(int $parts): array
        {
            return array_fill(0, $parts, 1);
        }
    };

    expect(BrickMoneyCompatibility::allocate($legacyMoney, [1, '2', 3], false))
        ->toBe([1, 2, 3])
        ->and(BrickMoneyCompatibility::split($legacyMoney, 3, false))
        ->toBe([1, 1, 1])
        ->and(fn () => BrickMoneyCompatibility::allocate($legacyMoney, ['1.5'], false))
        ->toThrow(InvalidArgumentException::class);
});
