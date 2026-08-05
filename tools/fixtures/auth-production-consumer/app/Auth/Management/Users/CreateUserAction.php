<?php

declare(strict_types=1);

namespace App\Auth\Management\Users;

use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Nvl\Auth\Actions\Principals\EnsurePrincipalProjectionAction;
use Nvl\Auth\Data\Principals\EnsurePrincipalData;

/**
 * Creates a host user, security projection, and allowlisted access atomically.
 *
 * Approved orchestration: the host write deliberately composes the package's
 * principal-projection Action so consumer identity and package trust cannot drift.
 */
final readonly class CreateUserAction
{
    public function __construct(
        private Gate $gate,
        private Hasher $hasher,
        private EnsurePrincipalProjectionAction $principals,
        private AccessAssignmentValidator $assignments,
    ) {}

    /**
     * Create one application-owned user.
     *
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function execute(
        User $actor,
        string $name,
        string $email,
        string $password,
        array $roles,
        array $permissions,
    ): User {
        $this->gate->forUser($actor)->authorize('auth.users.create');
        $this->assignments->validate($roles, $permissions);

        return DB::transaction(function () use (
            $email,
            $name,
            $password,
            $permissions,
            $roles,
        ): User {
            $user = User::query()->create([
                'name' => trim($name),
                'email' => mb_strtolower(trim($email)),
                'password' => $this->hasher->make($password),
            ]);
            $this->principals->execute(new EnsurePrincipalData(
                subjectType: $user->getMorphClass(),
                subjectId: (string) $user->getKey(),
            ));
            $user->syncRoles($roles);
            $user->syncPermissions($permissions);

            return $user->load(['roles', 'permissions', 'authPrincipal']);
        }, 3);
    }
}
