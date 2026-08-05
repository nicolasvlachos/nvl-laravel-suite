<?php

declare(strict_types=1);

namespace Nvl\Translations\Enums;

/**
 * Stable capabilities for the optional Translations management API.
 */
enum TranslationsAbility: string
{
    case ListEntries = 'list_entries';
    case UpdateEntry = 'update_entry';
    case Synchronize = 'synchronize';
    case Export = 'export';
    case Prune = 'prune';
    case Scan = 'scan';
}
