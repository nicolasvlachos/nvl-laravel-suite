<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use DateTimeZone;
use Nvl\Primitives\Concerns\CastsAsScalar;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Contracts\ScalarPrimitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;

/**
 * Canonical IANA timezone identifier suitable for persistence.
 */
final readonly class TimezoneId implements ScalarPrimitive
{
    use CastsAsScalar;

    private function __construct(private string $value) {}

    public static function from(string $value): static
    {
        $value = trim($value);

        if (! in_array($value, DateTimeZone::listIdentifiers(), true)) {
            throw InvalidPrimitive::for('timezone', "[{$value}] is not an IANA timezone identifier.");
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

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone($this->value);
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
