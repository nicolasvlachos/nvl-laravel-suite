<?php

declare(strict_types=1);

use App\Models\User;
use Nvl\Activity\Definitions\Tables\ActivityTables;

return [
    'routes' => ['enabled' => false],
    'migrations' => [
        'enabled' => env('AUTH_CONSUMER_PACKAGE_MIGRATIONS', true),
    ],
    'storage' => [
        'connection' => null,
        'table' => ActivityTables::ActivityLog,
    ],
    'causer_suggestions' => [
        'model' => User::class,
        'label_attribute' => 'name',
        'sublabel_attribute' => 'email',
        'type_attribute' => null,
        'search_attributes' => ['name', 'email'],
        'scan_limit' => 100,
    ],
    'retention' => [
        'schedule' => ['enabled' => false],
    ],
];
