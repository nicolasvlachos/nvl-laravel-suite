<?php

declare(strict_types=1);

use App\Auth\Activity\ConsumerActivityBridge;
use App\Auth\Authorization\ConsumerAuthAccess;
use App\Auth\Rbac\ConsumerPermissionCatalog;
use App\Auth\Rbac\ConsumerRoleTemplates;
use App\Models\User;
use Nvl\Auth\Models\Permission;
use Nvl\Auth\Models\Role;
use Nvl\Auth\Services\EloquentRbacPrincipalAccess;
use Nvl\Auth\Services\PackagePermissionCatalog;
use Nvl\Auth\Services\PackageRoleTemplates;

return [
    'enabled' => true,
    'migrations' => [
        'enabled' => env('TENANCY_CONSUMER_PACKAGE_MIGRATIONS', true),
        'load_when_disabled' => false,
        'install_all' => false,
    ],
    'guard' => 'web',
    'identifier' => 'email',
    'services' => ['system_mutation_access' => ConsumerAuthAccess::class],
    'tenancy' => ['activity_bridge' => ConsumerActivityBridge::class],
    'features' => [
        'authentication' => ['enabled' => false],
        'principal_management' => [
            'enabled' => true,
            'models' => ['user' => User::class],
            'settings' => [
                'use_as_auth_model' => true,
                'default_locale' => 'en',
                'default_timezone' => 'UTC',
            ],
        ],
        'password' => ['enabled' => false],
        'email_verification' => ['enabled' => false],
        'magic_links' => ['enabled' => false],
        'security_codes' => ['enabled' => false],
        'memberships' => ['enabled' => true],
        'invitations' => ['enabled' => false],
        'totp' => ['enabled' => false],
        'passkeys' => ['enabled' => false],
        'recovery_codes' => ['enabled' => false],
        'social_identities' => ['enabled' => false],
        'clients' => ['enabled' => false],
        'sessions' => ['enabled' => false],
        'api_tokens' => ['enabled' => false],
        'rbac' => [
            'enabled' => true,
            'models' => [
                'principal' => User::class,
                'role' => Role::class,
                'permission' => Permission::class,
            ],
            'services' => [
                'principal_access' => EloquentRbacPrincipalAccess::class,
                'permission_catalogs' => [
                    PackagePermissionCatalog::class,
                    ConsumerPermissionCatalog::class,
                ],
                'role_templates' => [
                    PackageRoleTemplates::class,
                    ConsumerRoleTemplates::class,
                ],
            ],
            'settings' => [
                'guard' => 'web',
                'super_admin_role' => null,
                'use_package_storage' => true,
            ],
        ],
        'audit' => ['enabled' => true],
    ],
    'routes' => ['enabled' => false],
];
