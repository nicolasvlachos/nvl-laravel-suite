<?php

declare(strict_types=1);

namespace App\Auth\Authorization;

use Nvl\Auth\Contracts\PermissionCatalogProvider;
use Nvl\Auth\ValueObjects\PermissionDefinition;

/**
 * Declares the consumer-owned authorization vocabulary used by the example APIs.
 */
final readonly class ApplicationPermissionCatalog implements PermissionCatalogProvider
{
    /**
     * Return deterministic application permission definitions.
     *
     * @return list<PermissionDefinition>
     */
    public function permissions(): array
    {
        return array_map(
            static fn (array $definition): PermissionDefinition => new PermissionDefinition(
                name: $definition[0],
                group: $definition[1],
                description: $definition[2],
            ),
            [
                ['auth.management.access', 'auth-management', 'Access authentication management APIs.'],
                ['auth.users.view', 'users', 'View users and their access assignments.'],
                ['auth.users.create', 'users', 'Create users and principal projections.'],
                ['auth.users.update', 'users', 'Update consumer-owned user attributes.'],
                ['auth.users.delete', 'users', 'Invalidate and delete users.'],
                ['auth.users.assign-access', 'users', 'Assign catalog roles and permissions.'],
                ['auth.roles.view', 'authorization', 'View the synchronized role catalog.'],
                ['auth.permissions.view', 'authorization', 'View the synchronized permission catalog.'],
                ['auth.catalog.sync', 'authorization', 'Synchronize deterministic authorization catalogs.'],
                ['auth.principals.view', 'auth-security', 'View package principal projections.'],
                ['auth.clients.manage', 'auth-security', 'Manage registered authentication clients.'],
                ['auth.invitations.manage', 'auth-security', 'Create, resend, and revoke invitations.'],
                ['auth.recovery.review', 'auth-security', 'Review high-risk recovery cases.'],
                ['auth.security-events.view', 'auth-security', 'View the sanitized security ledger.'],
            ],
        );
    }
}
