<?php

declare(strict_types=1);

namespace Nvl\Forms\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Forms\Providers\FormsServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Forms and its runtime dependencies in an isolated Laravel application.
 */
abstract class FormsTestCase extends Orchestra
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
            TranslatableServiceProvider::class,
            FormsServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('manage-forms', static fn ($user): bool => $user !== null);

        if (! Schema::hasTable('test_forms_users')) {
            Schema::create('test_forms_users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->string('account_type')->default('user');
                $table->timestamps();
            });
        }
    }

    /**
     * Configure the explicit route and authorization opt-ins used by feature tests.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('forms.routes.management.enabled', true);
        $app['config']->set('forms.routes.public.enabled', true);
        $app['config']->set('forms.authorization.gate', 'manage-forms');
    }
}
