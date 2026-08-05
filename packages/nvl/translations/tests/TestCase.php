<?php

declare(strict_types=1);

namespace Nvl\Translations\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translations\Providers\TranslationsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Translations and its runtime dependencies in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DataServiceProvider::class,
            FilterableServiceProvider::class,
            SupportServiceProvider::class,
            TranslationsServiceProvider::class,
        ];
    }
}
