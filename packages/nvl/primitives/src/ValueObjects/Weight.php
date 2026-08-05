<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Nvl\Primitives\Concerns\CastsAsScalar;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Contracts\ScalarPrimitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;

/**
 * Exact non-negative mass stored canonically in grams.
 */
final readonly class Weight implements ScalarPrimitive
{
    use CastsAsScalar;

    private function __construct(
        private BigDecimal $grams,
    ) {}

    /**
     * Create a weight from canonical grams.
     */
    public static function from(string $value): static
    {
        return self::grams($value);
    }

    /**
     * Create a weight from grams.
     */
    public static function grams(string|int $value): self
    {
        return self::fromUnit($value, '1');
    }

    /**
     * Create a weight from kilograms.
     */
    public static function kilograms(string|int $value): self
    {
        return self::fromUnit($value, '1000');
    }

    /**
     * Create a weight from pounds.
     */
    public static function pounds(string|int $value): self
    {
        return self::fromUnit($value, '453.59237');
    }

    /**
     * Create a weight from ounces.
     */
    public static function ounces(string|int $value): self
    {
        return self::fromUnit($value, '28.349523125');
    }

    /**
     * Return grams rounded to the requested scale.
     */
    public function inGrams(int $scale = 3): string
    {
        $this->assertScale($scale);

        return (string) $this->grams->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * Return kilograms rounded to the requested scale.
     */
    public function inKilograms(int $scale = 3): string
    {
        $this->assertScale($scale);

        return (string) $this->grams->dividedBy(1000, $scale, RoundingMode::HalfUp);
    }

    /**
     * Return pounds rounded to the requested scale.
     */
    public function inPounds(int $scale = 3): string
    {
        $this->assertScale($scale);

        return (string) $this->grams->dividedBy('453.59237', $scale, RoundingMode::HalfUp);
    }

    /**
     * Return ounces rounded to the requested scale.
     */
    public function inOunces(int $scale = 3): string
    {
        $this->assertScale($scale);

        return (string) $this->grams->dividedBy('28.349523125', $scale, RoundingMode::HalfUp);
    }

    /**
     * Add another exact weight.
     */
    public function add(self $other): self
    {
        return new self($this->grams->plus($other->grams));
    }

    /**
     * Subtract another weight without permitting a negative result.
     */
    public function subtract(self $other): self
    {
        $result = $this->grams->minus($other->grams);

        if ($result->isNegative()) {
            throw InvalidPrimitive::for('weight', 'a subtraction cannot produce a negative weight.');
        }

        return new self($result);
    }

    /**
     * Return the canonical gram storage value.
     */
    public function storageValue(): string
    {
        return (string) $this->grams;
    }

    /**
     * Determine whether another primitive represents the same mass.
     */
    public function equals(Primitive $other): bool
    {
        return $other instanceof self && $other->grams->isEqualTo($this->grams);
    }

    /**
     * Return the canonical gram JSON value.
     */
    public function jsonSerialize(): string
    {
        return (string) $this->grams;
    }

    /**
     * Return a human-readable gram representation.
     */
    public function __toString(): string
    {
        return $this->inGrams().' g';
    }

    private static function fromUnit(string|int $value, string $factor): self
    {
        try {
            $grams = BigDecimal::of($value)->multipliedBy($factor)->strippedOfTrailingZeros();
        } catch (MathException $exception) {
            throw InvalidPrimitive::for('weight', $exception->getMessage(), $exception);
        }

        if ($grams->isNegative()) {
            throw InvalidPrimitive::for('weight', 'the value cannot be negative.');
        }

        return new self($grams);
    }

    /**
     * Reject invalid decimal scales before invoking Brick Math.
     *
     * @phpstan-assert int<0, max> $scale
     */
    private function assertScale(int $scale): void
    {
        if ($scale < 0) {
            throw InvalidPrimitive::for('weight', 'scale cannot be negative.');
        }
    }
}
