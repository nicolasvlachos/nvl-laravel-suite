<?php

declare(strict_types=1);

namespace Nvl\Csv\Validators;

use Closure;
use Nvl\Csv\ValueObjects\CSVFieldMapping;

/**
 * Validates entire CSV rows with multiple field rules.
 */
final class CSVRowValidator extends CSVValidator
{
    /** @var array<string, CSVFieldValidator> */
    private array $fieldValidators = [];

    /** @var array<Closure> */
    private array $rowValidators = [];

    /** @var array<string, CSVFieldMapping> */
    private array $fieldMappings = [];

    /**
     * Add field validator.
     *
     * @param  string  $field  Field name
     * @param  CSVFieldValidator  $validator  Field validator
     * @return self Validator instance
     */
    public function addFieldValidator(string $field, CSVFieldValidator $validator): self
    {
        $this->fieldValidators[$field] = $validator;

        return $this;
    }

    /**
     * Add field mapping.
     *
     * @param  string  $field  Field name
     * @param  CSVFieldMapping  $mapping  Field mapping definition
     * @return self Validator instance
     */
    public function addFieldMapping(string $field, CSVFieldMapping $mapping): self
    {
        $this->fieldMappings[$field] = $mapping;

        return $this;
    }

    /**
     * Add row-level validator.
     *
     * @param  Closure  $validator  Row validation callback
     * @return self Validator instance
     */
    public function addRowValidator(Closure $validator): self
    {
        $this->rowValidators[] = $validator;

        return $this;
    }

    /**
     * Validate an entire row.
     *
     * @param  mixed  $value  The row data to validate
     * @param  array<string, mixed>  $context  Additional validation context
     * @return bool True when row is valid
     */
    public function validate(mixed $value, array $context = []): bool
    {
        $this->clearMessages();

        if (! is_array($value)) {
            $this->addError('Row must be an array');

            return false;
        }

        $isValid = true;

        // Validate individual fields
        foreach ($this->fieldValidators as $field => $validator) {
            $fieldValue = $value[$field] ?? null;

            /** @var array<string, mixed> $context */
            $context = $value;

            if (! $validator->validate($fieldValue, $context)) {
                $isValid = false;
                foreach ($validator->getErrors() as $error) {
                    $this->addError("Field '$field': $error");
                }
            }
        }

        // Apply field mappings and validate
        foreach ($this->fieldMappings as $field => $mapping) {
            $fieldValue = $value[$field] ?? $mapping->defaultValue;

            if (! $mapping->validate($fieldValue)) {
                $isValid = false;
                foreach ($mapping->getValidationErrors($fieldValue) as $error) {
                    $this->addError($error);
                }
            }
        }

        // Apply row-level validators
        foreach ($this->rowValidators as $validator) {
            $result = $validator($value);

            if ($result === false) {
                $isValid = false;
                $this->addError('Row validation failed');
            } elseif (is_string($result)) {
                $isValid = false;
                $this->addError($result);
            }
        }

        return $isValid;
    }

    /**
     * Validate cross-field dependencies.
     *
     * @param  array<string, mixed>  $row  Row data
     * @return bool True when dependencies are satisfied
     */
    public function validateDependencies(array $row): bool
    {
        $isValid = true;

        // Example: Validate that if field A has value X, field B must be present
        // This can be extended based on specific requirements

        return $isValid;
    }

    /**
     * Validate row completeness.
     *
     * @param  array<string, mixed>  $row  Row data
     * @param  array<int, string>  $requiredFields  Required field names
     * @return bool True when required fields are present
     */
    public function validateCompleteness(array $row, array $requiredFields): bool
    {
        $missingFields = [];

        foreach ($requiredFields as $field) {
            if (! isset($row[$field]) || $this->isEmpty($row[$field])) {
                $missingFields[] = $field;
            }
        }

        if (! empty($missingFields)) {
            $this->addError('Missing required fields: '.implode(', ', $missingFields));

            return false;
        }

        return true;
    }

    /**
     * Validate row uniqueness.
     *
     * @param  array<string, mixed>  $row  Row data
     * @param  array<int, array<string, mixed>>  $existingRows  Existing rows to compare
     * @param  array<int, string>  $uniqueFields  Fields that must be unique
     * @return bool True when row is unique
     */
    public function validateUniqueness(array $row, array $existingRows, array $uniqueFields): bool
    {
        foreach ($existingRows as $existingRow) {
            $isDuplicate = true;

            foreach ($uniqueFields as $field) {
                if (! isset($row[$field]) || ! isset($existingRow[$field]) ||
                    $row[$field] !== $existingRow[$field]) {
                    $isDuplicate = false;
                    break;
                }
            }

            if ($isDuplicate) {
                $this->addError('Duplicate row found based on fields: '.implode(', ', $uniqueFields));

                return false;
            }
        }

        return true;
    }
}
