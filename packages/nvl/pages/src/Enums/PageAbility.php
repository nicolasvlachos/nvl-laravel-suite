<?php

declare(strict_types=1);

namespace Nvl\Pages\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Fine-grained capabilities enforced by every public Page action.
 */
#[TypeScript]
enum PageAbility: string
{
    case List = 'list';
    case View = 'view';
    case ViewNavigation = 'view_navigation';
    case Preview = 'preview';
    case Create = 'create';
    case Update = 'update';
    case Move = 'move';
    case Publish = 'publish';
    case Archive = 'archive';
    case Delete = 'delete';
    case Restore = 'restore';
}
