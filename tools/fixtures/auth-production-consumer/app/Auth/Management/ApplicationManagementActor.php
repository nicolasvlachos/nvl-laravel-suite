<?php

declare(strict_types=1);

namespace App\Auth\Management;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Resolves the authenticated consumer subject at the management transport boundary.
 */
final readonly class ApplicationManagementActor
{
    /**
     * Return the concrete host user or fail authentication.
     */
    public function resolve(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        return $actor;
    }
}
