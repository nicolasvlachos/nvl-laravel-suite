<?php

declare(strict_types=1);

namespace Nvl\Forms\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Forms\Providers\FormsServiceProvider;
use Nvl\Forms\Tests\Fixtures\TenantScenario;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;

abstract class TenancyTestCase extends Orchestra
{
    use DatabaseMigrations;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [DataServiceProvider::class, FilterableServiceProvider::class, SupportServiceProvider::class,
            TenancyServiceProvider::class, TranslatableServiceProvider::class, FormsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('tenancy.enabled', true);
        $app['config']->set('tenancy.profile', 'application');
        $app['config']->set('tenancy.resources', ['forms' => 'tenant']);
        $app['config']->set('forms.routes.public.enabled', true);
        TenantScenario::bind($app);
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $provider = new ReflectionClass(TenancyServiceProvider::class);
        $this->loadMigrationsFrom(dirname($provider->getFileName()).'/../../database/migrations/tenancy');
        Schema::create('test_forms_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        TenantScenario::activate(['forms']);
    }
}
