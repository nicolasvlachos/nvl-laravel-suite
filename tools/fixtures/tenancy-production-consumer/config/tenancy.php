<?php

declare(strict_types=1);

use App\Auth\Authorization\ConsumerAuthAccess;

return [
    'enabled' => env('TENANCY_CONSUMER_ENABLED', true),
    'strategy' => 'shared-database',
    'connection' => null,
    'profile' => 'application',
    'directory' => [
        'driver' => 'package',
        'adapter' => null,
    ],
    'resolvers' => ['http' => null, 'public_site' => null],
    'access' => [
        'membership' => null,
        'platform' => ConsumerAuthAccess::class,
    ],
    'resources' => ['media' => 'tenant', 'consumer-articles' => 'tenant'],
    'sharing' => ['media' => 'none', 'metafields' => 'none', 'templates' => 'none'],
    'migrations' => ['enabled' => env('TENANCY_CONSUMER_PACKAGE_MIGRATIONS', true)],
];
