<?php

declare(strict_types=1);

use App\Content\Media\ContentConsumerMediaScanner;
use App\Models\Article;

return [
    'routes' => [
        'api_enabled' => false,
        'assets_enabled' => true,
    ],
    'migrations' => [
        'enabled' => env('CONTENT_CONSUMER_PACKAGE_MIGRATIONS', true),
    ],
    'disk' => 'local',
    'content_scanner' => ContentConsumerMediaScanner::class,
    'scanner' => [
        'required' => true,
        'allow_noop' => false,
        'untrusted_uploads' => true,
    ],
    'multipart' => ['enabled' => false],
    'allowed_disks' => ['local'],
    'allowed_associable_types' => [Article::class],
    'queue' => [
        'enabled' => true,
        'connection' => env('MEDIA_QUEUE_CONNECTION', 'database'),
        'name' => 'media',
    ],
];
