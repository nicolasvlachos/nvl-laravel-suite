<?php

declare(strict_types=1);

namespace Nvl\Pages\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Distinguishes persisted pages from resource-backed route definitions.
 */
#[TypeScript]
enum PageKind: string
{
    case Static = 'static';
    case Resource = 'resource';
}
