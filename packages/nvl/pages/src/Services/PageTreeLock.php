<?php

declare(strict_types=1);

namespace Nvl\Pages\Services;

use Illuminate\Support\Facades\DB;
use Nvl\Pages\Support\PagesConfiguration;

/**
 * Serializes every structural mutation for one site through a stable database row.
 */
final class PageTreeLock
{
    /**
     * Acquire the site tree lock for the surrounding database transaction.
     */
    public function acquire(string $site): void
    {
        $connection = DB::connection(PagesConfiguration::connection());
        $table = PagesConfiguration::table('page_tree_locks', 'page_tree_locks');

        $connection->table($table)->insertOrIgnore(['site' => $site]);
        $connection->table($table)
            ->where('site', $site)
            ->lockForUpdate()
            ->first();
    }
}
