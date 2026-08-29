<?php

declare(strict_types=1);

namespace App\Auth\Authorization;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Auth\Contracts\AuthManagementAccess;
use Nvl\Auth\Contracts\SystemMutationAccess;
use Nvl\Auth\ValueObjects\SystemMutationContext;

/** Denies Auth management unless the exact host permission or bootstrap is present. */
final class AuthConsumerAccess implements AuthManagementAccess, SystemMutationAccess
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
            ], true)
                && $authority->reason === 'auth-production-consumer-bootstrap'
                && $authority->correlationId === 'auth-production-consumer-bootstrap-v1';
        }

        return $authority instanceof User
            && $authority->hasPermissionTo(self::PERMISSION);
    }
}
