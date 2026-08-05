<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Exposes a same-session CSRF token only to the local integration probe.
 */
final class CsrfTokenController
{
    /**
     * Return the current Laravel session's CSRF token.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'token' => csrf_token(),
            ],
        ]);
    }
}
