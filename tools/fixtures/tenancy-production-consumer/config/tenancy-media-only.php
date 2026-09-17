<?php

declare(strict_types=1);

use App\Tenancy\ConsumerPlatformAccess;
use App\Tenancy\HostMembershipAccess;
use App\Tenancy\HostTenantDirectory;

return [
    'enabled' => env('TENANCY_CONSUMER_ENABLED', true),
    'strategy' => 'shared-database',
    'connection' => null,
    'profile' => 'application',
    'directory' => [
        'driver' => 'host',
        'adapter' => HostTenantDirectory::class,
    ],
    'resolvers' => ['http' => null, 'public_site' => null],
    'access' => [
        'membership' => HostMembershipAccess::class,
        'platform' => ConsumerPlatformAccess::class,
    ],
    'resources' => ['media' => 'tenant', 'consumer-articles' => 'tenant'],
    'sharing' => ['media' => 'none', 'metafields' => 'none', 'templates' => 'none'],
    'migrations' => ['enabled' => env('TENANCY_CONSUMER_PACKAGE_MIGRATIONS', true)],
];
