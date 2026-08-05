<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use Nvl\Primitives\Concerns\CastsAsScalar;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Contracts\ScalarPrimitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;

/**
 * Transport-safe opaque identifier supporting integer, UUID, ULID, and string keys.
 */
final readonly class Identifier implements ScalarPrimitive
{
    use CastsAsScalar;

    private function __construct(private string $value) {}

    public static function from(string|int $value): static
    {
        $value = trim((string) $value);

        if ($value === ''
            || strlen($value) > 255
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1) {
            throw InvalidPrimitive::for(
                'identifier',
                'use 1-255 ASCII letters, numbers, dots, underscores, colons, or hyphens.',
            );
        }

        return new self($value);
    }

    public static function tryFrom(string|int $value): ?self
    {
        try {
            return self::from($value);
        } catch (InvalidPrimitive) {
            return null;
        }
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
