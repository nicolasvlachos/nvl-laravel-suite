<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Brick\Money\Exception\MoneyException;
use Brick\Money\Money as BrickMoney;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Nvl\Primitives\Casts\MoneyCast;
use Nvl\Primitives\Contracts\ArrayPrimitive;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;
use Nvl\Primitives\Support\BrickMathCompatibility;
use Nvl\Primitives\Support\BrickMoneyCompatibility;

/**
 * Immutable, exact monetary amount backed by Brick Money.
 */
final readonly class Money implements ArrayPrimitive
{
    private function __construct(
        private BrickMoney $money,
    ) {}

    /**
     * Create money from a major-unit decimal amount and explicit currency.
     */
    public static function of(
        string|int $amount,
        CurrencyCode|string $currency,
        ?RoundingMode $roundingMode = null,
    ): self {
        $code = $currency instanceof CurrencyCode ? (string) $currency : $currency;
        $roundingMode ??= BrickMathCompatibility::unnecessary();

        try {
            return new self(BrickMoney::of($amount, $code, roundingMode: $roundingMode));
        } catch (MoneyException|MathException $exception) {
            throw InvalidPrimitive::for('money', $exception->getMessage(), $exception);
        }
    }

    /**
     * Create money from integer minor units and an explicit currency.
     */
    public static function minor(
        string|int $minorAmount,
        CurrencyCode|string $currency,
    ): self {
        $code = $currency instanceof CurrencyCode ? (string) $currency : $currency;

        try {
            return new self(BrickMoney::ofMinor($minorAmount, $code));
        } catch (MoneyException|MathException $exception) {
            throw InvalidPrimitive::for('money', $exception->getMessage(), $exception);
        }
    }

    /**
     * Create a zero amount in an explicit currency.
     */
    public static function zero(CurrencyCode|string $currency): self
    {
        return self::minor(0, $currency);
    }

    /**
     * Create money from its canonical or API representation.
     *
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): static
    {
        $currency = $value['currency'] ?? null;

        if (! is_string($currency)) {
            throw InvalidPrimitive::for('money', 'a currency code is required.');
        }

        $minor = $value['minor'] ?? null;
        $amount = $value['amount'] ?? null;

        if ($minor !== null && ! is_string($minor) && ! is_int($minor)) {
            throw InvalidPrimitive::for('money', 'minor must be an integer string or integer.');
        }

        if ($amount !== null && ! is_string($amount) && ! is_int($amount)) {
            throw InvalidPrimitive::for('money', 'amount must be an exact decimal string or integer.');
        }

        if ($minor === null && $amount === null) {
            throw InvalidPrimitive::for('money', 'either minor or amount is required.');
        }

        $fromMinor = $minor !== null ? self::minor($minor, $currency) : null;
        $fromAmount = $amount !== null ? self::of($amount, $currency) : null;

        if (
            $fromMinor instanceof self
            && $fromAmount instanceof self
            && ! $fromMinor->equals($fromAmount)
        ) {
            throw InvalidPrimitive::for('money', 'minor and amount describe different values.');
        }

        if ($fromMinor instanceof self) {
            return $fromMinor;
        }

        if ($fromAmount instanceof self) {
            return $fromAmount;
        }

        throw InvalidPrimitive::for('money', 'either minor or amount is required.');
    }

    /**
     * Return the Eloquent cast configured by mode and optional fixed currency.
     *
     * @param  list<string>  $arguments
     * @return MoneyCast
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new MoneyCast(
            mode: $arguments[0] ?? 'json',
            currency: $arguments[1] ?? null,
        );
    }

    /**
     * Return the exact major-unit decimal amount.
     */
    public function amount(): string
    {
        return (string) $this->money->getAmount();
    }

    /**
     * Return the exact integer minor-unit amount.
     */
    public function minorAmount(): string
    {
        return (string) $this->money->getMinorAmount();
    }

    /**
     * Return the validated currency code.
     */
    public function currency(): CurrencyCode
    {
        return CurrencyCode::from($this->money->getCurrency()->getCurrencyCode());
    }

    /**
     * Add money in the same currency.
     */
    public function add(self $other): self
    {
        try {
            return new self($this->money->plus($other->money));
        } catch (MoneyException|MathException $exception) {
            throw InvalidPrimitive::for('money arithmetic', $exception->getMessage(), $exception);
        }
    }

    /**
     * Subtract money in the same currency.
     */
    public function subtract(self $other): self
    {
        try {
            return new self($this->money->minus($other->money));
        } catch (MoneyException|MathException $exception) {
            throw InvalidPrimitive::for('money arithmetic', $exception->getMessage(), $exception);
        }
    }

    /**
     * Multiply the amount, rejecting inexact results unless rounding is supplied.
     */
    public function multiply(
        string|int $multiplier,
        ?RoundingMode $roundingMode = null,
    ): self {
        $roundingMode ??= BrickMathCompatibility::unnecessary();

        try {
            return new self($this->money->multipliedBy($multiplier, $roundingMode));
        } catch (MoneyException|MathException $exception) {
            throw InvalidPrimitive::for('money arithmetic', $exception->getMessage(), $exception);
        }
    }

    /**
     * Divide the amount, rejecting inexact results unless rounding is supplied.
     */
    public function divide(
        string|int $divisor,
        ?RoundingMode $roundingMode = null,
    ): self {
        $roundingMode ??= BrickMathCompatibility::unnecessary();

        try {
            return new self($this->money->dividedBy($divisor, $roundingMode));
        } catch (MoneyException|MathException $exception) {
            throw InvalidPrimitive::for('money arithmetic', $exception->getMessage(), $exception);
        }
    }

    /**
     * Convert at an explicit rate and explicit rounding mode.
     */
    public function convert(
        CurrencyCode|string $currency,
        string $rate,
        RoundingMode $roundingMode,
    ): self {
        $code = $currency instanceof CurrencyCode ? (string) $currency : $currency;

        try {
            if (! BigDecimal::of($rate)->isPositive()) {
                throw InvalidPrimitive::for('money conversion', 'the exchange rate must be positive.');
            }

            return new self($this->money->convertedTo(
                $code,
                $rate,
                roundingMode: $roundingMode,
            ));
        } catch (MoneyException|MathException $exception) {
            throw InvalidPrimitive::for('money conversion', $exception->getMessage(), $exception);
        }
    }

    /**
     * Determine whether the amount is zero.
     */
    public function isZero(): bool
    {
        return $this->money->isZero();
    }

    /**
     * Determine whether the amount is positive.
     */
    public function isPositive(): bool
    {
        return $this->money->isPositive();
    }

    /**
     * Determine whether the amount is negative.
     */
    public function isNegative(): bool
    {
        return $this->money->isNegative();
    }

    /**
     * Return the absolute amount.
     */
    public function absolute(): self
    {
        return new self($this->money->abs());
    }

    /**
     * Return the negated amount.
     */
    public function negate(): self
    {
        return new self($this->money->negated());
    }

    /**
     * Compare money in the same currency.
     */
    public function compare(self $other): int
    {
        try {
            return $this->money->compareTo($other->money);
        } catch (MoneyException|MathException $exception) {
            throw InvalidPrimitive::for('money comparison', $exception->getMessage(), $exception);
        }
    }

    /**
     * Allocate the amount proportionally while preserving every minor unit.
     *
     * @param  list<string|int>  $ratios
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        try {
            return $this->wrapMoneyList(
                BrickMoneyCompatibility::allocate($this->money, $ratios),
            );
        } catch (MoneyException|MathException|\InvalidArgumentException $exception) {
            throw InvalidPrimitive::for('money allocation', $exception->getMessage(), $exception);
        }
    }

    /**
     * Split the amount equally while preserving every minor unit.
     *
     * @return list<self>
     */
    public function split(int $parts): array
    {
        if ($parts < 1) {
            throw InvalidPrimitive::for('money split', 'the number of parts must be positive.');
        }

        try {
            return $this->wrapMoneyList(
                BrickMoneyCompatibility::split($this->money, $parts),
            );
        } catch (MoneyException|MathException|\InvalidArgumentException $exception) {
            throw InvalidPrimitive::for('money split', $exception->getMessage(), $exception);
        }
    }

    /**
     * Return the underlying Brick Money value.
     */
    public function toBrick(): BrickMoney
    {
        return $this->money;
    }

    /**
     * @return list<self>
     */
    private function wrapMoneyList(mixed $values): array
    {
        if (! is_array($values)) {
            throw InvalidPrimitive::for('money allocation', 'Brick Money returned an invalid allocation result.');
        }

        $wrapped = [];

        foreach ($values as $value) {
            if (! $value instanceof BrickMoney) {
                throw InvalidPrimitive::for('money allocation', 'Brick Money returned an invalid allocation value.');
            }

            $wrapped[] = new self($value);
        }

        return $wrapped;
    }

    /**
     * Return the canonical persistence representation.
     *
     * @return array{minor: string, currency: string}
     */
    public function toArray(): array
    {
        return [
            'minor' => $this->minorAmount(),
            'currency' => (string) $this->currency(),
        ];
    }

    /**
     * Determine whether another primitive has the same amount and currency.
     */
    public function equals(Primitive $other): bool
    {
        return $other instanceof self
            && $other->money->getCurrency()->isEqualTo($this->money->getCurrency())
            && $other->money->isEqualTo($this->money);
    }

    /**
     * Return the canonical JSON persistence representation.
     *
     * @return array{minor: string, currency: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Return Brick Money's amount and currency representation.
     */
    public function __toString(): string
    {
        return (string) $this->money;
    }
}
