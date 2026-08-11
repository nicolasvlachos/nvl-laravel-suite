<?php

declare(strict_types=1);

namespace Nvl\Pages\Definitions\Tables;

/**
 * Defines the canonical table names owned by the Pages package.
 */
final class PagesTables
{
    public const string Pages = 'pages';

    public const string I18n = 'pages_i18n';

    public const string TreeLocks = 'page_tree_locks';

    private function __construct() {}
}
