<?php

declare(strict_types=1);

namespace App\Auth\Rbac;

use Nvl\Auth\Contracts\RoleTemplateProvider;
use Nvl\Auth\ValueObjects\RoleTemplate;

/** Contributes the tenant-local role instantiated independently in A and B. */
final class ConsumerRoleTemplates implements RoleTemplateProvider
{
    /** @return list<RoleTemplate> */
    public function roles(): array
    {
        return [
            new RoleTemplate(
                key: 'tenancy-consumer-editor',
                permissions: ['tenancy-consumer.manage', 'nvl-auth.rbac.view'],
                displayName: 'Tenancy consumer editor',
                description: 'Exercises tenant-local role projections.',
                priority: 100,
                metadata: ['owner' => 'tenancy-production-consumer'],
            ),
        ];
    }
}
