<?php

declare(strict_types=1);

namespace Nvl\Content\FieldTypes;

use InvalidArgumentException;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Normalizes integer and finite floating-point fields.
 */
final class NumberFieldTypeAdapter extends AbstractFieldTypeAdapter
{
    public function __construct(private readonly bool $integer) {}

    public function alias(): string
    {
        return $this->integer ? 'integer' : 'number';
    }

    public function normalize(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): int|float|null {
        if ($value === null) {
            return null;
        }

        if ($this->integer && ! is_int($value)) {
            throw new InvalidArgumentException("Content field [{$context->path}] must be an integer.");
        }

        if (! $this->integer && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("Content field [{$context->path}] must be numeric.");
        }

        $number = $this->integer ? $value : (float) $value;

        if (is_float($number) && ! is_finite($number)) {
            throw new InvalidArgumentException("Content field [{$context->path}] must be finite.");
        }

        $minimum = $field->setting('min');
        $maximum = $field->setting('max');

        if ((is_int($minimum) || is_float($minimum)) && $number < $minimum) {
            throw new InvalidArgumentException("Content field [{$context->path}] is below its minimum.");
        }

        if ((is_int($maximum) || is_float($maximum)) && $number > $maximum) {
            throw new InvalidArgumentException("Content field [{$context->path}] exceeds its maximum.");
        }

        return $number;
    }
}
