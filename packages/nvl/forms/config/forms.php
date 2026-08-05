<?php

declare(strict_types=1);

return [
    'migrations' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Both surfaces are opt-in. Management middleware must authenticate callers,
    | while package policies additionally require an explicit gate.
    |
    */
    'routes' => [
        'prefix' => 'api/v1',
        'middleware' => ['api'],
        'management' => [
            'enabled' => false,
            'middleware' => ['auth'],
        ],
        'public' => [
            'enabled' => false,
            'middleware' => ['throttle:forms-public'],
        ],
    ],

    'authorization' => [
        'gate' => null,
    ],

    'public' => [
        'token_ttl_minutes' => 15,
    ],

    'submission' => [
        'max_payload_bytes' => 262144,
        'max_depth' => 8,
        'max_items' => 250,
    ],

    'security' => [
        'rate_limit' => [
            'max_attempts' => 10,
            'decay_minutes' => 1,
        ],
        'rate_limiting' => [
            'block_duration_minutes' => [
                1 => 15,
                2 => 60,
                3 => 240,
                4 => 720,
                5 => 1440,
            ],
        ],
        'spam_protection' => [
            'honeypot' => [
                'field_names' => ['url', 'website', 'homepage', 'link'],
            ],
            'score_thresholds' => [
                'block' => 70,
                'flag' => 40,
            ],
            'score_weights' => [
                'honeypot' => 100,
                'fast_submission' => 50,
                'multiple_links' => 30,
                'excessive_punctuation' => 20,
                'spam_phrases' => 25,
                'ip_reputation' => 10,
            ],
            'spam_phrases' => ['click here', 'buy now', 'limited time', 'act now', 'free money'],
            'min_submission_time' => 3,
        ],
        'ip_blocking' => [
            'cleanup_after_days' => 7,
        ],
    ],
];
