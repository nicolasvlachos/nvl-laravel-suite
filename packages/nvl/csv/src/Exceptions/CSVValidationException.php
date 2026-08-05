<?php

declare(strict_types=1);

namespace Nvl\Csv\Exceptions;

/**
 * Exception for CSV validation errors
 */
final class CSVValidationException extends CSVException
{
    /**
     * @var array<int, array<string, array<string>>>
     */
    protected array $validationErrors = [];

    /**
     * Create exception for validation failure.
     *
     * @param  array<int, array<string, array<string>>>  $errors  Validation errors by row
     * @return self Exception instance
     */
    public static function validationFailed(array $errors): self
    {
        $errorCount = count($errors);
        $message = "CSV validation failed with {$errorCount} error(s)";

        $exception = new self($message);
        $exception->validationErrors = $errors;
        $exception->withContext(['errors' => $errors]);

        return $exception;
    }

    /**
     * Create exception for row validation failure.
     *
     * @param  int  $row  Row number
     * @param  array<string, array<string>>  $errors  Validation errors for row
     * @return self Exception instance
     */
    public static function rowValidationFailed(int $row, array $errors): self
    {
        $fieldCount = count($errors);
        $message = "Row {$row} validation failed with {$fieldCount} field error(s)";

        $exception = new self($message);
        $exception->validationErrors = [$row => $errors];
        $exception->withContext([
            'row' => $row,
            'errors' => $errors,
        ]);

        return $exception;
    }

    /**
     * Create exception for field validation failure.
     *
     * @param  string  $field  Field name
     * @param  mixed  $value  Field value
     * @param  string  $rule  Validation rule
     * @return self Exception instance
     */
    public static function fieldValidationFailed(string $field, mixed $value, string $rule): self
    {
        $valueStr = is_scalar($value) ? (string) $value : gettype($value);

        return (new self("Field '{$field}' with value '{$valueStr}' failed validation rule: {$rule}"))
            ->withContext([
                'field' => $field,
                'value' => $value,
                'rule' => $rule,
            ]);
    }

    /**
     * Create exception for type validation failure.
     *
     * @param  string  $field  Field name
     * @param  mixed  $value  Field value
     * @param  string  $expectedType  Expected type description
     * @return self Exception instance
     */
    public static function invalidType(string $field, mixed $value, string $expectedType): self
    {
        $actualType = gettype($value);

        return (new self("Field '{$field}' expected type '{$expectedType}', got '{$actualType}'"))
            ->withContext([
                'field' => $field,
                'value' => $value,
                'expected_type' => $expectedType,
                'actual_type' => $actualType,
            ]);
    }

    /**
     * Create exception for required field missing.
     *
     * @param  string  $field  Field name
     * @param  int  $row  Row number
     * @return self Exception instance
     */
    public static function requiredFieldMissing(string $field, int $row): self
    {
        return (new self("Required field '{$field}' is missing or empty at row {$row}"))
            ->withContext([
                'field' => $field,
                'row' => $row,
            ]);
    }

    /**
     * Create exception for unique constraint violation.
     *
     * @param  string  $field  Field name
     * @param  mixed  $value  Field value
     * @param  int  $row  Row number
     * @return self Exception instance
     */
    public static function duplicateValue(string $field, mixed $value, int $row): self
    {
        $valueStr = is_scalar($value) ? (string) $value : gettype($value);

        return (new self("Duplicate value '{$valueStr}' for unique field '{$field}' at row {$row}"))
            ->withContext([
                'field' => $field,
                'value' => $value,
                'row' => $row,
            ]);
    }

    /**
     * Get validation errors.
     *
     * @return array<int, array<string, array<string>>> Validation errors by row
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Get errors for a specific row.
     *
     * @param  int  $row  Row number
     * @return array<string, array<string>>|null Row errors or null
     */
    public function getRowErrors(int $row): ?array
    {
        return $this->validationErrors[$row] ?? null;
    }

    /**
     * Get total error count.
     *
     * @return int Error count
     */
    public function getErrorCount(): int
    {
        $count = 0;
        foreach ($this->validationErrors as $rowErrors) {
            $count += count($rowErrors);
        }

        return $count;
    }

    /**
     * Get affected rows.
     *
     * @return array<int> Row numbers with errors
     */
    public function getAffectedRows(): array
    {
        return array_keys($this->validationErrors);
    }

    /**
     * Create exception for missing required fields.
     *
     * @param  array<int, string>  $fields  Missing field names
     * @return self Exception instance
     */
    public static function missingRequiredFields(array $fields): self
    {
        $fieldList = implode(', ', $fields);
        $message = "Missing required fields: {$fieldList}";

        return (new self($message))
            ->withContext(['missing_fields' => $fields]);
    }
}
