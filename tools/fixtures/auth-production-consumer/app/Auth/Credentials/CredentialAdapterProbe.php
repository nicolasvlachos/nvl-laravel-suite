<?php

declare(strict_types=1);

namespace App\Auth\Credentials;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Auth\Contracts\PasswordUpdater;
use Nvl\Auth\Contracts\PasswordVerifier;
use Nvl\Auth\Models\Principal;
use Nvl\Auth\ValueObjects\PasswordUpdateRequest;
use Nvl\Auth\ValueObjects\PrincipalReference;
use Nvl\Auth\ValueObjects\SecretValue;
use RuntimeException;

/**
 * Exercises both host-owned credential contracts and their idempotency boundary.
 */
final readonly class CredentialAdapterProbe
{
    public function __construct(
        private PasswordUpdater $updater,
        private PasswordVerifier $verifier,
    ) {}

    /**
     * Apply and retry one operation before verifying the resulting credential.
     */
    public function probe(User $user, string $password): CredentialAdapterProbeResult
    {
        $principal = $user->authPrincipal;

        if (! $principal instanceof Principal) {
            throw new RuntimeException('The credential probe user has no package principal.');
        }

        $operationId = (string) Str::uuid();
        $reference = new PrincipalReference(
            id: $principal->id,
            subjectType: $principal->subject_type,
            subjectId: $principal->subject_id,
            securityVersion: $principal->security_version,
        );
        $request = new PasswordUpdateRequest(
            operationId: $operationId,
            principal: $reference,
            password: new SecretValue($password),
        );
        $updated = $this->updater->update($request);
        $retried = $this->updater->update($request);
        $verified = $this->verifier->verify(
            $reference,
            new SecretValue($password),
        );

        return new CredentialAdapterProbeResult(
            updated: $updated->applied,
            retryIdempotent: $retried->applied,
            checkpointUnique: DB::table('auth_consumer_password_operations')
                ->where('operation_id', $operationId)
                ->count() === 1,
            verified: $verified->verified,
        );
    }
}
