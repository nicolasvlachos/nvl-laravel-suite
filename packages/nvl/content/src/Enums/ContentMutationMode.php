<?php

declare(strict_types=1);

namespace Nvl\Content\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Selects non-destructive deep patching or complete replacement semantics.
 */
#[TypeScript]
enum ContentMutationMode: string
{
    case Replace = 'replace';
    case Patch = 'patch';
}
