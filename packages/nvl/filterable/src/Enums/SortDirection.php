<?php

declare(strict_types=1);

namespace Nvl\Filterable\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Directions accepted by allowlisted sorts.
 */
#[TypeScript]
enum SortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
