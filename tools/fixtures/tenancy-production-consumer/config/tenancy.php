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
    'resources' => [
        'activity' => 'tenant',
        'auth' => 'tenant',
        'comments' => 'tenant',
        'consumer-articles' => 'tenant',
        'content' => 'tenant',
        'forms' => 'tenant',
        'mail-notifications' => 'tenant',
        'media' => 'tenant',
        'settings' => 'tenant',
        'templates' => 'tenant',
        'translations' => 'tenant',
    ],
    'sharing' => ['media' => 'none', 'metafields' => 'none', 'templates' => 'none'],
    'migrations' => ['enabled' => env('TENANCY_CONSUMER_PACKAGE_MIGRATIONS', true)],
];
