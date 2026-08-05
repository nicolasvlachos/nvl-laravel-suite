<?php

declare(strict_types=1);

namespace Nvl\Csv\Transformers;

/**
 * Chains multiple transformers together.
 */
final class ChainedTransformer extends CSVTransformer
{
    /** @var array<CSVTransformer> */
    private array $transformers;

    /**
     * Create a chained transformer.
     *
     * @param  array<int, CSVTransformer>  $transformers  Transformers to chain
     * @return void
     */
    public function __construct(array $transformers)
    {
        $this->transformers = $transformers;
    }

    /**
     * Add transformer to chain.
     *
     * @param  CSVTransformer  $transformer  Transformer to add
     * @return self Chain instance
     */
    public function add(CSVTransformer $transformer): self
    {
        $this->transformers[] = $transformer;

        return $this;
    }

    /**
     * Transform value through all transformers.
     *
     * @param  mixed  $value  Input value
     * @param  array<string, mixed>  $context  Transformation context
     * @return mixed Transformed value
     */
    public function transform(mixed $value, array $context = []): mixed
    {
        $result = $value;

        foreach ($this->transformers as $transformer) {
            $result = $transformer->transform($result, $context);
        }

        return $result;
    }

    /**
     * Reverse transform through all transformers (in reverse order).
     *
     * @param  mixed  $value  Input value
     * @param  array<string, mixed>  $context  Transformation context
     * @return mixed Reverse-transformed value
     */
    public function reverseTransform(mixed $value, array $context = []): mixed
    {
        $result = $value;

        foreach (array_reverse($this->transformers) as $transformer) {
            if ($transformer->supportsReverseTransform()) {
                $result = $transformer->reverseTransform($result, $context);
            }
        }

        return $result;
    }

    /**
     * Check if all transformers support reverse transformation.
     *
     * @return bool True when all transformers support reverse transformation
     */
    public function supportsReverseTransform(): bool
    {
        foreach ($this->transformers as $transformer) {
            if (! $transformer->supportsReverseTransform()) {
                return false;
            }
        }

        return true;
    }
}
