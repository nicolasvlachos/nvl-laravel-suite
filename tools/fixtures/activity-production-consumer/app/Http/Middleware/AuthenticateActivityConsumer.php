<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates synthetic Activity API requests without weakening package routes.
 */
final class AuthenticateActivityConsumer
{
    /**
     * Resolve the synthetic consumer actor and continue the request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $identifier = $request->header('X-Activity-Consumer-User');

        if (! is_string($identifier) || $identifier === '' || ! ctype_digit($identifier)) {
            abort(401, 'An Activity consumer user is required.');
        }

        $user = User::query()->find($identifier);

        if (! $user instanceof User) {
            abort(401, 'The Activity consumer user was not found.');
        }

        Auth::setUser($user);
        $request->setUserResolver(static fn (): User => $user);

        return $next($request);
    }
}
