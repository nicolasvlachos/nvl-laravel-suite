<?php

declare(strict_types=1);

use App\Models\TenantTaxonomyRecord;
use Nvl\Taxonomy\Models\Category;

return [
    'owners' => ['record' => TenantTaxonomyRecord::class],
    'taxonomies' => ['category' => [
        'model' => Category::class,
        'hierarchical' => true,
        'exclusive' => false,
        'open' => true,
        'max_depth' => 3,
        'sort' => 'position',
        'allowed_owners' => ['record'],
        'metadata_rules' => [],
    ]],
];
