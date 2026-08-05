<?php

declare(strict_types=1);

namespace App\Providers;

use App\Activity\ArticleActivityMapping;
use App\Models\ActivityArticle;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Nvl\Activity\Services\MappingRegistry;

/**
 * Registers the consumer-owned Activity mapping and authorization policy seams.
 */
final class ActivityProductionServiceProvider extends ServiceProvider
{
    /**
     * Register the consumer-owned custom Activity database connection.
     */
    public function register(): void
    {
        if (config('activity.storage.connection') !== 'activity_consumer') {
            return;
        }

        Config::set('database.connections.activity_consumer', [
            'driver' => 'sqlite',
            'url' => null,
            'database' => database_path('activity-consumer.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
        ]);
    }

    /**
     * Register the Activity mapping and named authorization abilities.
     */
    public function boot(
        MappingRegistry $activityMappings,
        ArticleActivityMapping $articleActivityMapping,
    ): void {
        $activityMappings->register($articleActivityMapping);

        Gate::define('activity.view', static fn (User $user): bool => $user->exists);
        Gate::define(
            'activity.timeline',
            static fn (User $user, ActivityArticle $article): bool => $user->exists && $article->exists,
        );
        Gate::define('activity.purge', static fn (User $user): bool => $user->exists);
    }
}
