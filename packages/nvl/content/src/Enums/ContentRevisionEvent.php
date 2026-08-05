<?php

declare(strict_types=1);

namespace Nvl\Content\Enums;

/**
 * Immutable block-history event names persisted with revision snapshots.
 */
enum ContentRevisionEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Published = 'published';
    case Archived = 'archived';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case Migrated = 'migrated';
}
