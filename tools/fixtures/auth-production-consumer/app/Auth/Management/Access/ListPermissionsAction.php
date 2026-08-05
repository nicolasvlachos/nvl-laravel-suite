<?php

declare(strict_types=1);

namespace App\Auth\Management\Access;

use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission;

/**
 * Lists synchronized permissions for authorized management consumers.
 */
final readonly class ListPermissionsAction
{
    public function __construct(private Gate $gate) {}

    /** @return Collection<int, Permission> */
    public function execute(User $actor): Collection
    {
        $this->gate->forUser($actor)->authorize('auth.permissions.view');

        return Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get();
    }
}
