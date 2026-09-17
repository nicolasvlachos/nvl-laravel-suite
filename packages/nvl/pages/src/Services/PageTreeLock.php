<?php

declare(strict_types=1);

namespace Nvl\Pages\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Nvl\Pages\Definitions\Tables\PagesTables;
use Nvl\Pages\Support\PagesConfiguration;
use Nvl\Tenancy\Contracts\TenantContext;

/**
 * Serializes every structural mutation for one site through a stable database row.
 */
final readonly class PageTreeLock
{
    /** Create the tenant-aware page-tree lock boundary. */
    public function __construct(private Repository $configuration, private TenantContext $tenancy) {}

    /**
     * Acquire the site tree lock for the surrounding database transaction.
     */
    public function acquire(string $site): void
    {
        $connection = DB::connection(PagesConfiguration::connection());
        $table = PagesConfiguration::table(PagesTables::TreeLocks, PagesTables::TreeLocks);

        $identity = ['site' => $site];
        if ($this->configuration->get('tenancy.enabled') === true) {
            $identity = ['tenant_id' => $this->tenancy->requireTenant()->value, ...$identity];
        }
        $connection->table($table)->insertOrIgnore($identity);
        $connection->table($table)
            ->where($identity)
            ->lockForUpdate()
            ->first();
    }
}
