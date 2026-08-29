<?php

declare(strict_types=1);

namespace App\Auth\Rbac;

use Nvl\Auth\Contracts\RoleTemplateProvider;
use Nvl\Auth\ValueObjects\RoleTemplate;

/** Contributes one deterministic administrator role template. */
final class AuthConsumerRoleTemplates implements RoleTemplateProvider
{
    /** @return list<RoleTemplate> */
    public function roles(): array
    {
        return [
            new RoleTemplate(
                key: 'auth-consumer-administrator',
                permissions: ['auth-consumer.manage'],
                displayName: 'Auth consumer administrator',
                description: 'Exercises every privileged proof-consumer read.',
                priority: 100,
                metadata: ['owner' => 'auth-production-consumer'],
            ),
        ];
    }
}
