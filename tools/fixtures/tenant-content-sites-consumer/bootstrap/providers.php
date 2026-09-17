<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\TenantContentSitesServiceProvider;
use Nvl\Suite\SuiteServiceProvider;

return [
    AppServiceProvider::class,
    SuiteServiceProvider::class,
    TenantContentSitesServiceProvider::class,
];
