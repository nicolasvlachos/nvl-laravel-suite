<?php

declare(strict_types=1);

namespace App\Auth\Management\Access;

use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;

/**
 * Lists synchronized role templates for authorized management consumers.
 */
final readonly class ListRolesAction
{
    public function __construct(private Gate $gate) {}

    /** @return Collection<int, Role> */
    public function execute(User $actor): Collection
    {
        $this->gate->forUser($actor)->authorize('auth.roles.view');

        return Role::query()
            ->where('guard_name', 'web')
            ->with('permissions')
            ->orderBy('name')
            ->get();
    }
}
