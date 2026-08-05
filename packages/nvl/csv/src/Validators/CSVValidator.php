<?php

declare(strict_types=1);

namespace Nvl\Csv\Validators;

/**
 * Abstract base class for CSV data validation.
 *
 * Provides a comprehensive validation framework for CSV field values with
 * built-in error and warning collection. Includes common validation methods
 * for data types, formats, ranges, and business rules.
 *
 * Concrete validators should extend this class and implement the validate() method
 * while utilizing the provided helper methods for consistent validation behavior.
 */
abstract class CSVValidator
{
    /** @var array<string> */
    protected array $errors = [];

    /** @var array<string> */
    protected array $warnings = [];

    /**
     * Validate a CSV field value according to the validation rules.
     *
     * This is the primary validation method that must be implemented by all
     * concrete validator classes. Should return true if validation passes,
     * false otherwise. Errors and warnings should be recorded using helper methods.
     *
     * @param  mixed  $value  The value to validate
     * @param  array<string, mixed>  $context  Additional context data for validation (row data, field info, etc.)
     * @return bool True if validation passes, false if validation fails
     */
    abstract public function validate(mixed $value, array $context = []): bool;

    /**
     * Get all validation error messages collected during validation.
     *
     * Returns an array of human-readable error messages that describe
     * validation failures. These are typically critical issues that
     * prevent data processing.
     *
     * @return array<string> Array of error messages
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get all validation warning messages collected during validation.
     *
     * Returns an array of human-readable warning messages that describe
     * potential issues or data quality concerns. These are non-critical
     * issues that don't prevent processing but may need attention.
     *
     * @return array<string> Array of warning messages
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Clear all stored error and warning messages.
     *
     * Resets the validator state by removing all accumulated validation
     * messages. Useful when reusing a validator instance for multiple validations.
     */
    public function clearMessages(): void
    {
        $this->errors = [];
        $this->warnings = [];
    }

    /**
     * Add a validation error message to the collection.
     *
     * Records a critical validation failure that prevents data processing.
     * Error messages should be descriptive and actionable.
     *
     * @param  string  $message  Human-readable error message
     */
    protected function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * Add a validation warning message to the collection.
     *
     * Records a data quality concern or potential issue that doesn't
     * prevent processing but may need attention or review.
     *
     * @param  string  $message  Human-readable warning message
     */
    protected function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * Check if a value should be considered empty for validation purposes.
     *
     * Determines if a value is null, empty string, or empty array.
     * Used consistently across validation methods for empty value detection.
     *
     * @param  mixed  $value  Value to check for emptiness
     * @return bool True if the value is considered empty
     */
    protected function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * Validate that a required field has a non-empty value.
     *
     * Checks that the field contains a meaningful value and is not empty.
     * Adds an appropriate error message if validation fails.
     *
     * @param  mixed  $value  Value to check for presence
     * @param  string  $fieldName  Name of the field for error messaging
     * @return bool True if the field has a value, false if empty
     */
    protected function validateRequired(mixed $value, string $fieldName): bool
    {
        if ($this->isEmpty($value)) {
            $this->addError("Field '$fieldName' is required");

            return false;
        }

        return true;
    }

    /**
     * Validate that a string value falls within specified length constraints.
     *
     * Checks string length against minimum and/or maximum limits using
     * multibyte-safe length calculation. Adds appropriate error messages for violations.
     *
     * @param  string  $value  String value to validate
     * @param  string  $fieldName  Name of the field for error messaging
     * @param  int|null  $min  Minimum required length (null = no minimum)
     * @param  int|null  $max  Maximum allowed length (null = no maximum)
     * @return bool True if length is valid, false if outside constraints
     */
    protected function validateLength(string $value, string $fieldName, ?int $min = null, ?int $max = null): bool
    {
        $length = mb_strlen($value);

        if ($min !== null && $length < $min) {
            $this->addError("Field '$fieldName' must be at least $min characters");

            return false;
        }

        if ($max !== null && $length > $max) {
            $this->addError("Field '$fieldName' must not exceed $max characters");

            return false;
        }

        return true;
    }

    /**
     * Validate that a numeric value falls within specified range constraints.
     *
     * Checks numeric value against minimum and/or maximum limits.
     * Adds appropriate error messages for values outside the valid range.
     *
     * @param  float|int  $value  Numeric value to validate
     * @param  string  $fieldName  Name of the field for error messaging
     * @param  float|null  $min  Minimum allowed value (null = no minimum)
     * @param  float|null  $max  Maximum allowed value (null = no maximum)
     * @return bool True if value is within range, false if outside constraints
     */
    protected function validateRange(float|int $value, string $fieldName, ?float $min = null, ?float $max = null): bool
    {
        if ($min !== null && $value < $min) {
            $this->addError("Field '$fieldName' must be at least $min");

            return false;
        }

        if ($max !== null && $value > $max) {
            $this->addError("Field '$fieldName' must not exceed $max");

            return false;
        }

        return true;
    }

    /**
     * Validate that a string value matches a regular expression pattern.
     *
     * Tests the value against the provided regex pattern. Useful for
     * validating formats like phone numbers, postal codes, or custom formats.
     *
     * @param  string  $value  String value to validate against pattern
     * @param  string  $fieldName  Name of the field for error messaging
     * @param  string  $pattern  Regular expression pattern to match against
     * @param  string  $message  Custom error message (empty = default message)
     * @return bool True if value matches pattern, false otherwise
     */
    protected function validatePattern(string $value, string $fieldName, string $pattern, string $message = ''): bool
    {
        $matches = preg_match($pattern, $value);
        if ($matches === false || $matches === 0) {
            $this->addError($message ?: "Field '$fieldName' has invalid format");

            return false;
        }

        return true;
    }

    /**
     * Validate that a string value is a properly formatted email address.
     *
     * Uses PHP's built-in email validation filter to ensure the value
     * conforms to standard email address format requirements.
     *
     * @param  string  $value  Email address to validate
     * @param  string  $fieldName  Name of the field for error messaging
     * @return bool True if valid email format, false otherwise
     */
    protected function validateEmail(string $value, string $fieldName): bool
    {
        $isValid = filter_var($value, FILTER_VALIDATE_EMAIL);
        if ($isValid === false) {
            $this->addError("Field '$fieldName' must be a valid email address");

            return false;
        }

        return true;
    }

    /**
     * Validate that a string value is a properly formatted URL.
     *
     * Uses PHP's built-in URL validation filter to ensure the value
     * conforms to standard URL format requirements including protocol.
     *
     * @param  string  $value  URL to validate
     * @param  string  $fieldName  Name of the field for error messaging
     * @return bool True if valid URL format, false otherwise
     */
    protected function validateUrl(string $value, string $fieldName): bool
    {
        $isValid = filter_var($value, FILTER_VALIDATE_URL);
        if ($isValid === false) {
            $this->addError("Field '$fieldName' must be a valid URL");

            return false;
        }

        return true;
    }

    /**
     * Validate that a value is one of a predefined set of allowed values.
     *
     * Performs strict comparison to ensure the value exactly matches
     * one of the allowed enumeration values. Useful for validating
     * dropdown selections, status codes, or categorical data.
     *
     * @param  mixed  $value  Value to check against allowed values
     * @param  string  $fieldName  Name of the field for error messaging
     * @param  array<string>  $allowedValues  Array of valid enumeration values
     * @return bool True if value is in allowed set, false otherwise
     */
    protected function validateEnum(mixed $value, string $fieldName, array $allowedValues): bool
    {
        if (! in_array($value, $allowedValues, true)) {
            $this->addError("Field '$fieldName' must be one of: ".implode(', ', $allowedValues));

            return false;
        }

        return true;
    }
}
