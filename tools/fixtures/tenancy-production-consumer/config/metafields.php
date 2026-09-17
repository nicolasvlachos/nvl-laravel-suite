<?php

declare(strict_types=1);

use Nvl\Media\Models\Media;
use Nvl\Pages\Models\Page;

return [
    'authorization' => [
        'owner_ability' => 'tenancy-consumer.metafields.manage',
        'definition_ability' => 'tenancy-consumer.metafields.manage',
        'reference_ability' => 'tenancy-consumer.metafields.reference',
    ],
    'owners' => [
        'page' => [
            'model' => Page::class,
            'label' => 'Pages',
            'supported_types' => ['string', 'reference'],
            'sections' => ['publication'],
            'runtime_status' => 'live',
        ],
    ],
    'reference_models' => ['media' => Media::class],
];
