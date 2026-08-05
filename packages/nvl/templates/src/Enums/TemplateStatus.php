<?php

declare(strict_types=1);

namespace Nvl\Templates\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Represents the lifecycle state of a template definition.
 */
#[TypeScript]
enum TemplateStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';
}
