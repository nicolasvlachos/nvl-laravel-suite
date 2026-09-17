<?php

declare(strict_types=1);

return [
    'routes' => ['enabled' => false],
    'migrations' => ['enabled' => env('TENANCY_CONSUMER_PACKAGE_MIGRATIONS', true)],
    'storage' => ['connection' => null, 'table' => 'activity_log'],
    'causer_suggestions' => ['model' => null],
    'retention' => ['schedule' => ['enabled' => false]],
];
