<?php

declare(strict_types=1);

use Nvl\Settings\Definitions\Tables\SettingsTables;

return [
    'discovery' => [
        'paths' => [app_path('Settings')],
        'patterns' => ['*.settings.php'],
        'recursive' => true,
        'follow_links' => false,
        'maximum_files' => 10,
        'maximum_file_bytes' => 16_384,
        'maximum_json_depth' => 16,
        'cache' => true,
        'cache_path' => null,
    ],
    'storage' => [
        'connection' => null,
        'table' => SettingsTables::Settings,
    ],
    'migrations' => [
        'enabled' => env('AUTH_CONSUMER_PACKAGE_MIGRATIONS', true),
    ],
    'management' => [
        'enabled' => false,
        'authorization_ability' => null,
    ],
    'cache' => [
        'enabled' => true,
        'store' => 'database',
        'key' => 'auth-consumer:settings:v1',
    ],
    'overrides' => ['enabled' => false],
];
