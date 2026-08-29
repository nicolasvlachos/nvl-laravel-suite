<?php

declare(strict_types=1);

use App\Auth\Authorization\AuthConsumerMailReadAuthorization;
use App\Models\User;

return [
    'enabled' => true,
    'tracking' => [
        'enabled' => true,
        'failure_policy' => 'fail_closed',
        'excluded_mailers' => [],
        'store_subject' => true,
    ],
    'presentation' => ['enabled' => false],
    'providers' => [
        'default' => 'array',
        'mailers' => [
            'array' => 'array',
        ],
    ],
    'notifiable_types' => [
        'consumer-user' => User::class,
    ],
    'management' => [
        'maximum_per_page' => 25,
        'authorization' => [
            'class' => AuthConsumerMailReadAuthorization::class,
            'callback' => null,
        ],
    ],
    'webhooks' => ['enabled' => false],
    'scheduling' => ['enabled' => false],
    'migrations' => [
        'enabled' => env('AUTH_CONSUMER_PACKAGE_MIGRATIONS', true),
    ],
];
