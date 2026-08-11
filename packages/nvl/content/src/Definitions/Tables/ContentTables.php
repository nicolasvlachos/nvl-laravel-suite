<?php

declare(strict_types=1);

namespace Nvl\Content\Definitions\Tables;

/**
 * Defines the canonical table names owned by the Content package.
 */
final class ContentTables
{
    public const string Definitions = 'content_definitions';

    public const string Blocks = 'content_blocks';

    public const string BlocksI18n = 'content_blocks_i18n';

    public const string Placements = 'content_placements';

    public const string Revisions = 'content_revisions';

    private function __construct() {}
}
