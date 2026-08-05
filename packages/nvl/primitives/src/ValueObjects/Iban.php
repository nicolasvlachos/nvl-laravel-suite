<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use Iban\Validation\Iban as BaseIban;
use Iban\Validation\Validator;
use Nvl\Primitives\Concerns\CastsAsScalar;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Contracts\ScalarPrimitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;

/**
 * Valid ISO 13616 International Bank Account Number.
 */
final readonly class Iban implements ScalarPrimitive
{
    use CastsAsScalar;

    private function __construct(
        private string $electronic,
    ) {}

    public static function from(string $value): static
    {
        $iban = new BaseIban($value);

        if (! (new Validator)->validate($iban)) {
            throw InvalidPrimitive::for('IBAN', 'the country format or checksum is invalid.');
        }

        return new self($iban->format(BaseIban::FORMAT_ELECTRONIC));
    }

    public static function tryFrom(string $value): ?self
    {
        try {
            return self::from($value);
        } catch (InvalidPrimitive) {
            return null;
        }
    }

    public function country(): CountryCode
    {
        return CountryCode::from($this->base()->countryCode());
    }

    public function checksum(): string
    {
        return $this->base()->checksum();
    }

    public function bban(): string
    {
        return $this->base()->bban();
    }

    public function bankIdentifier(): string
    {
        return $this->base()->bbanBankIdentifier();
    }

    public function formatted(): string
    {
        return $this->base()->format(BaseIban::FORMAT_PRINT);
    }

    public function masked(): string
    {
        return $this->base()->format(BaseIban::FORMAT_ANONYMIZED);
    }

    public function storageValue(): string
    {
        return $this->electronic;
    }

    public function equals(Primitive $other): bool
    {
        return $other instanceof self && $other->electronic === $this->electronic;
    }

    public function jsonSerialize(): string
    {
        return $this->electronic;
    }

    public function __toString(): string
    {
        return $this->formatted();
    }

    private function base(): BaseIban
    {
        return new BaseIban($this->electronic);
    }
}
