<?php

declare(strict_types=1);

namespace App\Auth\Authorization;

use App\Models\User;
use App\Tenancy\ConsumerPlatformAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Auth\Contracts\AuthManagementAccess;
use Nvl\Auth\Contracts\SystemMutationAccess;
use Nvl\Auth\ValueObjects\SystemMutationContext;

/** Supplies the exact Auth abilities required by the full sealed consumer. */
final class ConsumerAuthAccess extends ConsumerPlatformAccess implements AuthManagementAccess, SystemMutationAccess
{
    /** Determine whether the fixture authority owns one exact Auth ability. */
    public function allows(
        Authenticatable|SystemMutationContext $authority,
        string $ability,
        mixed $target = null,
    ): bool {
        if ($authority instanceof SystemMutationContext) {
            return in_array($ability, [
                'nvl-auth.memberships.enroll',
                'nvl-auth.memberships.manageAccess',
            ], true)
                && $authority->reason === 'tenancy-production-consumer'
                && $authority->correlationId === 'tenancy-production-consumer-v1';
        }

        if (! $authority instanceof User || $authority->email !== 'principal@tenancy-consumer.test') {
            return false;
        }

        return $ability === 'nvl-auth.rbac.synchronize'
            || $authority->hasPermissionTo('tenancy-consumer.manage');
    }
}
