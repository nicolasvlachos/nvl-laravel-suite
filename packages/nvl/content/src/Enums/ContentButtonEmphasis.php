<?php

declare(strict_types=1);

namespace Nvl\Content\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Presentation-neutral semantic priority for linked calls to action.
 */
#[TypeScript]
enum ContentButtonEmphasis: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Tertiary = 'tertiary';
}
