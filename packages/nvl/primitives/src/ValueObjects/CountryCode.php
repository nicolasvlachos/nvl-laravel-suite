<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use Nvl\Primitives\Concerns\CastsAsScalar;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Contracts\ScalarPrimitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;
use Symfony\Component\Intl\Countries;

/**
 * Valid ISO 3166-1 alpha-2 country code.
 */
final readonly class CountryCode implements ScalarPrimitive
{
    use CastsAsScalar;

    private function __construct(
        private string $value,
    ) {}

    public static function from(string $value): static
    {
        $value = mb_strtoupper(trim($value));

        if (! Countries::exists($value)) {
            throw InvalidPrimitive::for('country code', "[{$value}] is not an ISO 3166-1 alpha-2 code.");
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
        return Countries::getName($this->value, $displayLocale);
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
