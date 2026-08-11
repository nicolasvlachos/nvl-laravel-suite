<?php

declare(strict_types=1);

namespace Nvl\Translations\Definitions\Tables;

/**
 * Database table names for the Translations module.
 */
final class TranslationsTables
{
    public const string Entries = 'translation_entries';

    public const string ScanRuns = 'translation_scan_runs';

    public const string Usages = 'translation_usages';

    public const string TRANSLATION_ENTRIES = self::Entries;

    public const string TRANSLATION_SCAN_RUNS = self::ScanRuns;

    public const string TRANSLATION_USAGES = self::Usages;

    private function __construct() {}
}
