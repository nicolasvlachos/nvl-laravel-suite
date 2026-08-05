<?php

declare(strict_types=1);

namespace Nvl\Content\FieldTypes;

use Nvl\Content\Contracts\ContentFieldTypeAdapter;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Default display behavior for adapters whose normalized form is renderable.
 */
abstract class AbstractFieldTypeAdapter implements ContentFieldTypeAdapter
{
    public function render(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): mixed {
        return $value;
    }
}
