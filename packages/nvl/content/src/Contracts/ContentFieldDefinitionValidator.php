<?php

declare(strict_types=1);

namespace Nvl\Content\Contracts;

use Nvl\Content\Schema\ContentFieldDefinition;

/**
 * Optional boot-time validation hook for field-type-specific schema settings.
 */
interface ContentFieldDefinitionValidator
{
    public function validateDefinition(ContentFieldDefinition $field): void;
}
