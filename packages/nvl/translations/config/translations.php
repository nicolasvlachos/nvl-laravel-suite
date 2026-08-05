<?php

declare(strict_types=1);

return [
    'routes' => [
        'enabled' => false,
        'prefix' => 'api/v1',
        'middleware' => ['api'],
        'management_middleware' => ['auth'],
    ],

    'migrations' => [
        'enabled' => true,
    ],

    'authorization' => [
        'ability' => null,
    ],

    'paths' => [
        'app' => lang_path(),
        'vendor' => lang_path('vendor'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional module roots
    |--------------------------------------------------------------------------
    |
    | Each root may contain module directories whose language files live in
    | either "lang" or "Resources/lang". No module system is assumed.
    |
    */
    'module_roots' => [],

    'discovery' => [
        'modules' => false,
        'vendor' => false,
    ],

    'custom_scopes' => [
        // 'shared' => storage_path('translations/shared'),
    ],

    'export_targets' => [
        'source' => [],
        // 'generated' => [
        //     'app' => storage_path('translations/generated/app'),
        //     'custom:shared' => storage_path('translations/generated/shared'),
        // ],
    ],

    'import' => [
        'conflict_strategy' => 'fail',
        'fail_on_error' => true,
    ],

    'backup' => [
        'enabled' => true,
        'directory' => storage_path('translations/backups'),
    ],

    'lock' => [
        'store' => null,
        'seconds' => 300,
        'wait_seconds' => 0,
    ],

    'scan' => [
        'paths' => [
            base_path('app'),
            resource_path('views'),
            resource_path('js'),
        ],
        'extensions' => ['php', 'blade.php', 'js', 'jsx', 'ts', 'tsx', 'vue'],
        'retention_days' => 30,
        'patterns' => [
            '/(?:(?:__|trans|trans_choice|Lang::get|Lang::choice)\s*\(\s*[\'"]([^\'"]+)[\'"])/',
            '/(?:@lang|@choice)\s*\(\s*[\'"]([^\'"]+)[\'"]/',
            '/(?<![A-Za-z0-9_$])(?:t|\$t)\s*\(\s*[\'"]([^\'"]+)[\'"]/',
        ],
        'namespaces' => [
            // 'package-namespace' => 'vendor:package-directory',
        ],
    ],

    'scan_allowlist' => [
        'errors.*',
    ],
];
