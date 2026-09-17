<?php

declare(strict_types=1);

use Nvl\Pages\Models\Page;
use Nvl\Taxonomy\Models\Category;

return [
    'owners' => ['page' => Page::class],
    'taxonomies' => [
        'category' => [
            'model' => Category::class,
            'hierarchical' => true,
            'exclusive' => true,
            'open' => true,
            'max_depth' => 3,
            'sort' => 'position',
            'allowed_owners' => ['page'],
            'metadata_rules' => [],
        ],
    ],
];
