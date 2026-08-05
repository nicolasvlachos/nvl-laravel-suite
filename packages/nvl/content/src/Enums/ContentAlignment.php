<?php

declare(strict_types=1);

namespace Nvl\Content\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Direction-aware semantic alignment for adaptable rich content sections.
 */
#[TypeScript]
enum ContentAlignment: string
{
    case Start = 'start';
    case Center = 'center';
    case End = 'end';
}
