<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\TaxonomyOnlyTenancyConsumerServiceProvider;

return [AppServiceProvider::class, TaxonomyOnlyTenancyConsumerServiceProvider::class];
