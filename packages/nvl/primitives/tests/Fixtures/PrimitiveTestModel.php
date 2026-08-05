<?php

declare(strict_types=1);

namespace Nvl\Primitives\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Primitives\ValueObjects\Coordinates;
use Nvl\Primitives\ValueObjects\DateTimeValue;
use Nvl\Primitives\ValueObjects\EmailAddress;
use Nvl\Primitives\ValueObjects\Identifier;
use Nvl\Primitives\ValueObjects\Length;
use Nvl\Primitives\ValueObjects\Money;
use Nvl\Primitives\ValueObjects\PhoneNumber;
use Nvl\Primitives\ValueObjects\PostalAddress;
use Nvl\Primitives\ValueObjects\TimezoneId;

/**
 * Exercises Castable integration against a real Eloquent model.
 *
 * @property EmailAddress|null $email
 * @property PhoneNumber|null $phone
 * @property Money|null $money
 * @property Money|null $money_minor
 * @property Money|null $money_decimal
 * @property Coordinates|null $coordinates
 * @property PostalAddress|null $postal_address
 * @property DateTimeValue|null $date_time
 * @property TimezoneId|null $timezone
 * @property Identifier|null $external_id
 * @property Length|null $length
 */
final class PrimitiveTestModel extends Model
{
    protected $table = 'primitive_test_models';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email', 'phone', 'money', 'money_minor', 'money_decimal',
        'coordinates', 'postal_address', 'date_time', 'timezone',
        'external_id', 'length',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email' => EmailAddress::class,
            'phone' => PhoneNumber::class,
            'money' => Money::class,
            'money_minor' => Money::class.':minor,EUR',
            'money_decimal' => Money::class.':decimal,EUR',
            'coordinates' => Coordinates::class,
            'postal_address' => PostalAddress::class,
            'date_time' => DateTimeValue::class,
            'timezone' => TimezoneId::class,
            'external_id' => Identifier::class,
            'length' => Length::class,
        ];
    }
}
