<?php

declare(strict_types=1);

use Nvl\Primitives\Casts\ArrayPrimitiveCast;
use Nvl\Primitives\Casts\MoneyCast;
use Nvl\Primitives\Casts\ScalarPrimitiveCast;
use Nvl\Primitives\Exceptions\InvalidPrimitive;
use Nvl\Primitives\Tests\Fixtures\PrimitiveTestModel;
use Nvl\Primitives\ValueObjects\Coordinates;
use Nvl\Primitives\ValueObjects\EmailAddress;
use Nvl\Primitives\ValueObjects\Money;

it('treats only actual null as an absent scalar primitive', function (): void {
    $cast = new ScalarPrimitiveCast(EmailAddress::class);
    $model = new PrimitiveTestModel;

    expect($cast->get($model, 'email', null, []))->toBeNull()
        ->and($cast->set($model, 'email', null, []))->toBeNull()
        ->and(fn () => $cast->get($model, 'email', '', []))->toThrow(InvalidPrimitive::class)
        ->and(fn () => $cast->set($model, 'email', '', []))->toThrow(InvalidPrimitive::class);
});

it('treats empty JSON strings as malformed array primitives', function (): void {
    $cast = new ArrayPrimitiveCast(Coordinates::class);
    $model = new PrimitiveTestModel;

    expect($cast->get($model, 'coordinates', null, []))->toBeNull()
        ->and(fn () => $cast->get($model, 'coordinates', '', []))->toThrow(JsonException::class)
        ->and(fn () => $cast->set($model, 'coordinates', '', []))->toThrow(JsonException::class);
});

it('accepts scalar values in fixed-currency money modes', function (): void {
    $model = new PrimitiveTestModel;
    $minor = new MoneyCast('minor', 'EUR');
    $decimal = new MoneyCast('decimal', 'EUR');

    expect($minor->set($model, 'money_minor', 1234, []))->toBe('1234')
        ->and($minor->set($model, 'money_minor', '1234', []))->toBe('1234')
        ->and($decimal->set($model, 'money_decimal', '12.34', []))->toBe('12.34')
        ->and($minor->get($model, 'money_minor', '1234', [])?->amount())->toBe('12.34');
});

it('normalizes database-hydrated decimal floats without accepting input floats', function (): void {
    $model = new PrimitiveTestModel;
    $decimal = new MoneyCast('decimal', 'EUR');

    expect($decimal->get($model, 'money_decimal', 12.34, [])?->amount())->toBe('12.34')
        ->and(fn () => $decimal->get($model, 'money_decimal', INF, []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $decimal->set($model, 'money_decimal', 12.34, []))
        ->toThrow(InvalidArgumentException::class);
});

it('validates fixed-currency cast configuration immediately', function (): void {
    expect(fn () => new MoneyCast('minor', 'invalid'))
        ->toThrow(InvalidPrimitive::class);
});

it('writes canonical money JSON and rejects empty storage', function (): void {
    $cast = new MoneyCast;
    $model = new PrimitiveTestModel;

    expect($cast->set($model, 'money', Money::of('12.34', 'EUR'), []))
        ->toBe('{"minor":"1234","currency":"EUR"}')
        ->and($cast->get($model, 'money', null, []))->toBeNull()
        ->and(fn () => $cast->get($model, 'money', '', []))->toThrow(JsonException::class);
});

it('covers scalar cast hydration and storage boundaries', function (): void {
    $cast = new ScalarPrimitiveCast(EmailAddress::class);
    $model = new PrimitiveTestModel;
    $email = EmailAddress::from('person@example.com');

    expect($cast->get($model, 'email', 'person@example.com', []))->toEqual($email)
        ->and($cast->set($model, 'email', $email, []))->toBe('person@example.com')
        ->and($cast->set($model, 'email', 'Person@EXAMPLE.COM', []))
        ->toBe('Person@example.com')
        ->and(fn () => $cast->get($model, 'email', [], []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $cast->set($model, 'email', [], []))
        ->toThrow(InvalidArgumentException::class);
});

it('covers array cast hydration and storage boundaries', function (): void {
    $cast = new ArrayPrimitiveCast(Coordinates::class);
    $model = new PrimitiveTestModel;
    $coordinates = Coordinates::from('42.6977', '23.3219');
    $payload = ['latitude' => '42.6977', 'longitude' => '23.3219'];

    expect($cast->get($model, 'coordinates', json_encode($payload, JSON_THROW_ON_ERROR), []))
        ->toEqual($coordinates)
        ->and($cast->set($model, 'coordinates', null, []))->toBeNull()
        ->and($cast->set($model, 'coordinates', $coordinates, []))
        ->toBe('{"latitude":"42.6977000","longitude":"23.3219000"}')
        ->and($cast->set($model, 'coordinates', $payload, []))
        ->toBe('{"latitude":"42.6977000","longitude":"23.3219000"}')
        ->and($cast->set($model, 'coordinates', json_encode($payload, JSON_THROW_ON_ERROR), []))
        ->toBe('{"latitude":"42.6977000","longitude":"23.3219000"}')
        ->and(fn () => $cast->get($model, 'coordinates', '"invalid"', []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $cast->set($model, 'coordinates', 42, []))
        ->toThrow(InvalidArgumentException::class);
});

it('covers every money cast mode and rejection boundary', function (): void {
    $model = new PrimitiveTestModel;
    $json = new MoneyCast;
    $minor = new MoneyCast('minor', 'EUR');
    $decimal = new MoneyCast('decimal', 'EUR');

    expect($minor->get($model, 'money_minor', null, []))->toBeNull()
        ->and($decimal->get($model, 'money_decimal', 12, [])?->amount())->toBe('12.00')
        ->and($json->get($model, 'money', ['minor' => '1234', 'currency' => 'EUR'], [])?->amount())
        ->toBe('12.34')
        ->and($json->set($model, 'money', ['amount' => '12.34', 'currency' => 'EUR'], []))
        ->toBe('{"minor":"1234","currency":"EUR"}')
        ->and($json->set(
            $model,
            'money',
            '{"amount":"12.34","currency":"EUR"}',
            [],
        ))->toBe('{"minor":"1234","currency":"EUR"}')
        ->and($decimal->set($model, 'money_decimal', Money::of('12.34', 'EUR'), []))
        ->toBe('12.34')
        ->and(fn () => new MoneyCast('unsupported'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MoneyCast('minor'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $minor->get($model, 'money_minor', 1.5, []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $decimal->get($model, 'money_decimal', [], []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $json->get($model, 'money', '"invalid"', []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $json->set($model, 'money', 42, []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $json->set($model, 'money', [0 => 'invalid'], []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $minor->set($model, 'money_minor', Money::of('1.00', 'USD'), []))
        ->toThrow(InvalidArgumentException::class);
});
