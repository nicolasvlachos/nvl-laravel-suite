<?php

declare(strict_types=1);

namespace App\Auth\Authorization;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Auth\Contracts\AuthManagementAccess;
use Nvl\Auth\Contracts\SystemMutationAccess;
use Nvl\Auth\ValueObjects\SystemMutationContext;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\PlatformOperation;

/** Denies Auth management unless the exact host permission or bootstrap is present. */
final class AuthConsumerAccess implements AuthManagementAccess, PlatformAccess, SystemMutationAccess
{
    public const string PERMISSION = 'auth-consumer.manage';

    public function allows(
        Authenticatable|SystemMutationContext $authority,
        string $ability,
        mixed $target = null,
    ): bool {
        if ($authority instanceof SystemMutationContext) {
            return in_array($ability, [
                'nvl-auth.rbac.bootstrap',
                'nvl-auth.users.manageAccess',
                'nvl-auth.memberships.manageAccess',
                'nvl-auth.memberships.enroll',
                'nvl-auth.memberships.revoke',
            ], true)
                && in_array($authority->reason, ['auth-production-consumer-bootstrap', 'auth-production-consumer-tenancy'], true)
                && str_starts_with($authority->correlationId, 'auth-production-consumer-');
        }

        return $authority instanceof User
            && $authority->hasPermissionTo(self::PERMISSION);
    }

    /** Authorize only the sealed fixture's explicit adoption operation. */
    public function authorize(PlatformOperation $operation): void
    {
        if ($operation->purpose !== 'auth-consumer.adoption'
            || $operation->actorType !== 'system'
            || $operation->actorId !== 'auth-consumer') {
            throw new TenantBoundaryViolation('The consumer platform operation is denied.');
        }
    }
}
