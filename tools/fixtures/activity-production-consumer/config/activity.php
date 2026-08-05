<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateActivityConsumer;
use App\Models\ActivityArticle;
use App\Models\User;

$usesCustomStorage = filter_var(
    env('NVL_ACTIVITY_CUSTOM_STORAGE', false),
    FILTER_VALIDATE_BOOL,
);

return [
    'routes' => [
        'enabled' => true,
        'management_middleware' => [AuthenticateActivityConsumer::class],
        'timeline_subjects' => [ActivityArticle::class],
    ],

    'authorization' => [
        'abilities' => [
            'view' => 'activity.view',
            'timeline' => 'activity.timeline',
            'purge' => 'activity.purge',
        ],
    ],

    'migrations' => [
        'enabled' => ! $usesCustomStorage,
    ],

    'storage' => [
        'connection' => $usesCustomStorage ? 'activity_consumer' : null,
        'table' => $usesCustomStorage
            ? 'activity_consumer_activity_log'
            : 'activity_log',
    ],

    'causer_suggestions' => [
        'model' => User::class,
        'type_attribute' => null,
        'search_attributes' => ['name'],
        'scan_limit' => 100,
    ],

    'retention' => [
        'allowed_purge_options' => [90],
    ],

    'capture' => [
        'ignored_attributes' => ['updated_at'],
    ],
];
