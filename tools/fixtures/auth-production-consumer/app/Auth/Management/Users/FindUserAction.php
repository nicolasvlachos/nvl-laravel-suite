<?php

declare(strict_types=1);

namespace App\Auth\Management\Users;

use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * Loads one authorized consumer user projection.
 */
final readonly class FindUserAction
{
    public function __construct(private Gate $gate) {}

    /**
     * Return the user with its access and package security projection.
     */
    public function execute(User $actor, User $user): User
    {
        $this->gate->forUser($actor)->authorize('auth.users.view');

        return $user->load(['roles', 'permissions', 'authPrincipal']);
    }
}
