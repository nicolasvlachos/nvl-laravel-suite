<?php

declare(strict_types=1);

use Nvl\Pages\Models\Page;

return [
    'locales' => ['available' => ['en', 'bg'], 'required_on_publish' => ['en']],
    'scopes' => [
        'global' => ['key_pattern' => '/^(?:\*|[A-Za-z0-9][A-Za-z0-9_.:-]{0,190})$/'],
        'site' => ['key_pattern' => '/^[a-z0-9-]{1,50}$/'],
    ],
    'owners' => ['page' => Page::class],
    'definitions' => [
        'consumer-hero' => [
            'name' => 'Consumer hero',
            'category' => 'publication',
            'version' => 1,
            'allowed_scopes' => ['site'],
            'allowed_regions' => ['main'],
            'schema' => ['fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'localized' => true, 'required' => true],
                ['key' => 'media', 'type' => 'media', 'label' => 'Media'],
            ]],
        ],
        'consumer-template-copy' => [
            'name' => 'Consumer template copy',
            'category' => 'publication',
            'version' => 1,
            'allowed_scopes' => ['global'],
            'allowed_regions' => ['main'],
            'schema' => ['fields' => [
                ['key' => 'body', 'type' => 'text', 'label' => 'Body', 'localized' => true, 'required' => true],
            ]],
        ],
    ],
];
