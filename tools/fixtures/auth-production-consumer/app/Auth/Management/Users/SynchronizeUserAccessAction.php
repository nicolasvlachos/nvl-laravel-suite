<?php

declare(strict_types=1);

namespace App\Auth\Management\Users;

use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;

/**
 * Synchronizes one user's catalog-backed roles and direct permissions.
 */
final readonly class SynchronizeUserAccessAction
{
    public function __construct(
        private Gate $gate,
        private AccessAssignmentValidator $assignments,
    ) {}

    /**
     * Replace access assignments with an allowlisted deterministic set.
     *
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function execute(
        User $actor,
        User $user,
        array $roles,
        array $permissions,
    ): User {
        $this->gate->forUser($actor)->authorize('auth.users.assign-access');
        $this->assignments->validate($roles, $permissions);

        return DB::transaction(function () use (
            $permissions,
            $roles,
            $user,
        ): User {
            $user->syncRoles($roles);
            $user->syncPermissions($permissions);

            return $user->load(['roles', 'permissions', 'authPrincipal']);
        }, 3);
    }
}
