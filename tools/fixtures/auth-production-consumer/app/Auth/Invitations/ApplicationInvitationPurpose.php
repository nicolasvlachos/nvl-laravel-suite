<?php

declare(strict_types=1);

namespace App\Auth\Invitations;

use App\Models\User;
use Nvl\Auth\Contracts\InvitationPurposeHandler;
use Nvl\Auth\Enums\AssuranceLevel;
use Nvl\Auth\Enums\ContactType;
use Nvl\Auth\Enums\InvitationExistingPrincipalPolicy;
use Nvl\Auth\Enums\InvitationPrincipalProvisioningPolicy;
use Nvl\Auth\Pipelines\Contexts\InvitationAcceptancePipelineContext;
use Nvl\Auth\Results\InvitationPurposeResult;
use Nvl\Auth\ValueObjects\InvitationPurposeDefinition;
use Nvl\Auth\ValueObjects\InvitationPurposeHandlerMetadata;

/**
 * Applies the idempotent consumer-owned role effect for a member invitation.
 */
final readonly class ApplicationInvitationPurpose implements InvitationPurposeHandler
{
    /**
     * Declare the complete issuance and acceptance policy.
     */
    public function definition(): InvitationPurposeDefinition
    {
        return new InvitationPurposeDefinition(
            handle: 'member',
            existingPrincipalPolicy: InvitationExistingPrincipalPolicy::Allowed,
            provisioningPolicy: InvitationPrincipalProvisioningPolicy::Allowed,
            allowedContactTypes: [ContactType::Email],
            ttlMinutes: 10_080,
            maximumUses: 1,
            requiredAssurance: AssuranceLevel::SingleFactor,
            allowedRoles: ['member'],
            allowedPermissions: [],
            handler: new InvitationPurposeHandlerMetadata(
                identity: 'application.member-role',
                version: 1,
            ),
        );
    }

    /**
     * Apply allowlisted role and permission assignments idempotently.
     */
    public function accept(
        InvitationAcceptancePipelineContext $context,
    ): InvitationPurposeResult {
        $principal = $context->principalReference;

        if ($principal === null || $principal->subjectType !== (new User)->getMorphClass()) {
            return new InvitationPurposeResult(
                accepted: false,
                reasonCode: 'principal_unavailable',
            );
        }

        $user = User::query()->find($principal->subjectId);

        if (! $user instanceof User) {
            return new InvitationPurposeResult(
                accepted: false,
                reasonCode: 'principal_unavailable',
            );
        }

        $attributes = $context->attributes()->all();
        $roles = $this->handles($attributes['roles'] ?? []);
        $permissions = $this->handles($attributes['permissions'] ?? []);
        $user->syncRoles($roles);
        $user->syncPermissions($permissions);

        return new InvitationPurposeResult(
            accepted: true,
            principal: $principal,
        );
    }

    /**
     * Normalize an already package-allowlisted assignment list.
     *
     * @return list<string>
     */
    private function handles(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            $values,
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));
    }
}
