<?php

declare(strict_types=1);

namespace App\Auth\Identity;

use App\Models\User;
use Nvl\Auth\Actions\Principals\EnsurePrincipalProjectionAction;
use Nvl\Auth\Contracts\PrincipalResolver;
use Nvl\Auth\Data\Principals\EnsurePrincipalData;
use Nvl\Auth\Models\Principal;
use Nvl\Auth\ValueObjects\PrincipalLookup;
use Nvl\Auth\ValueObjects\PrincipalReference;

/**
 * Resolves normalized consumer email identifiers into package principals.
 */
final readonly class ApplicationPrincipalResolver implements PrincipalResolver
{
    public function __construct(
        private EnsurePrincipalProjectionAction $principals,
    ) {}

    /**
     * Resolve an email without exposing the host model across the package boundary.
     */
    public function resolve(PrincipalLookup $lookup): ?PrincipalReference
    {
        if ($lookup->type !== 'email') {
            return null;
        }

        $user = User::query()
            ->where('email', mb_strtolower(trim($lookup->value)))
            ->first();

        if (! $user instanceof User) {
            return null;
        }

        $principal = $this->principals->execute(new EnsurePrincipalData(
            subjectType: $user->getMorphClass(),
            subjectId: (string) $user->getKey(),
        ));

        return $this->reference($principal);
    }

    /**
     * Convert the package projection to its immutable integration boundary.
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
