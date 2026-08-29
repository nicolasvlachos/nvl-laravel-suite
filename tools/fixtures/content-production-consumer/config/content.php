<?php

declare(strict_types=1);
use App\Content\Authorization\ContentConsumerContentAuthorization;

return [
    'migrations' => [
        'enabled' => env('CONTENT_CONSUMER_PACKAGE_MIGRATIONS', true),
    ],
    'authorization' => [
        'class' => ContentConsumerContentAuthorization::class,
        'callback' => null,
    ],
    'definitions' => [],
    'definition_paths' => [],
    'required_definition_paths' => [],
    'locales' => [
        'available' => ['en', 'bg'],
        'required_on_publish' => ['en', 'bg'],
    ],
    'routes' => [
        'management' => ['enabled' => false],
        'public' => ['enabled' => false],
    ],
];
