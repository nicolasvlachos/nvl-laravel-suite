<?php

declare(strict_types=1);
use App\Providers\AppServiceProvider;
use App\Providers\AuthProductionServiceProvider;

return [
    AppServiceProvider::class,
    AuthProductionServiceProvider::class,
];
