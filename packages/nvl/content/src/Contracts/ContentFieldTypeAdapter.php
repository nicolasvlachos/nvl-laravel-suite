<?php

declare(strict_types=1);

namespace Nvl\Content\Contracts;

use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Pluggable field validation, normalization, and display contract.
 */
interface ContentFieldTypeAdapter
{
    public function alias(): string;

    public function normalize(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): mixed;

    public function render(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): mixed;
}
