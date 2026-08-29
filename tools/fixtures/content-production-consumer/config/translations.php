<?php

declare(strict_types=1);

return [
    'routes' => ['enabled' => false],
    'migrations' => [
        'enabled' => env('CONTENT_CONSUMER_PACKAGE_MIGRATIONS', true),
    ],
    'paths' => [
        'app' => lang_path(),
        'vendor' => lang_path('vendor'),
    ],
    'discovery' => [
        'modules' => false,
        'vendor' => false,
    ],
    'export_targets' => [
        'source' => [],
        'generated' => [
            'app' => storage_path('app/content-consumer-translations'),
        ],
    ],
    'import' => [
        'conflict_strategy' => 'prefer_database',
        'fail_on_error' => true,
    ],
    'backup' => [
        'enabled' => true,
        'directory' => storage_path('app/content-consumer-translation-backups'),
    ],
    'scan' => [
        'paths' => [app_path('Content')],
        'extensions' => ['php'],
    ],
];
