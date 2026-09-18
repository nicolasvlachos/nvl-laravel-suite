<?php

declare(strict_types=1);

use Nvl\Pages\Tests\HttpTestCase;
use Nvl\Pages\Tests\TenancyTestCase;
use Nvl\Pages\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__.'/Feature');
pest()->extend(HttpTestCase::class)->in(__DIR__.'/Http');
pest()->extend(TenancyTestCase::class)->in(__DIR__.'/Tenancy');
