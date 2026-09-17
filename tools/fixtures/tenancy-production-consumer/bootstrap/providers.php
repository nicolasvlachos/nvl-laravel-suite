<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\TenancyConsumerServiceProvider;

return [
    AppServiceProvider::class,
    TenancyConsumerServiceProvider::class,
];
