<?php

declare(strict_types=1);

namespace Nvl\Csv\Filters;

use InvalidArgumentException;
use LogicException;

/**
 * Filter based on field values.
 */
final class FieldFilter extends CSVFilter
{
    private string $field;

    private mixed $value = null;

    private ?string $operator = null;

    private bool $caseInsensitive = false;

    /**
     * Create a field filter.
     *
     * @param  string  $field  Field name to evaluate
     * @return void
     */
    public function __construct(string $field)
    {
        $this->field = $field;
    }

    /**
     * Filter where field equals value.
     *
     * @param  mixed  $value  Value to compare
     * @return self Filter instance
     */
    public function equals(mixed $value): self
    {
        $this->value = $value;
        $this->operator = '=';

        return $this;
    }

    /**
     * Filter where field does not equal value.
     *
     * @param  mixed  $value  Value to compare
     * @return self Filter instance
     */
    public function notEquals(mixed $value): self
    {
        $this->value = $value;
        $this->operator = '!=';

        return $this;
    }

    /**
     * Filter where field is greater than value.
     *
     * @param  mixed  $value  Value to compare
     * @return self Filter instance
     */
    public function greaterThan(mixed $value): self
    {
        $this->value = $value;
        $this->operator = '>';

        return $this;
    }

    /**
     * Filter where field is greater than or equal to value.
     *
     * @param  mixed  $value  Value to compare
     * @return self Filter instance
     */
    public function greaterThanOrEqual(mixed $value): self
    {
        $this->value = $value;
        $this->operator = '>=';

        return $this;
    }

    /**
     * Filter where field is less than value.
     *
     * @param  mixed  $value  Value to compare
     * @return self Filter instance
     */
    public function lessThan(mixed $value): self
    {
        $this->value = $value;
        $this->operator = '<';

        return $this;
    }

    /**
     * Filter where field is less than or equal to value.
     *
     * @param  mixed  $value  Value to compare
     * @return self Filter instance
     */
    public function lessThanOrEqual(mixed $value): self
    {
        $this->value = $value;
        $this->operator = '<=';

        return $this;
    }

    /**
     * Filter where field contains value.
     *
     * @param  string  $value  Value to search for
     * @param  bool  $caseInsensitive  Toggle case-insensitive search
     * @return self Filter instance
     */
    public function contains(string $value, bool $caseInsensitive = false): self
    {
        $this->value = $value;
        $this->operator = 'contains';
        $this->caseInsensitive = $caseInsensitive;

        return $this;
    }

    /**
     * Filter where field starts with value.
     *
     * @param  string  $value  Value to search for
     * @param  bool  $caseInsensitive  Toggle case-insensitive search
     * @return self Filter instance
     */
    public function startsWith(string $value, bool $caseInsensitive = false): self
    {
        $this->value = $value;
        $this->operator = 'starts_with';
        $this->caseInsensitive = $caseInsensitive;

        return $this;
    }

    /**
     * Filter where field ends with value.
     *
     * @param  string  $value  Value to search for
     * @param  bool  $caseInsensitive  Toggle case-insensitive search
     * @return self Filter instance
     */
    public function endsWith(string $value, bool $caseInsensitive = false): self
    {
        $this->value = $value;
        $this->operator = 'ends_with';
        $this->caseInsensitive = $caseInsensitive;

        return $this;
    }

    /**
     * Filter where field is in array.
     *
     * @param  array<int, mixed>  $values  Values to compare
     * @return self Filter instance
     */
    public function in(array $values): self
    {
        $this->value = $values;
        $this->operator = 'in';

        return $this;
    }

    /**
     * Filter where field is not in array.
     *
     * @param  array<int, mixed>  $values  Values to compare
     * @return self Filter instance
     */
    public function notIn(array $values): self
    {
        $this->value = $values;
        $this->operator = 'not_in';

        return $this;
    }

    /**
     * Filter where field is null.
     *
     * @return self Filter instance
     */
    public function isNull(): self
    {
        $this->operator = 'is_null';

        return $this;
    }

    /**
     * Filter where field is not null.
     *
     * @return self Filter instance
     */
    public function isNotNull(): self
    {
        $this->operator = 'is_not_null';

        return $this;
    }

    /**
     * Filter where field is empty.
     *
     * @return self Filter instance
     */
    public function isEmpty(): self
    {
        $this->operator = 'is_empty';

        return $this;
    }

    /**
     * Filter where field is not empty.
     *
     * @return self Filter instance
     */
    public function isNotEmpty(): self
    {
        $this->operator = 'is_not_empty';

        return $this;
    }

    /**
     * Filter where field matches regex pattern.
     *
     * @param  string  $pattern  Regex pattern to match
     * @return self Filter instance
     */
    public function matches(string $pattern): self
    {
        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException('Invalid regular expression pattern.');
        }

        $this->value = $pattern;
        $this->operator = 'matches';

        return $this;
    }

    /**
     * Filter where field is between two values.
     *
     * @param  mixed  $min  Minimum value
     * @param  mixed  $max  Maximum value
     * @return self Filter instance
     */
    public function between(mixed $min, mixed $max): self
    {
        $this->value = [$min, $max];
        $this->operator = 'between';

        return $this;
    }

    /**
     * Check if row passes filter.
     *
     * @param  array<string, mixed>  $row  Row data
     * @return bool True when row passes filter
     */
    public function passes(array $row): bool
    {
        if ($this->operator === null) {
            throw new LogicException("No comparison operator is configured for field '{$this->field}'.");
        }

        $fieldValue = $row[$this->field] ?? null;

        return match ($this->operator) {
            '=' => $this->compareEquals($fieldValue, $this->value),
            '!=' => ! $this->compareEquals($fieldValue, $this->value),
            '>' => $fieldValue > $this->value,
            '>=' => $fieldValue >= $this->value,
            '<' => $fieldValue < $this->value,
            '<=' => $fieldValue <= $this->value,
            'contains' => $this->compareContains($fieldValue, $this->value),
            'starts_with' => $this->compareStartsWith($fieldValue, $this->value),
            'ends_with' => $this->compareEndsWith($fieldValue, $this->value),
            'in' => $this->compareIn($fieldValue),
            'not_in' => ! $this->compareIn($fieldValue),
            'is_null' => $fieldValue === null,
            'is_not_null' => $fieldValue !== null,
            'is_empty' => empty($fieldValue),
            'is_not_empty' => ! empty($fieldValue),
            'matches' => $this->compareMatches($fieldValue),
            'between' => $this->compareBetween($fieldValue),
            default => throw new LogicException("Unsupported field filter operator '{$this->operator}'."),
        };
    }

    /**
     * Compare values for equality checks.
     *
     * @param  mixed  $fieldValue  Row field value
     * @param  mixed  $compareValue  Comparison target
     * @return bool True when values match
     */
    private function compareEquals(mixed $fieldValue, mixed $compareValue): bool
    {
        if ($this->caseInsensitive && is_string($fieldValue) && is_string($compareValue)) {
            return mb_strtolower($fieldValue) === mb_strtolower($compareValue);
        }

        return $fieldValue === $compareValue;
    }

    private function compareIn(mixed $fieldValue): bool
    {
        return is_array($this->value) && in_array($fieldValue, $this->value, true);
    }

    private function compareMatches(mixed $fieldValue): bool
    {
        return is_string($this->value)
            && is_string($fieldValue)
            && preg_match($this->value, $fieldValue) === 1;
    }

    private function compareBetween(mixed $fieldValue): bool
    {
        if (! is_array($this->value) || count($this->value) !== 2) {
            return false;
        }

        $bounds = array_values($this->value);

        return $fieldValue >= $bounds[0] && $fieldValue <= $bounds[1];
    }

    /**
     * Compare values for contains checks.
     *
     * @param  mixed  $fieldValue  Row field value
     * @param  mixed  $searchValue  Search target
     * @return bool True when value contains search target
     */
    private function compareContains(mixed $fieldValue, mixed $searchValue): bool
    {
        if (! is_string($fieldValue) || ! is_string($searchValue)) {
            return false;
        }

        if ($this->caseInsensitive) {
            return mb_stripos($fieldValue, $searchValue) !== false;
        }

        return mb_strpos($fieldValue, $searchValue) !== false;
    }

    /**
     * Compare values for starts-with checks.
     *
     * @param  mixed  $fieldValue  Row field value
     * @param  mixed  $searchValue  Search target
     * @return bool True when value starts with search target
     */
    private function compareStartsWith(mixed $fieldValue, mixed $searchValue): bool
    {
        if (! is_string($fieldValue) || ! is_string($searchValue)) {
            return false;
        }

        if ($this->caseInsensitive) {
            return mb_stripos($fieldValue, $searchValue) === 0;
        }

        return mb_strpos($fieldValue, $searchValue) === 0;
    }

    /**
     * Compare values for ends-with checks.
     *
     * @param  mixed  $fieldValue  Row field value
     * @param  mixed  $searchValue  Search target
     * @return bool True when value ends with search target
     */
    private function compareEndsWith(mixed $fieldValue, mixed $searchValue): bool
    {
        if (! is_string($fieldValue) || ! is_string($searchValue)) {
            return false;
        }

        $length = mb_strlen($searchValue);

        if ($this->caseInsensitive) {
            return mb_strtolower(mb_substr($fieldValue, -$length)) === mb_strtolower($searchValue);
        }

        return mb_substr($fieldValue, -$length) === $searchValue;
    }
}
