<?php

declare(strict_types=1);

namespace Nvl\Pages\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Allowlisted ordering modes for bounded public child-page reads.
 */
#[TypeScript]
enum PublicChildPageOrder: string
{
    case Sibling = 'sibling';
    case Newest = 'newest';
}
