<?php

declare(strict_types=1);

namespace App\Auth\Identity;

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Auth\Actions\Principals\EnsurePrincipalProjectionAction;
use Nvl\Auth\Contracts\PrincipalProvisioner;
use Nvl\Auth\Data\Principals\EnsurePrincipalData;
use Nvl\Auth\Enums\AuthResponseCode;
use Nvl\Auth\Exceptions\AuthException;
use Nvl\Auth\Models\Principal;
use Nvl\Auth\ValueObjects\PrincipalProfile;
use Nvl\Auth\ValueObjects\PrincipalReference;

/**
 * Idempotently provisions a host user only from a verified invitation identity.
 */
final readonly class ApplicationPrincipalProvisioner implements PrincipalProvisioner
{
    public function __construct(
        private EnsurePrincipalProjectionAction $principals,
        private Hasher $hasher,
    ) {}

    /**
     * Provision or resolve the host subject and its package security projection.
     */
    public function provision(PrincipalProfile $profile): PrincipalReference
    {
        if ($profile->identifierType !== 'email' || ! $profile->identifierVerified) {
            throw AuthException::forCode(
                AuthResponseCode::PrincipalIneligible,
                'The invitation identity cannot provision a user.',
                409,
            );
        }

        return DB::transaction(function () use ($profile): PrincipalReference {
            $email = mb_strtolower(trim($profile->identifier));
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $profile->displayName ?? $email,
                    'password' => $this->hasher->make(Str::random(64)),
                    'email_verified_at' => now(),
                ],
            );
            $principal = $this->principals->execute(new EnsurePrincipalData(
                subjectType: $user->getMorphClass(),
                subjectId: (string) $user->getKey(),
                metadata: $profile->operationId === null
                    ? []
                    : ['provisioningOperationId' => $profile->operationId],
            ));

            return $this->reference($principal);
        }, 3);
    }

    /**
     * Convert the persisted projection to its immutable package boundary.
     */
    private function reference(Principal $principal): PrincipalReference
    {
        return new PrincipalReference(
            id: $principal->id,
            subjectType: $principal->subject_type,
            subjectId: $principal->subject_id,
            securityVersion: $principal->security_version,
        );
    }
}
