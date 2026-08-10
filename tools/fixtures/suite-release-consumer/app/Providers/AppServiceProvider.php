<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

/**
 * Supplies the minimum host-owned integration required by strict suite doctors.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Config::set('taxonomy.owners.users', User::class);
        Config::set('taxonomy.taxonomies.category.allowed_owners', ['users']);
        Config::set('taxonomy.taxonomies.tag.allowed_owners', ['users']);

        if (Config::boolean('nvl-release-consumer.published_migrations')) {
            foreach ([
                'activity',
                'nvl-auth',
                'comments',
                'content',
                'forms',
                'media',
                'mail-notifications',
                'metafields',
                'pages',
                'seo',
                'settings',
                'taxonomy',
                'templates',
                'translations',
            ] as $configuration) {
                Config::set("{$configuration}.migrations.enabled", false);
            }
        }
    }
}
