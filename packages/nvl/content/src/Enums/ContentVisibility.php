<?php

declare(strict_types=1);

namespace Nvl\Content\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Delivery policy classification applied to blocks and referenced Media.
 */
#[TypeScript]
enum ContentVisibility: string
{
    case Public = 'public';
    case Private = 'private';
}
