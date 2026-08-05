<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\Session\AuthenticateConsumerCredentialsAction;
use App\Http\Requests\AuthConsumerSessionRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use LogicException;

/**
 * Establishes the fixture's browser session through a real HTTP response.
 */
final readonly class AuthConsumerSessionController
{
    /**
     * Authenticate credentials, rotate the session, and return a safe response.
     *
     * @throws AuthenticationException
     */
    public function __invoke(
        AuthConsumerSessionRequest $request,
        AuthenticateConsumerCredentialsAction $authenticate,
        AuthManager $auth,
    ): JsonResponse {
        $user = $authenticate->execute(
            $request->email(),
            $request->password(),
        );

        if ($user === null) {
            throw new AuthenticationException;
        }

        $guard = $auth->guard('web');

        if (! $guard instanceof StatefulGuard) {
            throw new LogicException('The web guard must support stateful login.');
        }

        $guard->login($user);
        $request->session()->regenerate();

        return response()->json(['data' => null])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
