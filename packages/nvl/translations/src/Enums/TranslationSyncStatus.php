<?php

declare(strict_types=1);

namespace Nvl\Translations\Enums;

/**
 * Describes how one editable catalog row relates to its authoritative source file.
 */
enum TranslationSyncStatus: string
{
    case Synchronized = 'synchronized';
    case Edited = 'edited';
    case Conflict = 'conflict';
    case Missing = 'missing';
}
