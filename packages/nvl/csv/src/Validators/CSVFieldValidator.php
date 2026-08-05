<?php

declare(strict_types=1);

namespace Nvl\Csv\Validators;

use Carbon\Carbon;
use Exception;
use InvalidArgumentException;
use Nvl\Csv\Enums\CSVTypeEnum;

/**
 * Validates individual CSV fields with type checking and rules.
 */
final class CSVFieldValidator extends CSVValidator
{
    private ?CSVTypeEnum $type = null;

    private bool $required = false;

    private bool $nullable = true;

    private ?int $minLength = null;

    private ?int $maxLength = null;

    private ?float $minValue = null;

    private ?float $maxValue = null;

    private ?string $pattern = null;

    private ?string $patternMessage = null;

    /** @var array<mixed>|null */
    private ?array $allowedValues = null;

    /** @var array<callable> */
    private array $customValidators = [];

    /**
     * Set field type.
     *
     * @param  CSVTypeEnum  $type  Field type
     * @return self Validator instance
     */
    public function type(CSVTypeEnum $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Set field as required.
     *
     * @param  bool  $required  Toggle required
     * @return self Validator instance
     */
    public function required(bool $required = true): self
    {
        $this->required = $required;
        $this->nullable = ! $required;

        return $this;
    }

    /**
     * Set field as nullable.
     *
     * @param  bool  $nullable  Toggle nullable
     * @return self Validator instance
     */
    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * Set string length constraints.
     *
     * @param  int|null  $min  Minimum length
     * @param  int|null  $max  Maximum length
     * @return self Validator instance
     */
    public function length(?int $min = null, ?int $max = null): self
    {
        if (($min !== null && $min < 0) || ($max !== null && $max < 0)) {
            throw new InvalidArgumentException('Length constraints cannot be negative.');
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidArgumentException('Minimum length cannot exceed maximum length.');
        }

        $this->minLength = $min;
        $this->maxLength = $max;

        return $this;
    }

    /**
     * Set numeric range constraints.
     *
     * @param  float|null  $min  Minimum value
     * @param  float|null  $max  Maximum value
     * @return self Validator instance
     */
    public function range(?float $min = null, ?float $max = null): self
    {
        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidArgumentException('Minimum value cannot exceed maximum value.');
        }

        $this->minValue = $min;
        $this->maxValue = $max;

        return $this;
    }

    /**
     * Set regex pattern.
     *
     * @param  string  $pattern  Regex pattern
     * @param  string|null  $message  Custom error message
     * @return self Validator instance
     */
    public function pattern(string $pattern, ?string $message = null): self
    {
        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException('Invalid regular expression pattern.');
        }

        $this->pattern = $pattern;
        $this->patternMessage = $message;

        return $this;
    }

    /**
     * Set allowed values.
     *
     * @param  array<int, mixed>  $values  Allowed values
     * @return self Validator instance
     */
    public function in(array $values): self
    {
        $this->allowedValues = $values;

        return $this;
    }

    /**
     * Add custom validator.
     *
     * @param  callable  $validator  Custom validation callback
     * @return self Validator instance
     */
    public function custom(callable $validator): self
    {
        $this->customValidators[] = $validator;

        return $this;
    }

    /**
     * Validate a field value.
     *
     * @param  mixed  $value  Field value
     * @param  array<string, mixed>  $context  Validation context
     * @return bool True when value is valid
     */
    public function validate(mixed $value, array $context = []): bool
    {
        $this->clearMessages();
        $isValid = true;

        // Check required
        if ($this->required && $this->isEmpty($value)) {
            $this->addError('Field is required');

            return false;
        }

        // Check nullable
        if (! $this->nullable && $value === null) {
            $this->addError('Field cannot be null');

            return false;
        }

        // Skip further validation for empty optional fields
        if (! $this->required && $this->isEmpty($value)) {
            return true;
        }

        // Type validation
        if ($this->type !== null && ! $this->isEmpty($value)) {
            if (! $this->type->validate($value)) {
                $this->addError("Field must be of type {$this->type->value}");
                $isValid = false;
            }
        }

        // String length validation
        if (is_string($value) && ($this->minLength !== null || $this->maxLength !== null)) {
            $length = mb_strlen($value);

            if ($this->minLength !== null && $length < $this->minLength) {
                $this->addError("Field must be at least {$this->minLength} characters");
                $isValid = false;
            }

            if ($this->maxLength !== null && $length > $this->maxLength) {
                $this->addError("Field must not exceed {$this->maxLength} characters");
                $isValid = false;
            }
        }

        // Numeric range validation
        if (is_numeric($value) && ($this->minValue !== null || $this->maxValue !== null)) {
            $numValue = (float) $value;

            if ($this->minValue !== null && $numValue < $this->minValue) {
                $this->addError("Field must be at least {$this->minValue}");
                $isValid = false;
            }

            if ($this->maxValue !== null && $numValue > $this->maxValue) {
                $this->addError("Field must not exceed {$this->maxValue}");
                $isValid = false;
            }
        }

        // Pattern validation
        if ($this->pattern !== null && is_string($value)) {
            $matches = preg_match($this->pattern, $value);
            if ($matches === false || $matches === 0) {
                $this->addError($this->patternMessage ?? 'Field has invalid format');
                $isValid = false;
            }
        }

        // Allowed values validation
        if ($this->allowedValues !== null) {
            if (! in_array($value, $this->allowedValues, true)) {
                $stringValues = array_map(
                    static function (mixed $allowedValue): string {
                        if (is_scalar($allowedValue) || $allowedValue === null) {
                            return var_export($allowedValue, true);
                        }

                        $encoded = json_encode($allowedValue);

                        return $encoded === false ? get_debug_type($allowedValue) : $encoded;
                    },
                    $this->allowedValues,
                );
                $this->addError('Field must be one of: '.implode(', ', $stringValues));
                $isValid = false;
            }
        }

        // Custom validators
        foreach ($this->customValidators as $validator) {
            $result = $validator($value, $context);

            if ($result === false) {
                $this->addError('Custom validation failed');
                $isValid = false;
            } elseif (is_string($result)) {
                $this->addError($result);
                $isValid = false;
            }
        }

        return $isValid;
    }

    /**
     * Create email validator.
     *
     * @return self Validator instance
     */
    public static function email(): self
    {
        return (new self)
            ->type(CSVTypeEnum::STRING)
            ->pattern('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', 'Invalid email format');
    }

    /**
     * Create URL validator.
     *
     * @return self Validator instance
     */
    public static function url(): self
    {
        return (new self)
            ->type(CSVTypeEnum::STRING)
            ->custom(function ($value) {
                $isValid = filter_var($value, FILTER_VALIDATE_URL);
                if ($isValid === false) {
                    return 'Invalid URL format';
                }

                return true;
            });
    }

    /**
     * Create phone validator.
     *
     * @return self Validator instance
     */
    public static function phone(): self
    {
        return (new self)
            ->type(CSVTypeEnum::STRING)
            ->pattern('/^[+]?[0-9\s\-\(\)]+$/', 'Invalid phone number format')
            ->length(10, 20);
    }

    /**
     * Create date validator.
     *
     * @param  string  $format  Expected date format
     * @return self Validator instance
     */
    public static function date(string $format = 'Y-m-d'): self
    {
        return (new self)
            ->type(CSVTypeEnum::STRING)
            ->custom(function ($value) use ($format) {
                try {
                    if (! is_string($value)) {
                        return "Invalid date format (expected: $format)";
                    }

                    $inputFormat = str_contains($format, '!') || str_contains($format, '|')
                        ? $format
                        : '!'.$format;
                    $date = Carbon::createFromFormat($inputFormat, $value);
                    $parseErrors = Carbon::getLastErrors();
                    if (
                        $date === null
                        || ($parseErrors !== false
                            && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
                    ) {
                        return "Invalid date format (expected: $format)";
                    }

                    return true;
                } catch (Exception) {
                    return "Invalid date format (expected: $format)";
                }
            });
    }

    /**
     * Create numeric validator.
     *
     * @param  float|null  $min  Minimum value
     * @param  float|null  $max  Maximum value
     * @return self Validator instance
     */
    public static function numeric(?float $min = null, ?float $max = null): self
    {
        return (new self)
            ->type(CSVTypeEnum::FLOAT)
            ->range($min, $max);
    }

    /**
     * Create integer validator.
     *
     * @param  int|null  $min  Minimum value
     * @param  int|null  $max  Maximum value
     * @return self Validator instance
     */
    public static function integer(?int $min = null, ?int $max = null): self
    {
        return (new self)
            ->type(CSVTypeEnum::INTEGER)
            ->range($min !== null ? (float) $min : null, $max !== null ? (float) $max : null);
    }

    /**
     * Create boolean validator.
     *
     * @return self Validator instance
     */
    public static function boolean(): self
    {
        return (new self)
            ->type(CSVTypeEnum::BOOLEAN);
    }
}
