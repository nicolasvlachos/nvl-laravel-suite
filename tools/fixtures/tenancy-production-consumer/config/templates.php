<?php

declare(strict_types=1);

return [
    'definitions' => [
        'consumer-publication' => [
            'renderer' => 'blade',
            'view' => 'nvl-templates::html.document',
            'profiles' => ['default'],
            'schema' => [
                'type' => 'object',
                'properties' => ['tenant' => ['type' => 'string']],
                'required' => ['tenant'],
                'additionalProperties' => false,
            ],
            'required_regions' => ['main'],
            'allowed_content_definitions' => ['consumer-template-copy'],
        ],
    ],
];
