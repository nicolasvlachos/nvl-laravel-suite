<?php

declare(strict_types=1);

use App\Models\Article;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Pages\Models\Page;

$supportedTypes = array_map(
    static fn (MetafieldTypeEnum $type): string => $type->value,
    MetafieldTypeEnum::cases(),
);

return [
    'routes' => ['enabled' => false],
    'migrations' => [
        'enabled' => env('CONTENT_CONSUMER_PACKAGE_MIGRATIONS', true),
    ],
    'owners' => [
        'article' => [
            'model' => Article::class,
            'label' => 'Articles',
            'supported_types' => $supportedTypes,
            'sections' => ['general'],
            'runtime_status' => 'live',
        ],
        'page' => [
            'model' => Page::class,
            'label' => 'Pages',
            'supported_types' => $supportedTypes,
            'sections' => ['general', 'navigation'],
            'runtime_status' => 'live',
        ],
    ],
    'reference_models' => [],
];
