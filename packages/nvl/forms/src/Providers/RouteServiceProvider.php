<?php

declare(strict_types=1);

namespace Nvl\Forms\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Nvl\Forms\Support\FormsConfiguration;

final class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Forms';

    /**
     * Called before routes are registered.
     * Register any model bindings or pattern based filters.
     */
    public function boot(): void
    {
        parent::boot();

        RateLimiter::for('forms-public', function (Request $request): Limit {
            $maxAttempts = FormsConfiguration::positiveInteger(
                'forms.security.rate_limit.max_attempts',
                10,
            );
            $decayMinutes = FormsConfiguration::positiveInteger(
                'forms.security.rate_limit.decay_minutes',
                1,
            );

            return Limit::perMinute($maxAttempts, $decayMinutes)
                ->by($request->user()?->id ?: $request->ip());
        });
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();
    }

    /**
     * Define the "web" routes for the application.
     * These routes all receive session state, CSRF protection, etc.
     */
    protected function mapWebRoutes(): void {}

    /**
     * Define the "api" routes for the application.
     * These routes are typically stateless.
     */
    protected function mapApiRoutes(): void
    {
        if (! (bool) config('forms.routes.management.enabled', false)
            && ! (bool) config('forms.routes.public.enabled', false)) {
            return;
        }

        Route::middleware($this->middleware())
            ->prefix(trim(FormsConfiguration::string('forms.routes.prefix', 'api/v1'), '/'))
            ->group(__DIR__.'/../../routes/api.php');
    }

    /**
     * @return list<string>
     */
    private function middleware(): array
    {
        return array_values(array_filter(
            (array) config('forms.routes.middleware', ['api']),
            static fn (mixed $middleware): bool => is_string($middleware) && $middleware !== '',
        ));
    }
}
