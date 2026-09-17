<?php

declare(strict_types=1);

use Nvl\Content\Tests\TestCase;
use Nvl\Content\Tests\TenancyTestCase;

uses(TestCase::class)->in(__DIR__.'/Feature', __DIR__.'/Unit');
uses(TenancyTestCase::class)->in(__DIR__.'/Tenancy');
