<?php

declare(strict_types=1);

namespace App\Auth\ApiTokens;

use App\Models\User;

/**
 * Projects the authenticated user's own bearer-authorized profile.
 */
final readonly class ShowOwnProfileAction
{
    /**
     * Return the narrow host-owned profile representation.
     */
    public function execute(User $user): OwnProfileResult
    {
        return new OwnProfileResult(
            id: (string) $user->getKey(),
            name: $user->name,
            email: $user->email,
        );
    }
}
