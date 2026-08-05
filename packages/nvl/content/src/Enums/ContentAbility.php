<?php

declare(strict_types=1);

namespace Nvl\Content\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Fine-grained capabilities passed to the consumer-owned authorization policy.
 */
#[TypeScript]
enum ContentAbility: string
{
    case ListDefinitions = 'list_definitions';
    case ListPlacements = 'list_placements';
    case List = 'list';
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Publish = 'publish';
    case Archive = 'archive';
    case Delete = 'delete';
    case Restore = 'restore';
    case Place = 'place';
    case Unplace = 'unplace';
    case Render = 'render';
    case SyncDefinitions = 'sync_definitions';
    case MigrateDefinitions = 'migrate_definitions';
}
