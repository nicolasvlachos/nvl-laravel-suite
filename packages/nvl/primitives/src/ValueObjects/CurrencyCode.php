<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use Brick\Money\Currency;
use Brick\Money\Exception\UnknownCurrencyException;
use Nvl\Primitives\Concerns\CastsAsScalar;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Contracts\ScalarPrimitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;
use Symfony\Component\Intl\Currencies;

/**
 * Valid ISO 4217 currency code with current fraction metadata.
 */
final readonly class CurrencyCode implements ScalarPrimitive
{
    use CastsAsScalar;

    private function __construct(
        private string $value,
    ) {}

    public static function from(string $value): static
    {
        $value = mb_strtoupper(trim($value));

        try {
            Currency::of($value);
        } catch (UnknownCurrencyException) {
            throw InvalidPrimitive::for('currency code', "[{$value}] is not a known ISO 4217 currency.");
        }

        return new self($value);
    }

    public static function tryFrom(string $value): ?self
    {
        try {
            return self::from($value);
        } catch (InvalidPrimitive) {
            return null;
        }
    }

    public function name(?string $displayLocale = null): string
    {
        return Currencies::getName($this->value, $displayLocale);
    }

    public function symbol(?string $displayLocale = null): string
    {
        return Currencies::getSymbol($this->value, $displayLocale);
    }

    public function fractionDigits(): int
    {
        return Currencies::getFractionDigits($this->value);
    }

    public function toBrick(): Currency
    {
        return Currency::of($this->value);
    }

    public function storageValue(): string
    {
        return $this->value;
    }

    public function equals(Primitive $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
