<?php

declare(strict_types=1);
use App\Auth\Authorization\AuthConsumerAccess;

return [
    'enabled' => env('AUTH_CONSUMER_TENANCY', false),
    'strategy' => 'shared-database',
    'connection' => null,
    'profile' => 'application',
    'directory' => ['driver' => 'package', 'adapter' => null],
    'resolvers' => ['http' => null, 'public_site' => null],
    'access' => ['membership' => null, 'platform' => AuthConsumerAccess::class],
    'resources' => [],
    'sharing' => ['media' => 'none', 'metafields' => 'none', 'templates' => 'none'],
    'migrations' => ['enabled' => env('AUTH_CONSUMER_PACKAGE_MIGRATIONS', true)],
];
