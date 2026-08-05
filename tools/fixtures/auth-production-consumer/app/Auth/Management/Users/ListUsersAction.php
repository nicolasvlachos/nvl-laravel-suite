<?php

declare(strict_types=1);

namespace App\Auth\Management\Users;

use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Lists consumer users with their business access and package projection.
 */
final readonly class ListUsersAction
{
    public function __construct(private Gate $gate) {}

    /**
     * Return one bounded authorized user page.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function execute(User $actor, int $perPage): LengthAwarePaginator
    {
        $this->gate->forUser($actor)->authorize('auth.users.view');

        return User::query()
            ->with(['roles', 'permissions', 'authPrincipal'])
            ->orderBy('id')
            ->paginate(min(max($perPage, 1), 100));
    }
}
