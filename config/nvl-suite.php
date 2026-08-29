<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Suite Profile
    |--------------------------------------------------------------------------
    |
    | New installations select a profile plus optional capability roots. The
    | suite closes transitive dependencies and rejects excluded requirements.
    | A published 1.x modules map remains authoritative when non-null.
    |
    */
    'profile' => 'full-suite',
    'include' => [],
    'exclude' => [],
    'modules' => null,

    'adoption' => [
        'require_explicit_module_decisions' => false,
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
