<?php

declare(strict_types=1);

namespace App\Auth\Authorization;

use Nvl\Auth\Contracts\RoleTemplateProvider;
use Nvl\Auth\ValueObjects\RoleTemplate;

/**
 * Composes deterministic consumer roles from the application permission catalog.
 */
final readonly class ApplicationRoleTemplates implements RoleTemplateProvider
{
    /**
     * Return the complete role template catalog.
     *
     * @return list<RoleTemplate>
     */
    public function roles(): array
    {
        return [
            new RoleTemplate(
                name: 'auth-administrator',
                permissions: [
                    'auth.management.access',
                    'auth.users.view',
                    'auth.users.create',
                    'auth.users.update',
                    'auth.users.delete',
                    'auth.users.assign-access',
                    'auth.roles.view',
                    'auth.permissions.view',
                    'auth.catalog.sync',
                    'auth.principals.view',
                    'auth.clients.manage',
                    'auth.invitations.manage',
                    'auth.recovery.review',
                    'auth.security-events.view',
                ],
                description: 'Full authentication and user-management authority.',
            ),
            new RoleTemplate(
                name: 'auth-operator',
                permissions: [
                    'auth.management.access',
                    'auth.users.view',
                    'auth.roles.view',
                    'auth.permissions.view',
                    'auth.principals.view',
                    'auth.clients.manage',
                    'auth.invitations.manage',
                    'auth.recovery.review',
                    'auth.security-events.view',
                ],
                description: 'Operational auth visibility without catalog or user mutation.',
            ),
            new RoleTemplate(
                name: 'member',
                permissions: [],
                description: 'Baseline application member provisioned by invitation.',
            ),
        ];
    }
}
