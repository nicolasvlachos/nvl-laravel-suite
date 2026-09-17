<?php

declare(strict_types=1);

use App\Models\TenantArticle;

return [
    'tenancy' => ['owner_types' => [TenantArticle::class], 'active_tenant_worklist' => []],
    'routes' => ['api_enabled' => false, 'assets_enabled' => false],
    'migrations' => ['enabled' => env('TENANCY_CONSUMER_PACKAGE_MIGRATIONS', true)],
    'disk' => 'tenant-media',
    'root_folder' => 'media',
    'default_path' => 'articles/{model_id}',
    'file_types' => ['txt' => 'text/plain'],
    'group_types' => ['document' => ['txt']],
    'allowed_disks' => ['tenant-media'],
    'allowed_associable_types' => [TenantArticle::class],
    'scanner' => ['required' => false, 'allow_noop' => true, 'untrusted_uploads' => false],
    'multipart' => ['enabled' => false],
    'queue' => [
        'enabled' => true,
        'connection' => 'database',
        'name' => 'tenancy-proof',
        'jobs' => [
            'generate' => ['tries' => 1, 'timeout' => 60, 'backoff' => [0], 'unique_for' => 60],
        ],
    ],
];
