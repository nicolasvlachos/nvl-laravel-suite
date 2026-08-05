<?php

declare(strict_types=1);

namespace Nvl\Content\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Safe browsing-context targets supported by semantic links and buttons.
 */
#[TypeScript]
enum ContentLinkTarget: string
{
    case SameContext = '_self';
    case NewContext = '_blank';
}
