<?php

declare(strict_types=1);

namespace Nvl\Csv\ValueObjects;

use Closure;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use Nvl\Csv\Enums\CSVTypeEnum;

/**
 * Immutable value object for CSV field mapping configuration.
 *
 * Defines comprehensive field transformation rules for CSV import operations:
 * - Field name mapping from source CSV to target structure
 * - Type validation and casting with built-in CSV types
 * - Custom transformation functions for complex data processing
 * - Validation rules with detailed error reporting
 * - Uniqueness constraints and indexing hints
 * - Nullable and required field constraints
 */
final readonly class CSVFieldMapping
{
    /**
     * Create a comprehensive CSV field mapping with full configuration.
     *
     * @param  string  $sourceField  Source column name in CSV file
     * @param  string  $targetField  Target field name in destination structure
     * @param  CSVTypeEnum|null  $type  Data type for validation and casting
     * @param  bool  $required  Whether field must have a non-empty value
     * @param  mixed  $defaultValue  Fallback value for empty/missing fields
     * @param  Closure|null  $transformer  Custom transformation function (value) => transformed
     * @param  array<int, callable>  $validators  Array of validation functions
     * @param  bool  $unique  Whether values in this field must be unique across rows
     * @param  bool  $nullable  Whether null values are acceptable
     * @param  string|null  $format  Format string for date/number parsing
     * @param  array<string, mixed>  $metadata  Additional configuration metadata
     */
    public function __construct(
        public string $sourceField,                    // CSV column header name
        public string $targetField,                    // Destination field identifier
        public ?CSVTypeEnum $type = null,              // Type validation/casting
        public bool $required = false,                 // Field is mandatory
        public mixed $defaultValue = null,             // Fallback for empty values
        public ?Closure $transformer = null,           // Custom transformation
        public array $validators = [],                 // Additional validation rules
        public bool $unique = false,                   // Uniqueness constraint
        public bool $nullable = true,                  // Allow null values
        public ?string $format = null,                 // Parsing format hint
        public array $metadata = [],                   // Extension metadata
    ) {}

    /**
     * Serialize callback-bearing mappings safely for queued chunk jobs.
     *
     * @return array{
     *     sourceField: string,
     *     targetField: string,
     *     type: CSVTypeEnum|null,
     *     required: bool,
     *     defaultValue: mixed,
     *     transformer: SerializableClosure|null,
     *     validators: list<callable|SerializableClosure>,
     *     unique: bool,
     *     nullable: bool,
     *     format: string|null,
     *     metadata: array<string, mixed>
     * }
     */
    public function __serialize(): array
    {
        return [
            'sourceField' => $this->sourceField,
            'targetField' => $this->targetField,
            'type' => $this->type,
            'required' => $this->required,
            'defaultValue' => $this->defaultValue,
            'transformer' => $this->transformer === null
                ? null
                : new SerializableClosure($this->transformer),
            'validators' => array_values(array_map(
                fn (callable $validator): callable|SerializableClosure => $validator instanceof Closure
                    ? new SerializableClosure($validator)
                    : $validator,
                $this->validators,
            )),
            'unique' => $this->unique,
            'nullable' => $this->nullable,
            'format' => $this->format,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Restore a mapping serialized for a queue payload.
     *
     * @param  array{
     *     sourceField: string,
     *     targetField: string,
     *     type: CSVTypeEnum|null,
     *     required: bool,
     *     defaultValue: mixed,
     *     transformer: SerializableClosure|null,
     *     validators: list<callable|SerializableClosure>,
     *     unique: bool,
     *     nullable: bool,
     *     format: string|null,
     *     metadata: array<string, mixed>
     * }  $data
     */
    public function __unserialize(array $data): void
    {
        $this->sourceField = $data['sourceField'];
        $this->targetField = $data['targetField'];
        $this->type = $data['type'];
        $this->required = $data['required'];
        $this->defaultValue = $data['defaultValue'];
        $this->transformer = $data['transformer']?->getClosure();
        $this->validators = array_map(
            fn (callable|SerializableClosure $validator): callable => $validator instanceof SerializableClosure
                ? $validator->getClosure()
                : $validator,
            $data['validators'],
        );
        $this->unique = $data['unique'];
        $this->nullable = $data['nullable'];
        $this->format = $data['format'];
        $this->metadata = $data['metadata'];
    }

    /**
     * Create a basic field mapping without type validation or transformation.
     *
     * Simple 1:1 field mapping for direct value transfer. No validation
     * or transformation applied - values pass through unchanged.
     *
     * @param  string  $sourceField  CSV column name
     * @param  string  $targetField  Target field name
     * @return self Basic mapping instance
     */
    public static function simple(string $sourceField, string $targetField): self
    {
        return new self(
            sourceField: $sourceField,
            targetField: $targetField,
        );
    }

    /**
     * Create a field mapping with type validation and casting.
     *
     * Applies automatic type validation and conversion using CSV type system.
     * Automatically sets nullable based on required flag for consistency.
     *
     * @param  string  $sourceField  CSV column name
     * @param  string  $targetField  Target field name
     * @param  CSVTypeEnum  $type  Expected data type for validation/casting
     * @param  bool  $required  Whether field must have a value
     * @return self Typed mapping instance
     */
    public static function typed(
        string $sourceField,
        string $targetField,
        CSVTypeEnum $type,
        bool $required = false
    ): self {
        return new self(
            sourceField: $sourceField,
            targetField: $targetField,
            type: $type,
            required: $required,
            nullable: ! $required, // Nullable is inverse of required for consistency
        );
    }

    /**
     * Create a field mapping with custom transformation function.
     *
     * Applies custom transformation logic to field values during processing.
     * Transformer receives the raw value and should return the transformed value.
     *
     * @param  string  $sourceField  CSV column name
     * @param  string  $targetField  Target field name
     * @param  Closure  $transformer  Function that transforms values (mixed) => mixed
     * @return self Mapping with transformation
     */
    public static function withTransformer(
        string $sourceField,
        string $targetField,
        Closure $transformer
    ): self {
        return new self(
            sourceField: $sourceField,
            targetField: $targetField,
            transformer: $transformer,
        );
    }

    /**
     * Apply the complete field mapping transformation pipeline to a value.
     *
     * Executes the transformation in the following order:
     * 1. Apply default value if input is empty
     * 2. Apply type casting using CSVTypeEnum system
     * 3. Apply custom transformer function if configured
     *
     * @param  mixed  $value  Raw input value from CSV
     * @return mixed Fully transformed and typed value
     *
     * @throws InvalidArgumentException If type casting fails
     */
    public function apply(mixed $value): mixed
    {
        // Step 1: Apply default value for empty inputs
        if ($this->isEmpty($value) && $this->defaultValue !== null) {
            return $this->defaultValue;
        }

        // Step 2: Apply type casting and validation
        if ($this->type !== null) {
            $value = $this->type->cast($value);
        }

        // Step 3: Apply custom transformation function
        if ($this->transformer !== null) {
            $value = ($this->transformer)($value);
        }

        return $value;
    }

    /**
     * Validate a value against all mapping rules and constraints.
     *
     * Performs comprehensive validation in order:
     * 1. Required field validation
     * 2. Nullable constraint validation
     * 3. Type compatibility validation
     * 4. Custom validator function execution
     *
     * @param  mixed  $value  Value to validate
     * @return bool True if value passes all validation rules
     */
    public function validate(mixed $value): bool
    {
        // Validation 1: Check required field constraint
        if ($this->required && $this->isEmpty($value)) {
            return false; // Required field cannot be empty
        }

        // Validation 2: Check nullable constraint
        if (! $this->nullable && $value === null) {
            return false; // Non-nullable field cannot be null
        }

        // Validation 3: Check type compatibility (skip for empty values)
        if ($this->type !== null && ! $this->isEmpty($value)) {
            if (! $this->type->validate($value)) {
                return false; // Value doesn't match expected type
            }
        }

        // Validation 4: Run all custom validator functions
        foreach ($this->validators as $validator) {
            if (! $validator($value)) {
                return false; // Custom validation failed
            }
        }

        return true; // All validations passed
    }

    /**
     * Check if a value is considered empty for CSV processing.
     *
     * Empty values are: null, empty string, or empty array.
     * Used to determine when to apply default values or skip type validation.
     *
     * @param  mixed  $value  Value to check for emptiness
     * @return bool True if value should be treated as empty
     */
    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * Check if this field requires indexing for uniqueness constraints.
     *
     * Returns true if the unique constraint is enabled, indicating that
     * values should be tracked to prevent duplicates during processing.
     *
     * @return bool True if field values should be indexed for uniqueness
     */
    public function shouldIndex(): bool
    {
        return $this->unique;
    }

    /**
     * Get detailed validation error messages for a specific value.
     *
     * Performs the same validation as validate() but returns descriptive
     * error messages instead of a boolean result. Useful for providing
     * user-friendly feedback during CSV import operations.
     *
     * @param  mixed  $value  Value to validate
     * @return array<int, string> Array of human-readable error messages
     */
    public function getValidationErrors(mixed $value): array
    {
        $errors = [];

        // Check required field constraint
        if ($this->required && $this->isEmpty($value)) {
            $errors[] = "Field '{$this->sourceField}' is required";
        }

        // Check nullable constraint
        if (! $this->nullable && $value === null) {
            $errors[] = "Field '{$this->sourceField}' cannot be null";
        }

        // Check type validation (only for non-empty values)
        if ($this->type !== null && ! $this->isEmpty($value)) {
            if (! $this->type->validate($value)) {
                $errors[] = "Field '{$this->sourceField}' must be of type {$this->type->value}";
            }
        }

        return $errors;
    }

    /**
     * Convert field mapping to associative array representation.
     *
     * Provides serializable representation of the mapping configuration
     * suitable for logging, debugging, API responses, and persistence.
     * Closures are represented by their presence flags rather than content.
     *
     * @return array<string, mixed> Complete mapping configuration as array
     */
    public function toArray(): array
    {
        return [
            'source_field' => $this->sourceField,
            'target_field' => $this->targetField,
            'type' => $this->type?->value,
            'required' => $this->required,
            'default_value' => $this->defaultValue,
            'has_transformer' => $this->transformer !== null,
            'validators_count' => count($this->validators),
            'unique' => $this->unique,
            'nullable' => $this->nullable,
            'format' => $this->format,
            'metadata' => $this->metadata,
        ];
    }
}
