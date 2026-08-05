<?php

declare(strict_types=1);

namespace App\Providers;

use App\Comments\Authorization\ApplicationCommentAuthorization;
use App\Comments\Authorization\ApplicationMediaAuthorization;
use App\Console\Commands\CommentsConsumerSmokeCommand;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Media\Contracts\MediaAuthorization;

/**
 * Registers the production consumer's request guard, policies, limits, and smoke command.
 */
final class CommentsProductionServiceProvider extends ServiceProvider
{
    /**
     * Register consumer-owned authorization contracts and the request guard configuration.
     */
    public function register(): void
    {
        Config::set('auth.guards.comments_consumer', [
            'driver' => 'comments-consumer-header',
            'provider' => 'users',
        ]);

        $this->app->singleton(ApplicationCommentAuthorization::class);
        $this->app->alias(
            ApplicationCommentAuthorization::class,
            CommentAuthorization::class,
        );
        $this->app->alias(
            ApplicationCommentAuthorization::class,
            CommentQueryScope::class,
        );
        $this->app->singleton(MediaAuthorization::class, ApplicationMediaAuthorization::class);
    }

    /**
     * Register exact header authentication, named throttles, and the consumer command.
     */
    public function boot(): void
    {
        Auth::viaRequest(
            'comments-consumer-header',
            static function (Request $request): ?User {
                $email = $request->header('X-Comments-Consumer-User');

                if (! is_string($email) || trim($email) === '') {
                    return null;
                }

                $user = User::query()->where('email', $email)->first();

                return $user instanceof User && hash_equals($user->email, $email)
                    ? $user
                    : null;
            },
        );

        RateLimiter::for(
            'comments-consumer-public',
            static fn (Request $request): Limit => Limit::perMinute(120)
                ->by(self::ipRateLimitKey($request, 'public')),
        );
        RateLimiter::for(
            'comments-consumer-member',
            static fn (Request $request): Limit => Limit::perMinute(120)
                ->by(self::actorRateLimitKey($request, 'member')),
        );
        RateLimiter::for(
            'comments-consumer-management',
            static fn (Request $request): Limit => Limit::perMinute(120)
                ->by(self::actorRateLimitKey($request, 'management')),
        );
        RateLimiter::for(
            'comments-consumer-assets',
            static fn (Request $request): Limit => Limit::perMinute(240)
                ->by(self::ipRateLimitKey($request, 'asset')),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([CommentsConsumerSmokeCommand::class]);
        }
    }

    /**
     * Scope authenticated limits to one exact consumer user.
     */
    private static function actorRateLimitKey(Request $request, string $prefix): string
    {
        $actor = $request->user('comments_consumer');

        return $actor instanceof User
            ? $prefix.':'.$actor->email
            : self::ipRateLimitKey($request, $prefix);
    }

    /**
     * Scope unauthenticated limits to a stable remote-address fallback.
     */
    private static function ipRateLimitKey(Request $request, string $prefix): string
    {
        return $prefix.':'.($request->ip() ?? 'unknown');
    }
}
