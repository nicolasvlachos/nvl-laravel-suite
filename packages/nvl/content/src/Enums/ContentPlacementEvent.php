<?php

declare(strict_types=1);

namespace Nvl\Content\Enums;

/**
 * Committed lifecycle changes emitted for owner placement facts.
 */
enum ContentPlacementEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
