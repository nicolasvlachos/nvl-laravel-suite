<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Admits only explicit host-principal to host-tenant memberships. */
final class HostMembershipAccess implements TenantMembershipAccess
{
    /** Require the principal's stable host mapping to match the requested tenant. */
    public function assertMember(Authenticatable $actor, TenantId $tenant): void
    {
        $identifier = $actor->getAuthIdentifier();
        $expected = match (is_string($identifier) ? $identifier : null) {
            'host-a' => HostTenantDirectory::TENANT_A,
            'host-b' => HostTenantDirectory::TENANT_B,
            default => null,
        };

        if ($expected !== $tenant->value) {
            throw new TenantBoundaryViolation('The host principal is not a member of the requested tenant.');
        }
    }
}
