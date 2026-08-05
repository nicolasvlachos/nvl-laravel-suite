<?php

declare(strict_types=1);

namespace Nvl\Csv\Transformers;

use Closure;

/**
 * Applies transformation conditionally.
 */
final class ConditionalTransformer extends CSVTransformer
{
    private Closure $condition;

    private CSVTransformer $transformer;

    private ?CSVTransformer $elseTransformer = null;

    /**
     * Create a conditional transformer.
     *
     * @param  callable  $condition  Condition callback
     * @param  CSVTransformer  $transformer  Transformer when condition passes
     * @return void
     */
    public function __construct(callable $condition, CSVTransformer $transformer)
    {
        $this->condition = Closure::fromCallable($condition);
        $this->transformer = $transformer;
    }

    /**
     * Set else transformer.
     *
     * @param  CSVTransformer  $transformer  Transformer when condition fails
     * @return self Transformer instance
     */
    public function else(CSVTransformer $transformer): self
    {
        $this->elseTransformer = $transformer;

        return $this;
    }

    /**
     * Transform value conditionally.
     *
     * @param  mixed  $value  Input value
     * @param  array<string, mixed>  $context  Transformation context
     * @return mixed Transformed value
     */
    public function transform(mixed $value, array $context = []): mixed
    {
        if (($this->condition)($value, $context)) {
            return $this->transformer->transform($value, $context);
        }

        if ($this->elseTransformer !== null) {
            return $this->elseTransformer->transform($value, $context);
        }

        return $value;
    }
}
