<?php

declare(strict_types=1);

namespace Nvl\Pages\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Editorial lifecycle for a page.
 */
#[TypeScript]
enum PageStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';
}
