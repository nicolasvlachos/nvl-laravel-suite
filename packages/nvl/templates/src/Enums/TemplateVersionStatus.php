<?php

declare(strict_types=1);

namespace Nvl\Templates\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Represents the publication state of an immutable template version.
 */
#[TypeScript]
enum TemplateVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
