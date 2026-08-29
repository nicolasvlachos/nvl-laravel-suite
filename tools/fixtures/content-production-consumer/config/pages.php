<?php

declare(strict_types=1);

use App\Content\Authorization\ContentConsumerPageAuthorization;
use App\Pages\ArticlePageResourceHandler;

return [
    'migrations' => [
        'enabled' => env('CONTENT_CONSUMER_PACKAGE_MIGRATIONS', true),
    ],
    'resources' => [
        'articles.detail' => ArticlePageResourceHandler::class,
    ],
    'authorization' => [
        'class' => ContentConsumerPageAuthorization::class,
    ],
    'urls' => [
        'base_url' => env('APP_URL', 'https://content-consumer.test'),
        'default_locale' => 'en',
    ],
    'routes' => [
        'public' => ['enabled' => false],
        'management' => ['enabled' => false],
    ],
];
