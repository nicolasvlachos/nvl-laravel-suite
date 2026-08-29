<?php

declare(strict_types=1);

use App\Models\Article;
use Nvl\Pages\Models\Page;

return [
    'site' => [
        'scope' => 'default',
        'name' => 'Content Consumer',
        'base_url' => env('APP_URL', 'https://content-consumer.test'),
    ],
    'owners' => [
        'article' => Article::class,
        'page' => Page::class,
    ],
    'migrations' => [
        'enabled' => env('CONTENT_CONSUMER_PACKAGE_MIGRATIONS', true),
    ],
    'routes' => ['enabled' => false],
    'management' => ['enabled' => false],
];
