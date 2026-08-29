<?php

declare(strict_types=1);
use App\Providers\AppServiceProvider;
use App\Providers\ContentConsumerServiceProvider;
use Nvl\Suite\SuiteServiceProvider;

return [
    AppServiceProvider::class,
    SuiteServiceProvider::class,
    ContentConsumerServiceProvider::class,
];
