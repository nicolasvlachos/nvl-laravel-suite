<?php

declare(strict_types=1);

namespace App\Auth\Management\Access;

use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Nvl\Auth\Actions\Authorization\SynchronizePermissionCatalogAction;
use Nvl\Auth\Results\PermissionSynchronizationResult;

/**
 * Exposes package catalog synchronization behind host-owned authorization.
 *
 * Approved orchestration: this host authorization boundary deliberately delegates
 * the catalog mutation to the package-owned synchronization Action.
 */
final readonly class SynchronizeAccessCatalogAction
{
    public function __construct(
        private Gate $gate,
        private SynchronizePermissionCatalogAction $catalog,
    ) {}

    /**
     * Synchronize additively; destructive pruning remains a deployment command.
     */
    public function execute(User $actor): PermissionSynchronizationResult
    {
        $this->gate->forUser($actor)->authorize('auth.catalog.sync');

        return $this->catalog->execute(
            prunePermissions: false,
            pruneRoles: false,
        );
    }
}
