<?php

declare(strict_types=1);

namespace App\Auth\Credentials;

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Nvl\Auth\Contracts\PasswordVerifier;
use Nvl\Auth\Results\PasswordVerificationResult;
use Nvl\Auth\ValueObjects\PrincipalReference;
use Nvl\Auth\ValueObjects\SecretValue;

/**
 * Keeps the consumer password hash behind the package verification boundary.
 */
final readonly class ApplicationPasswordVerifier implements PasswordVerifier
{
    private const string DUMMY_HASH = '$2y$12$uzBJPmTvqZkYrO1o0pduA.sq2oMrHVZ3pKU8nkRzOKYnYBolYrRh.';

    public function __construct(private Hasher $hasher) {}

    /**
     * Verify one package principal against the consumer-owned credential.
     */
    public function verify(
        PrincipalReference $principal,
        SecretValue $password,
    ): PasswordVerificationResult {
        $user = $this->user($principal);

        if (! $user instanceof User) {
            $this->performDummyVerification($password);

            return new PasswordVerificationResult(
                verified: false,
                failureCode: 'credentials_invalid',
            );
        }

        $hash = $user->getAuthPassword();
        $verified = $this->hasher->check($password->reveal(), $hash);

        return new PasswordVerificationResult(
            verified: $verified,
            rehashRequired: $verified && $this->hasher->needsRehash($hash),
            failureCode: $verified ? null : 'credentials_invalid',
        );
    }

    /**
     * Perform equivalent password-hasher work for unresolved identities.
     */
    public function performDummyVerification(SecretValue $password): void
    {
        $this->hasher->check($password->reveal(), self::DUMMY_HASH);
    }

    /**
     * Resolve only the exact host subject named by the package principal.
     */
    private function user(PrincipalReference $principal): ?User
    {
        if ($principal->subjectType !== (new User)->getMorphClass()) {
            return null;
        }

        return User::query()->find($principal->subjectId);
    }
}
