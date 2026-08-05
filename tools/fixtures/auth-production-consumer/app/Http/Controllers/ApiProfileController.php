<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\ApiTokens\ShowOwnProfileAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Adapts one ability-protected bearer request to the host profile action.
 */
final readonly class ApiProfileController
{
    /**
     * Return the authenticated user's own profile.
     */
    public function __invoke(
        Request $request,
        ShowOwnProfileAction $action,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return response()->json([
            'data' => $action->execute($user)->toArray(),
        ]);
    }
}
