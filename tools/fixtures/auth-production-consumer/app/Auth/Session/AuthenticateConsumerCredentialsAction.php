<?php

declare(strict_types=1);

namespace App\Auth\Session;

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Nvl\Auth\ValueObjects\SecretValue;

/**
 * Verifies fixture-owned credentials before an HTTP session is established.
 */
final readonly class AuthenticateConsumerCredentialsAction
{
    private string $dummyHash;

    /**
     * Create the credential verifier with same-cost unknown-user material.
     */
    public function __construct(private Hasher $hasher)
    {
        $this->dummyHash = $this->hasher->make(bin2hex(random_bytes(32)));
    }

    /**
     * Return the matching consumer user only when its password is valid.
     */
    public function execute(string $email, SecretValue $password): ?User
    {
        $user = User::query()->where('email', $email)->first();
        $hash = $user instanceof User
            ? $user->getAuthPassword()
            : $this->dummyHash;
        $verified = $this->hasher->check($password->reveal(), $hash);

        if (! $user instanceof User || ! $verified) {
            return null;
        }

        return $user;
    }
}
