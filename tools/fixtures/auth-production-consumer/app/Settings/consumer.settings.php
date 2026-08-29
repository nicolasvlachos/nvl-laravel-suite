<?php

declare(strict_types=1);

use Nvl\Settings\Enums\SettingType;

return [
    'namespace' => 'consumer',
    'settings' => [
        'enabled' => [
            'type' => SettingType::Boolean,
            'default' => false,
            'description' => 'Proof setting changed through package Actions.',
            'position' => 10,
            'metadata' => ['group' => 'proof'],
        ],
    ],
];
