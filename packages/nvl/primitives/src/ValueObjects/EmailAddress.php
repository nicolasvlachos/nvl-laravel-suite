<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use Nvl\Primitives\Concerns\CastsAsScalar;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Contracts\ScalarPrimitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;

/**
 * Immutable, syntactically validated email address.
 */
final readonly class EmailAddress implements ScalarPrimitive
{
    use CastsAsScalar;

    private function __construct(
        private string $value,
    ) {}

    /**
     * Validate and normalize an email address.
     */
    public static function from(string $value): static
    {
        $value = trim($value);

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidPrimitive::for('email address', 'the value is not a valid address.');
        }

        [$local, $domain] = explode('@', $value, 2);

        return new self($local.'@'.mb_strtolower($domain));
    }

    /**
     * Return null instead of throwing for invalid input.
     */
    public static function tryFrom(string $value): ?self
    {
        try {
            return self::from($value);
        } catch (InvalidPrimitive) {
            return null;
        }
    }

    /**
     * Return the local part before the at sign.
     */
    public function localPart(): string
    {
        return explode('@', $this->value, 2)[0];
    }

    /**
     * Return the normalized domain.
     */
    public function domain(): string
    {
        return explode('@', $this->value, 2)[1];
    }

    /**
     * Return a privacy-preserving display value.
     */
    public function masked(): string
    {
        $local = $this->localPart();
        $length = mb_strlen($local);

        if ($length <= 2) {
            return mb_substr($local, 0, 1).'*@'.$this->domain();
        }

        return mb_substr($local, 0, 1)
            .str_repeat('*', min(6, $length - 2))
            .mb_substr($local, -1)
            .'@'.$this->domain();
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
