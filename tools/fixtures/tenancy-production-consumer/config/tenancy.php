<?php

declare(strict_types=1);

use App\Auth\Authorization\ConsumerAuthAccess;

$configuration = [
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
        'metafields' => 'tenant',
        'pages' => 'tenant',
        'seo' => 'tenant',
        'settings' => 'tenant',
        'taxonomy' => 'tenant',
        'templates' => 'tenant',
        'translations' => 'tenant',
    ],
    'sharing' => ['media' => 'none', 'metafields' => 'none', 'templates' => 'none'],
    'migrations' => ['enabled' => env('TENANCY_CONSUMER_PACKAGE_MIGRATIONS', true)],
];

$profile = (string) env('TENANCY_CONSUMER_MATRIX_PROFILE', 'full');

return match ($profile) {
    'disabled' => array_replace_recursive($configuration, ['enabled' => false]),
    'host-uuid-custom-principals' => array_replace_recursive($configuration, [
        'profile' => 'library',
        'directory' => ['driver' => 'host', 'adapter' => \App\Tenancy\HostTenantDirectory::class],
        'access' => [
            'membership' => \App\Tenancy\HostMembershipAccess::class,
            'platform' => \App\Tenancy\ConsumerPlatformAccess::class,
        ],
    ]),
    'conflicting-platform-family' => array_replace_recursive($configuration, ['resources' => ['media' => 'platform']]),
    'sharing-copy' => array_replace_recursive($configuration, ['sharing' => ['media' => 'copy', 'metafields' => 'copy', 'templates' => 'copy']]),
    'invalid-classes' => array_replace_recursive($configuration, ['directory' => ['driver' => 'host', 'adapter' => \stdClass::class]]),
    'invalid-families' => array_replace_recursive($configuration, ['resources' => ['unknown-family' => 'tenant']]),
    'invalid-custom-tables' => array_replace_recursive($configuration, ['tables' => ['ownership_markers' => 'bad table name']]),
    'invalid-connection-aliases' => array_replace_recursive($configuration, ['connection' => 'not-configured']),
    default => $configuration,
};
