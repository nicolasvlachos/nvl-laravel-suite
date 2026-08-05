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
 * Exact percentage stored as a decimal ratio where 1 represents 100%.
 */
final readonly class Percentage implements ScalarPrimitive
{
    use CastsAsScalar;

    private function __construct(
        private BigDecimal $decimal,
    ) {}

    /**
     * Create a percentage from its exact decimal ratio.
     */
    public static function from(string $value): static
    {
        return self::fromDecimal($value);
    }

    /**
     * Create a percentage from its exact decimal ratio.
     */
    public static function fromDecimal(string|int $value): self
    {
        try {
            return new self(BigDecimal::of($value)->strippedOfTrailingZeros());
        } catch (MathException $exception) {
            throw InvalidPrimitive::for('percentage', $exception->getMessage(), $exception);
        }
    }

    /**
     * Create a percentage from an exact percent value.
     */
    public static function fromPercent(string|int $value): self
    {
        try {
            return new self(
                BigDecimal::of($value)
                    ->multipliedBy('0.01')
                    ->strippedOfTrailingZeros(),
            );
        } catch (MathException $exception) {
            throw InvalidPrimitive::for('percentage', $exception->getMessage(), $exception);
        }
    }

    /**
     * Create a zero percentage.
     */
    public static function zero(): self
    {
        return self::fromDecimal(0);
    }

    /**
     * Create a full one-hundred-percent value.
     */
    public static function full(): self
    {
        return self::fromDecimal(1);
    }

    /**
     * Return the exact decimal ratio.
     */
    public function decimal(): string
    {
        return (string) $this->decimal;
    }

    /**
     * Return the exact percent value without implicit rounding.
     */
    public function percent(): string
    {
        return (string) $this->decimal->multipliedBy(100)->strippedOfTrailingZeros();
    }

    /**
     * Return the percent value at an explicit scale and rounding mode.
     */
    public function percentRounded(int $scale, RoundingMode $roundingMode): string
    {
        $this->assertScale($scale);

        return (string) $this->decimal->multipliedBy(100)->toScale($scale, $roundingMode);
    }

    /**
     * Apply the ratio exactly without implicit rounding.
     */
    public function of(string|int $amount): string
    {
        try {
            return (string) BigDecimal::of($amount)
                ->multipliedBy($this->decimal)
                ->strippedOfTrailingZeros();
        } catch (MathException $exception) {
            throw InvalidPrimitive::for('percentage calculation', $exception->getMessage(), $exception);
        }
    }

    /**
     * Apply the ratio at an explicit scale and rounding mode.
     */
    public function ofRounded(
        string|int $amount,
        int $scale,
        RoundingMode $roundingMode,
    ): string {
        $this->assertScale($scale);

        try {
            return (string) BigDecimal::of($amount)
                ->multipliedBy($this->decimal)
                ->toScale($scale, $roundingMode);
        } catch (MathException $exception) {
            throw InvalidPrimitive::for('percentage calculation', $exception->getMessage(), $exception);
        }
    }

    /**
     * Add another exact percentage.
     */
    public function add(self $other): self
    {
        return new self($this->decimal->plus($other->decimal));
    }

    /**
     * Subtract another exact percentage.
     */
    public function subtract(self $other): self
    {
        return new self($this->decimal->minus($other->decimal));
    }

    /**
     * Determine whether the ratio is between zero and one inclusive.
     */
    public function isNormalized(): bool
    {
        return $this->decimal->isGreaterThanOrEqualTo(0)
            && $this->decimal->isLessThanOrEqualTo(1);
    }

    /**
     * Return the exact decimal storage value.
     */
    public function storageValue(): string
    {
        return (string) $this->decimal;
    }

    /**
     * Determine whether another primitive has the same ratio.
     */
    public function equals(Primitive $other): bool
    {
        return $other instanceof self && $other->decimal->isEqualTo($this->decimal);
    }

    /**
     * Return the exact decimal JSON value.
     */
    public function jsonSerialize(): string
    {
        return (string) $this->decimal;
    }

    /**
     * Return the exact percent representation.
     */
    public function __toString(): string
    {
        return $this->percent().'%';
    }

    /**
     * Reject a negative output scale.
     *
     * @phpstan-assert int<0, max> $scale
     */
    private function assertScale(int $scale): void
    {
        if ($scale < 0) {
            throw InvalidPrimitive::for('percentage', 'scale cannot be negative.');
        }
    }
}
