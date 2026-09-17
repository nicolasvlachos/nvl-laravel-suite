<?php

declare(strict_types=1);

$driver = env('TENANCY_CONSUMER_MEDIA_DRIVER', 'local');

return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'throw' => true,
        ],
        'tenant-media' => $driver === 's3' ? [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'throw' => true,
        ] : [
            'driver' => 'local',
            'root' => storage_path('app/tenant-media'),
            'throw' => true,
        ],
    ],
];
