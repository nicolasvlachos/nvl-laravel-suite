<?php

declare(strict_types=1);

namespace Nvl\Content\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Allowlisted link relationship tokens supported by semantic navigation values.
 */
#[TypeScript]
enum ContentLinkRelationship: string
{
    case NoFollow = 'nofollow';
    case NoOpener = 'noopener';
    case NoReferrer = 'noreferrer';
    case Sponsored = 'sponsored';
    case UserGenerated = 'ugc';
}
