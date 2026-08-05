<?php

declare(strict_types=1);

namespace Nvl\Content\FieldTypes;

use InvalidArgumentException;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Performs outer-shape checks; ContentValueValidator handles recursive fields.
 */
final class StructuredFieldTypeAdapter extends AbstractFieldTypeAdapter
{
    public function __construct(
        private readonly string $type,
        private readonly bool $list,
    ) {}

    public function alias(): string
    {
        return $this->type;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function normalize(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): ?array {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)
            || ($this->list && ! array_is_list($value))
            || (! $this->list && $value !== [] && array_is_list($value))) {
            $shape = $this->list ? 'a list' : 'an object';

            throw new InvalidArgumentException(
                "Content field [{$context->path}] must be {$shape}.",
            );
        }

        return $value;
    }
}
