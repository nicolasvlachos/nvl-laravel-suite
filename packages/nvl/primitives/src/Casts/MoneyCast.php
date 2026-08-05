<?php

declare(strict_types=1);

namespace Nvl\Primitives\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;
use Nvl\Primitives\ValueObjects\CurrencyCode;
use Nvl\Primitives\ValueObjects\Money;

/**
 * Persists money as JSON by default, or as fixed-currency minor/decimal values.
 *
 * @implements CastsAttributes<Money|null, Money|array<string, mixed>|string|int|null>
 */
final readonly class MoneyCast implements CastsAttributes
{
    private const string Json = 'json';

    private const string Minor = 'minor';

    private const string Decimal = 'decimal';

    private string $mode;

    private ?string $currency;

    /**
     * Create a JSON or fixed-currency money cast.
     *
     * @param  string  $mode  json, minor, or decimal
     * @param  string|null  $currency  Required for minor and decimal modes
     */
    public function __construct(
        string $mode = self::Json,
        ?string $currency = null,
    ) {
        if (! in_array($mode, [self::Json, self::Minor, self::Decimal], true)) {
            throw new InvalidArgumentException("Unsupported money cast mode [{$mode}].");
        }

        if ($mode !== self::Json && $currency === null) {
            throw new InvalidArgumentException(
                "Money cast mode [{$mode}] requires a fixed currency argument.",
            );
        }

        $this->mode = $mode;
        $this->currency = $currency !== null
            ? (string) CurrencyCode::from($currency)
            : null;
    }

    /**
     * Hydrate money from JSON, minor units, or a decimal amount.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?Money {
        if ($value === null) {
            return null;
        }

        if ($this->mode === self::Minor) {
            if (! is_string($value) && ! is_int($value)) {
                throw new InvalidArgumentException("Attribute [{$key}] must contain minor units.");
            }

            return Money::minor($value, (string) $this->currency);
        }

        if ($this->mode === self::Decimal) {
            if (is_float($value)) {
                if (! is_finite($value)) {
                    throw new InvalidArgumentException("Attribute [{$key}] must contain a finite decimal amount.");
                }

                $value = number_format(
                    $value,
                    CurrencyCode::from((string) $this->currency)->fractionDigits(),
                    '.',
                    '',
                );
            }

            if (! is_string($value) && ! is_int($value)) {
                throw new InvalidArgumentException("Attribute [{$key}] must contain a decimal amount.");
            }

            return Money::of($value, (string) $this->currency);
        }

        $payload = is_string($value)
            ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
            : $value;

        if (! is_array($payload)) {
            throw new InvalidArgumentException("Attribute [{$key}] must contain a money JSON object.");
        }

        /** @var array<string, mixed> $payload */
        return Money::fromArray($payload);
    }

    /**
     * Convert money or accepted input to the configured storage representation.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public function set(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            $money = $value;
        } else {
            if (
                $this->mode !== self::Json
                && (is_string($value) || is_int($value))
            ) {
                $money = $this->mode === self::Minor
                    ? Money::minor($value, (string) $this->currency)
                    : Money::of($value, (string) $this->currency);
            } else {
                if (is_string($value)) {
                    $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                }

                if (! is_array($value)) {
                    throw new InvalidArgumentException(
                        "Attribute [{$key}] must be Money, an accepted scalar, or a money payload.",
                    );
                }

                $payload = [];

                foreach ($value as $payloadKey => $payloadValue) {
                    if (! is_string($payloadKey)) {
                        throw new InvalidArgumentException(
                            "Attribute [{$key}] must contain a money JSON object.",
                        );
                    }

                    $payload[$payloadKey] = $payloadValue;
                }

                $money = Money::fromArray($payload);
            }
        }

        if ($this->currency !== null && (string) $money->currency() !== mb_strtoupper($this->currency)) {
            throw new InvalidArgumentException(
                "Attribute [{$key}] expects [{$this->currency}], [{$money->currency()}] given.",
            );
        }

        return match ($this->mode) {
            self::Minor => $money->minorAmount(),
            self::Decimal => $money->amount(),
            default => json_encode($money->toArray(), JSON_THROW_ON_ERROR),
        };
    }
}
