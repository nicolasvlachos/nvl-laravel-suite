<?php

declare(strict_types=1);

namespace Nvl\Csv\Transformers;

use InvalidArgumentException;
use Stringable;

/**
 * Numeric transformation utilities for CSV data.
 */
final class NumericTransformer extends CSVTransformer
{
    private ?int $precision = null;

    private ?float $multiplier = null;

    private ?float $divisor = null;

    private ?float $addition = null;

    private ?float $subtraction = null;

    private bool $absolute = false;

    private ?float $minValue = null;

    private ?float $maxValue = null;

    private ?float $defaultValue = null;

    private bool $round = false;

    private bool $floor = false;

    private bool $ceil = false;

    /**
     * Set decimal precision.
     *
     * @param  int  $precision  Number of decimal places
     * @return self Transformer instance
     */
    public function precision(int $precision): self
    {
        $this->precision = $precision;

        return $this;
    }

    /**
     * Multiply by value.
     *
     * @param  float  $value  Multiplier value
     * @return self Transformer instance
     */
    public function multiply(float $value): self
    {
        $this->multiplier = $value;

        return $this;
    }

    /**
     * Divide by value.
     *
     * @param  float  $value  Divisor value
     * @return self Transformer instance
     *
     * @throws InvalidArgumentException If divisor is zero
     */
    public function divide(float $value): self
    {
        if ($value === 0.0) {
            throw new InvalidArgumentException('Cannot divide by zero');
        }
        $this->divisor = $value;

        return $this;
    }

    /**
     * Add value.
     *
     * @param  float  $value  Value to add
     * @return self Transformer instance
     */
    public function add(float $value): self
    {
        $this->addition = $value;

        return $this;
    }

    /**
     * Subtract value.
     *
     * @param  float  $value  Value to subtract
     * @return self Transformer instance
     */
    public function subtract(float $value): self
    {
        $this->subtraction = $value;

        return $this;
    }

    /**
     * Convert to absolute value.
     *
     * @param  bool  $absolute  Toggle absolute conversion
     * @return self Transformer instance
     */
    public function absolute(bool $absolute = true): self
    {
        $this->absolute = $absolute;

        return $this;
    }

    /**
     * Set minimum value (clamp).
     *
     * @param  float  $min  Minimum value
     * @return self Transformer instance
     */
    public function min(float $min): self
    {
        $this->minValue = $min;

        return $this;
    }

    /**
     * Set maximum value (clamp).
     *
     * @param  float  $max  Maximum value
     * @return self Transformer instance
     */
    public function max(float $max): self
    {
        $this->maxValue = $max;

        return $this;
    }

    /**
     * Set default value for non-numeric inputs.
     *
     * @param  float  $value  Default numeric value
     * @return self Transformer instance
     */
    public function default(float $value): self
    {
        $this->defaultValue = $value;

        return $this;
    }

    /**
     * Enable rounding.
     *
     * @param  bool  $round  Toggle rounding
     * @return self Transformer instance
     */
    public function round(bool $round = true): self
    {
        $this->round = $round;
        $this->floor = false;
        $this->ceil = false;

        return $this;
    }

    /**
     * Enable floor rounding.
     *
     * @param  bool  $floor  Toggle floor rounding
     * @return self Transformer instance
     */
    public function floor(bool $floor = true): self
    {
        $this->floor = $floor;
        $this->round = false;
        $this->ceil = false;

        return $this;
    }

    /**
     * Enable ceiling rounding.
     *
     * @param  bool  $ceil  Toggle ceiling rounding
     * @return self Transformer instance
     */
    public function ceil(bool $ceil = true): self
    {
        $this->ceil = $ceil;
        $this->round = false;
        $this->floor = false;

        return $this;
    }

    /**
     * Transform value.
     *
     * @param  mixed  $value  Input value
     * @param  array<string, mixed>  $context  Transformation context
     * @return mixed Transformed value
     */
    public function transform(mixed $value, array $context = []): mixed
    {
        // Handle non-numeric values
        if (! is_numeric($value)) {
            if ($value === null || $value === '') {
                return $this->defaultValue;
            }

            if (! is_scalar($value) && ! $value instanceof Stringable) {
                return $this->defaultValue;
            }

            $value = preg_replace('/[^0-9.-]/', '', (string) $value);

            if (! is_numeric($value)) {
                return $this->defaultValue;
            }
        }

        $result = (float) $value;

        // Apply multiplication
        if ($this->multiplier !== null) {
            $result *= $this->multiplier;
        }

        // Apply division
        if ($this->divisor !== null) {
            $result /= $this->divisor;
        }

        // Apply addition
        if ($this->addition !== null) {
            $result += $this->addition;
        }

        // Apply subtraction
        if ($this->subtraction !== null) {
            $result -= $this->subtraction;
        }

        // Apply absolute
        if ($this->absolute) {
            $result = abs($result);
        }

        // Apply min/max clamping
        if ($this->minValue !== null) {
            $result = max($result, $this->minValue);
        }

        if ($this->maxValue !== null) {
            $result = min($result, $this->maxValue);
        }

        // Apply rounding
        if ($this->round) {
            $result = round($result, $this->precision ?? 0);
        } elseif ($this->floor) {
            $result = floor($result);
        } elseif ($this->ceil) {
            $result = ceil($result);
        } elseif ($this->precision !== null) {
            $result = round($result, $this->precision);
        }

        return $result;
    }

    /**
     * Create percentage transformer.
     *
     * @param  int  $precision  Decimal precision
     * @return self Transformer instance
     */
    public static function percentage(int $precision = 2): self
    {
        return (new self)
            ->multiply(100)
            ->precision($precision);
    }

    /**
     * Create currency transformer.
     *
     * @param  int  $precision  Decimal precision
     * @return self Transformer instance
     */
    public static function currency(int $precision = 2): self
    {
        return (new self)
            ->precision($precision)
            ->min(0);
    }

    /**
     * Create integer transformer.
     *
     * @return self Transformer instance
     */
    public static function integer(): self
    {
        return (new self)->round();
    }

    /**
     * Create positive transformer.
     *
     * @return self Transformer instance
     */
    public static function positive(): self
    {
        return (new self)
            ->absolute()
            ->min(0);
    }
}
