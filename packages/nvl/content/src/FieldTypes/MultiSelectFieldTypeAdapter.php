<?php

declare(strict_types=1);

namespace Nvl\Content\FieldTypes;

use InvalidArgumentException;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Normalizes a bounded unique list from an allowlisted option set.
 */
final class MultiSelectFieldTypeAdapter extends AbstractFieldTypeAdapter
{
    public function alias(): string
    {
        return 'multi_select';
    }

    /**
     * @return list<string>|null
     */
    public function normalize(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): ?array {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Content field [{$context->path}] must be a list.");
        }

        $maximum = $field->setting(
            'max_items',
            ContentConfiguration::positiveInteger('content.validation.maximum_items', 500),
        );

        if (! is_int($maximum) || count($value) > $maximum) {
            throw new InvalidArgumentException("Content field [{$context->path}] has too many values.");
        }

        $options = $field->setting('options', []);
        $allowed = ! is_array($options)
            ? []
            : (array_is_list($options) ? $options : array_keys($options));

        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                throw new InvalidArgumentException(
                    "Content field [{$context->path}] contains an unavailable option.",
                );
            }
        }

        return array_values(array_unique($value));
    }
}
