<?php

declare(strict_types=1);

namespace App\Auth\Management\Users;

use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Nvl\Auth\Actions\Principals\RotatePrincipalSecurityVersionAction;
use Nvl\Auth\Data\Principals\RotateSecurityVersionData;
use Nvl\Auth\Models\Principal;

/**
 * Updates consumer-owned user attributes without rewriting package trust state.
 *
 * Approved orchestration: password changes deliberately compose the package's
 * security-version Action so every previously issued trust artifact is invalidated.
 */
final readonly class UpdateUserAction
{
    public function __construct(
        private Gate $gate,
        private Hasher $hasher,
        private RotatePrincipalSecurityVersionAction $rotateSecurityVersion,
    ) {}

    /**
     * Update only supplied host-user attributes.
     */
    public function execute(
        User $actor,
        User $user,
        ?string $name,
        ?string $email,
        ?string $password,
    ): User {
        $this->gate->forUser($actor)->authorize('auth.users.update');

        return DB::transaction(function () use (
            $email,
            $name,
            $password,
            $user,
        ): User {
            $attributes = [];

            if ($name !== null) {
                $attributes['name'] = trim($name);
            }

            if ($email !== null) {
                $attributes['email'] = mb_strtolower(trim($email));
            }

            if ($password !== null) {
                $attributes['password'] = $this->hasher->make($password);
            }

            if ($attributes !== []) {
                $user->forceFill($attributes)->save();
            }

            if ($password !== null) {
                $principal = $user->authPrincipal()->first();

                if ($principal instanceof Principal) {
                    $this->rotateSecurityVersion->execute(
                        $principal,
                        new RotateSecurityVersionData(
                            expectedSecurityVersion: $principal->security_version,
                            reason: 'host_password_changed',
                        ),
                    );
                }
            }

            return $user->refresh()->load(['roles', 'permissions', 'authPrincipal']);
        }, 3);
    }
}
