<?php

declare(strict_types=1);

namespace App\Auth\Management\Users;

use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Validation\ValidationException;
use Nvl\Auth\Actions\Principals\RotatePrincipalSecurityVersionAction;
use Nvl\Auth\Data\Principals\RotateSecurityVersionData;
use Nvl\Auth\Models\Principal;

/**
 * Invalidates package trust before deleting a consumer-owned user.
 *
 * Approved orchestration: the host deletion deliberately composes the package's
 * security-version Action before removing the consumer identity.
 */
final readonly class DeleteUserAction
{
    public function __construct(
        private Gate $gate,
        private RotatePrincipalSecurityVersionAction $rotateSecurityVersion,
    ) {}

    /**
     * Delete one non-current user after every stale trust projection is invalidated.
     */
    public function execute(User $actor, User $user): void
    {
        $this->gate->forUser($actor)->authorize('auth.users.delete');

        if ($actor->is($user)) {
            throw ValidationException::withMessages([
                'user' => ['The current management actor cannot delete itself.'],
            ]);
        }

        $principal = $user->authPrincipal()->first();

        if ($principal instanceof Principal) {
            $rotated = $this->rotateSecurityVersion->execute(
                $principal,
                new RotateSecurityVersionData(
                    expectedSecurityVersion: $principal->security_version,
                    reason: 'host_user_deleted',
                ),
            );
            $rotated->delete();
        }

        $user->delete();
    }
}
