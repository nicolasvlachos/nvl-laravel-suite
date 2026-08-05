<?php

declare(strict_types=1);

namespace Nvl\Csv\Transformers;

/**
 * Abstract base class for CSV data transformations.
 *
 * Provides a unified interface for transforming CSV field values during import/export operations.
 * Supports forward transformation for data processing and optional reverse transformation
 * for data export or validation scenarios.
 *
 * Transformers can be chained together for complex multi-step transformations
 * or made conditional based on runtime criteria.
 */
abstract class CSVTransformer
{
    /**
     * Transform a CSV field value according to the transformation rules.
     *
     * This is the primary transformation method that must be implemented by all
     * concrete transformer classes. The transformation should be idempotent
     * when possible to ensure consistent results.
     *
     * @param  mixed  $value  The input value to transform
     * @param  array<string, mixed>  $context  Additional context data for transformation (row data, column info, etc.)
     * @return mixed The transformed value
     */
    abstract public function transform(mixed $value, array $context = []): mixed;

    /**
     * Perform reverse transformation to convert processed data back to original format.
     *
     * Default implementation returns the value unchanged. Override this method
     * in subclasses that support bidirectional transformation for export scenarios.
     *
     * @param  mixed  $value  The transformed value to reverse
     * @param  array<string, mixed>  $context  Additional context data for reverse transformation
     * @return mixed The original or approximately original value
     */
    public function reverseTransform(mixed $value, array $context = []): mixed
    {
        return $value;
    }

    /**
     * Check if this transformer supports reverse transformation.
     *
     * Returns false by default. Override to return true in transformers
     * that implement meaningful reverse transformation logic.
     *
     * @return bool True if reverse transformation is supported and implemented
     */
    public function supportsReverseTransform(): bool
    {
        return false;
    }

    /**
     * Create a chained transformer that applies multiple transformations in sequence.
     *
     * The transformers will be applied in the order provided. Each transformer
     * receives the output of the previous transformer as its input.
     *
     * @param  array<int, CSVTransformer>  $transformers  Array of transformers to chain together
     * @return ChainedTransformer A new transformer that applies all transformations sequentially
     */
    public static function chain(array $transformers): ChainedTransformer
    {
        return new ChainedTransformer($transformers);
    }

    /**
     * Create a conditional transformer that applies transformation only when a condition is met.
     *
     * The condition callable receives the value and context, and should return true
     * if the transformation should be applied, false otherwise.
     *
     * @param  callable  $condition  Function (mixed $value, array $context) => bool to determine if transformation should apply
     * @param  CSVTransformer  $transformer  The transformer to apply when condition is true
     * @return ConditionalTransformer A new conditional transformer
     */
    public static function when(callable $condition, CSVTransformer $transformer): ConditionalTransformer
    {
        return new ConditionalTransformer($condition, $transformer);
    }
}
