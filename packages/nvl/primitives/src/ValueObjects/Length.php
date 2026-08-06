<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Nvl\Primitives\Concerns\CastsAsArray;
use Nvl\Primitives\Contracts\ArrayPrimitive;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;
use Nvl\Primitives\Support\BrickMathCompatibility;

/**
 * Exact non-negative length normalized through metres.
 */
final readonly class Length implements ArrayPrimitive
{
    use CastsAsArray;

    /** @var array<string, string> */
    private const array FACTORS = [
        'mm' => '0.001',
        'cm' => '0.01',
        'm' => '1',
        'km' => '1000',
        'in' => '0.0254',
        'ft' => '0.3048',
        'yd' => '0.9144',
    ];

    private function __construct(
        private BigDecimal $metres,
    ) {}

    /**
     * Create a length from a supported unit.
     */
    public static function from(string|int $value, string $unit = 'm'): self
    {
        $unit = mb_strtolower(trim($unit));
        $factor = self::FACTORS[$unit] ?? null;

        if ($factor === null) {
            throw InvalidPrimitive::for('length', "unit [{$unit}] is not supported.");
        }

        try {
            $metres = BrickMathCompatibility::stripTrailingZeros(
                BigDecimal::of($value)->multipliedBy($factor),
            );
        } catch (MathException $exception) {
            throw InvalidPrimitive::for('length', $exception->getMessage(), $exception);
        }

        if ($metres->isNegative()) {
            throw InvalidPrimitive::for('length', 'the value cannot be negative.');
        }

        return new self($metres);
    }

    /**
     * Create a length from its structured representation.
     *
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): static
    {
        $amount = $value['value'] ?? null;
        $unit = $value['unit'] ?? null;

        if ((! is_string($amount) && ! is_int($amount)) || ! is_string($unit)) {
            throw InvalidPrimitive::for('length', 'value and unit are required.');
        }

        return self::from($amount, $unit);
    }

    /**
     * Convert the length with explicit precision and rounding.
     */
    public function in(string $unit, int $scale, RoundingMode $roundingMode): string
    {
        if ($scale < 0) {
            throw InvalidPrimitive::for('length', 'scale cannot be negative.');
        }

        $unit = mb_strtolower(trim($unit));
        $factor = self::FACTORS[$unit] ?? null;

        if ($factor === null) {
            throw InvalidPrimitive::for('length', "unit [{$unit}] is not supported.");
        }

        try {
            return (string) $this->metres->dividedBy($factor, $scale, $roundingMode);
        } catch (MathException $exception) {
            throw InvalidPrimitive::for('length conversion', $exception->getMessage(), $exception);
        }
    }

    /**
     * Return the canonical metre representation.
     *
     * @return array{value: string, unit: string}
     */
    public function toArray(): array
    {
        return ['value' => (string) $this->metres, 'unit' => 'm'];
    }

    /**
     * Determine whether another primitive represents the same length.
     */
    public function equals(Primitive $other): bool
    {
        return $other instanceof self && $other->metres->isEqualTo($this->metres);
    }

    /**
     * Return the canonical JSON representation.
     *
     * @return array{value: string, unit: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Return the canonical metre representation.
     */
    public function __toString(): string
    {
        return (string) $this->metres.' m';
    }
}
