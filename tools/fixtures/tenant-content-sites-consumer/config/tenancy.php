<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'strategy' => 'shared-database',
    'connection' => null,
    'profile' => 'application',
    'migrations' => ['enabled' => true],
    'resources' => [
        'media' => 'tenant',
        'content' => 'tenant',
        'metafields' => 'tenant',
        'seo' => 'tenant',
        'pages' => 'tenant',
    ],
    'sharing' => ['media' => 'none', 'metafields' => 'none'],
];
