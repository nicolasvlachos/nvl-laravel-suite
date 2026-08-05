<?php

declare(strict_types=1);

namespace Nvl\Csv\Enums;

use Carbon\Carbon;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use Stringable;

/**
 * Comprehensive data type enumeration for CSV field validation and transformation.
 *
 * Provides type-safe column casting with built-in validation for common data types.
 * Supports both standard and nullable variants for flexible data handling.
 */
enum CSVTypeEnum: string
{
    // Standard data types - strict validation required
    case STRING = 'string';          // Text data, converted to string
    case INTEGER = 'integer';        // Whole numbers only
    case FLOAT = 'float';           // Decimal numbers
    case BOOLEAN = 'boolean';       // true/false, 1/0, yes/no variants
    case DATE = 'date';             // Date strings (Y-m-d format)
    case DATETIME = 'datetime';     // DateTime strings with time component
    case EMAIL = 'email';           // Email address validation
    case JSON = 'json';             // Valid JSON strings or encodable data
    case ARRAY = 'array';           // Array data or JSON-decodable strings

    // Nullable variants - allow null/empty values
    case NULLABLE_STRING = 'nullable_string';      // String or null
    case NULLABLE_INTEGER = 'nullable_integer';    // Integer or null
    case NULLABLE_FLOAT = 'nullable_float';        // Float or null

    /**
     * Cast and transform a value to match this data type.
     *
     * Performs intelligent type conversion with validation:
     * - Handles null/empty values based on nullability
     * - Converts strings to appropriate native PHP types
     * - Validates format compliance before conversion
     *
     * @param  mixed  $value  Raw input value from CSV
     * @return mixed Converted value in target PHP type
     *
     * @throws InvalidArgumentException When value cannot be converted to target type
     */
    public function cast(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            if ($this->isNullable()) {
                return null;
            }
        }

        return match ($this) {
            self::STRING => $this->castToString($value),
            self::INTEGER => $this->castToInteger($value),
            self::FLOAT => $this->castToFloat($value),
            self::BOOLEAN => $this->castToBoolean($value),
            self::DATE => $this->castToDate($value),
            self::DATETIME => $this->castToDateTime($value),
            self::EMAIL => $this->castToEmail($value),
            self::JSON => $this->castToJson($value),
            self::ARRAY => $this->castToArray($value),
            self::NULLABLE_STRING => $value === null || $value === '' ? null : $this->castToString($value),
            self::NULLABLE_INTEGER => $value === null || $value === '' ? null : $this->castToInteger($value),
            self::NULLABLE_FLOAT => $value === null || $value === '' ? null : $this->castToFloat($value),
        };
    }

    /**
     * Validate if a value can be safely converted to this data type.
     *
     * Performs pre-conversion validation without modifying the value:
     * - Checks format compatibility
     * - Handles null values according to type nullability
     * - Validates special formats (dates, JSON, etc.)
     *
     * @param  mixed  $value  Input value to validate
     * @return bool True if value is compatible with this type
     */
    public function validate(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return $this->isNullable();
        }

        return match ($this) {
            self::STRING, self::NULLABLE_STRING => is_string($value) || is_int($value) || is_float($value),
            self::INTEGER, self::NULLABLE_INTEGER => $this->isValidInteger($value),
            self::FLOAT, self::NULLABLE_FLOAT => is_numeric($value),
            self::BOOLEAN => $this->isValidBoolean($value),
            self::DATE, self::DATETIME => $this->isValidDate($value),
            self::EMAIL => $this->isValidEmail($value),
            self::JSON => is_string($value) ? $this->isValidJson($value) : $this->isJsonEncodable($value),
            self::ARRAY => is_array($value) || $this->isValidJson($value),
        };
    }

    /**
     * Determine if this data type allows null or empty values.
     *
     * Nullable types accept null/empty input without throwing errors,
     * while standard types require valid non-empty values.
     *
     * @return bool True for nullable variants (NULLABLE_*)
     */
    public function isNullable(): bool
    {
        return in_array($this, [
            self::NULLABLE_STRING,
            self::NULLABLE_INTEGER,
            self::NULLABLE_FLOAT,
        ], true);
    }

    /**
     * Get the corresponding PHP type hint string for this CSV type.
     *
     * Useful for code generation, documentation, and type checking.
     * Returns proper PHP 8+ type syntax including union types.
     *
     * @return string PHP type hint (e.g., 'string', 'int', '?float')
     */
    public function getPhpType(): string
    {
        return match ($this) {
            self::STRING => 'string',
            self::INTEGER => 'int',
            self::FLOAT => 'float',
            self::BOOLEAN => 'bool',
            self::DATE, self::DATETIME => 'string',
            self::EMAIL => 'string',
            self::JSON => 'string',
            self::ARRAY => 'array',
            self::NULLABLE_STRING => '?string',
            self::NULLABLE_INTEGER => '?int',
            self::NULLABLE_FLOAT => '?float',
        };
    }

    /**
     * Get the appropriate default/fallback value for this data type.
     *
     * Returns type-appropriate defaults when no value is provided:
     * - Empty string for strings
     * - Zero for numbers
     * - Null for nullable types
     *
     * @return mixed Type-specific default value
     */
    public function getDefaultValue(): mixed
    {
        if ($this->isNullable()) {
            return null;
        }

        return match ($this) {
            self::STRING => '',
            self::INTEGER => 0,
            self::FLOAT => 0.0,
            self::BOOLEAN => false,
            self::DATE, self::DATETIME => null,
            self::EMAIL => '',
            self::JSON => '{}',
            self::ARRAY => [],
            default => null,
        };
    }

    /**
     * Convert various input formats to boolean values.
     *
     * Handles multiple boolean representations commonly found in CSV files:
     * - Numeric: 0/1, any number (0 = false, others = true)
     * - String: 'true'/'false', 'yes'/'no', 'on'/'off', '1'/'0'
     * - Case-insensitive matching for flexibility
     *
     * @param  mixed  $value  Input value
     * @return bool Parsed boolean value
     */
    private function castToBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! $this->isValidBoolean($value)) {
            throw new InvalidArgumentException(
                'Cannot cast '.$this->describeValue($value).' to boolean.',
            );
        }

        if (is_numeric($value)) {
            return (float) $value === 1.0;
        }

        $stringValue = strtolower($this->castToString($value));

        return in_array($stringValue, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Parse and convert input to standardized date string format.
     *
     * Uses Carbon parser for flexible date format recognition.
     * Always returns Y-m-d format for consistency.
     *
     * @param  mixed  $value  Input value
     * @return string Formatted date string
     *
     * @throws InvalidArgumentException When input cannot be parsed as valid date
     */
    private function castToDate(mixed $value): string
    {
        try {
            return $this->parseDate($value)->toDateString();
        } catch (Exception) {
            throw new InvalidArgumentException(
                'Cannot parse '.$this->describeValue($value).' as date.',
            );
        }
    }

    /**
     * Parse and convert input to standardized datetime string format.
     *
     * Uses Carbon parser for comprehensive datetime format support.
     * Returns Y-m-d H:i:s format with time component preserved.
     *
     * @param  mixed  $value  Input value
     * @return string Formatted datetime string
     *
     * @throws InvalidArgumentException When input cannot be parsed as valid datetime
     */
    private function castToDateTime(mixed $value): string
    {
        try {
            return $this->parseDate($value)->toDateTimeString();
        } catch (Exception) {
            throw new InvalidArgumentException(
                'Cannot parse '.$this->describeValue($value).' as datetime.',
            );
        }
    }

    /**
     * Convert input to valid JSON string format.
     *
     * Handles two scenarios:
     * 1. Already valid JSON strings - return as-is after validation
     * 2. Other data types - encode using json_encode()
     *
     * @param  mixed  $value  Input value
     * @return string JSON string value
     *
     * @throws InvalidArgumentException When value cannot be JSON encoded
     */
    private function castToJson(mixed $value): string
    {
        // If already a string, check if it's valid JSON
        if (is_string($value)) {
            if ($this->isValidJson($value)) {
                return $value;
            }

            throw new InvalidArgumentException('CSV JSON strings must contain valid JSON.');
        }

        $json = json_encode($value);
        if ($json === false) {
            throw new InvalidArgumentException('Cannot encode value as JSON');
        }

        return $json;
    }

    /**
     * Convert various input formats to PHP array.
     *
     * Handles multiple array representations:
     * 1. Already arrays - return as-is
     * 2. JSON strings - decode to array
     * 3. Comma-separated values - split and trim
     * 4. Other values - wrap in single-element array
     *
     * @param  mixed  $value  Input value
     * @return array<int|string, mixed> Parsed array value
     */
    private function castToArray(mixed $value): array
    {
        // Already an array - perfect!
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            // Try JSON decoding first (most structured format)
            if ($this->isValidJson($value)) {
                $decoded = json_decode($value, true);

                // Ensure result is array (JSON scalars become single-element arrays)
                return is_array($decoded) ? $decoded : [$decoded];
            }

            // Fall back to CSV-style comma-separated values
            if (str_contains($value, ',')) {
                return array_map('trim', explode(',', $value));
            }
        }

        // Wrap non-array values in array for consistency
        return [$value];
    }

    /**
     * Validate if a value can represent a boolean.
     *
     * Accepts various boolean representations commonly found in CSV data:
     * - Native booleans: true/false
     * - Numeric: 0/1 (not other numbers)
     * - String: comprehensive list of boolean representations
     *
     * @param  mixed  $value  Input value
     * @return bool True when value is a valid boolean representation
     */
    private function isValidBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }

        if (is_numeric($value)) {
            return in_array((float) $value, [0.0, 1.0], true);
        }

        if (! is_string($value)) {
            return false;
        }

        $stringValue = strtolower($value);

        return in_array($stringValue, ['0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], true);
    }

    /**
     * Validate if a value can be parsed as a date.
     *
     * Uses Carbon's robust date parsing to test validity
     * without throwing exceptions during validation.
     *
     * @param  mixed  $value  Input value
     * @return bool True when value can be parsed as a date
     */
    private function isValidDate(mixed $value): bool
    {
        try {
            $this->parseDate($value);

            return true;
        } catch (Exception) {
            // Any parsing failure means invalid date
            return false;
        }
    }

    /**
     * Validate if a value represents a valid email address.
     *
     * Uses PHP's built-in filter validation for email addresses.
     * Accepts only string values that match RFC compliant email format.
     *
     * @param  mixed  $value  Input value
     * @return bool True when value is a valid email address
     */
    private function isValidEmail(mixed $value): bool
    {
        // Only strings can be email addresses
        if (! is_string($value)) {
            return false;
        }

        // Use PHP's built-in email validation
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Cast and validate a value as an email address.
     *
     * Converts the value to string and validates it as a proper email address.
     * Returns the original string if valid, throws exception if invalid.
     *
     * @param  mixed  $value  Input value
     * @return string Validated email address
     *
     * @throws InvalidArgumentException When value is not a valid email address
     */
    private function castToEmail(mixed $value): string
    {
        $stringValue = $this->castToString($value);

        if (! $this->isValidEmail($stringValue)) {
            throw new InvalidArgumentException("'$stringValue' is not a valid email address");
        }

        return $stringValue;
    }

    /**
     * Validate if a string contains valid JSON data.
     *
     * Performs actual JSON parsing to verify validity.
     * Only string values can contain JSON data.
     *
     * @param  mixed  $value  Input value
     * @return bool True when value is valid JSON
     */
    private function isValidJson(mixed $value): bool
    {
        // Only strings can contain JSON
        if (! is_string($value)) {
            return false;
        }

        // Attempt to decode and check for errors
        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function isJsonEncodable(mixed $value): bool
    {
        json_encode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function castToString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException(
            'Cannot cast '.$this->describeValue($value).' to string.',
        );
    }

    private function castToInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        $stringValue = is_string($value) ? $value : $this->castToString($value);
        $integer = filter_var($stringValue, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new InvalidArgumentException("'{$stringValue}' is not an integer.");
        }

        return $integer;
    }

    private function castToFloat(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (! is_string($value) || ! is_numeric($value)) {
            throw new InvalidArgumentException(
                'Cannot cast '.$this->describeValue($value).' to float.',
            );
        }

        return (float) $value;
    }

    private function isValidInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        return is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    private function parseDate(mixed $value): Carbon
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! $value instanceof DateTimeInterface) {
            throw new InvalidArgumentException('Date values must be strings, numbers, or DateTime instances.');
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        $date = Carbon::parse($value);
        $parseErrors = Carbon::getLastErrors();
        if (
            $parseErrors !== false
            && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0)
        ) {
            throw new InvalidArgumentException('Date value contains invalid calendar components.');
        }

        return $date;
    }

    private function describeValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return var_export($value, true);
        }

        return get_debug_type($value);
    }
}
