<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Suite Modules
    |--------------------------------------------------------------------------
    |
    | Disable modules that the host application is not ready to adopt. The
    | suite always registers enabled modules after their required NVL module
    | dependencies, even when a dependency is disabled explicitly below.
    |
    */
    'modules' => [
        'support' => true,
        'data' => true,
        'filterable' => true,
        'translatable' => true,
        'activity' => true,
        'auth' => true,
        'csv' => true,
        'mail-notifications' => true,
        'media' => true,
        'comments' => true,
        'content' => true,
        'metafields' => true,
        'primitives' => true,
        'seo' => true,
        'settings' => true,
        'taxonomy' => true,
        'templates' => true,
        'translations' => true,
        'forms' => true,
        'pages' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer Audit
    |--------------------------------------------------------------------------
    |
    | Paths extend Composer source discovery. Suppressions are exact, reviewed
    | exceptions; broad patterns are intentionally unsupported.
    |
    */
    'consumer_audit' => [
        'paths' => ['app', 'config', 'database/migrations', 'routes'],
        'authentication_middleware' => ['auth'],
        'suppressions' => [],
    ],
];
