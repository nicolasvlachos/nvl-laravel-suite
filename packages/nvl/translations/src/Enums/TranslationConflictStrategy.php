<?php

declare(strict_types=1);

namespace Nvl\Translations\Enums;

/**
 * Determines which side wins when both a source file and database entry changed.
 */
enum TranslationConflictStrategy: string
{
    case Fail = 'fail';
    case PreferFile = 'prefer_file';
    case PreferDatabase = 'prefer_database';
}
