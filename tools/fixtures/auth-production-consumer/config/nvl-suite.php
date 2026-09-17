<?php

declare(strict_types=1);

$tenantProfile = (bool) env('AUTH_CONSUMER_TENANCY', false);

return [
    'modules' => [
        'support' => true,
        'data' => true,
        'filterable' => false,
        'translatable' => false,
        'activity' => true,
        'auth' => true,
        'csv' => false,
        'mail-notifications' => ! $tenantProfile,
        'media' => false,
        'comments' => false,
        'content' => false,
        'metafields' => false,
        'primitives' => false,
        'seo' => false,
        'settings' => ! $tenantProfile,
        'taxonomy' => false,
        'tenancy' => true,
        'templates' => false,
        'translations' => false,
        'forms' => false,
        'pages' => false,
    ],
];
