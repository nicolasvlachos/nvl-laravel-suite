<?php

declare(strict_types=1);

namespace Nvl\Translations\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Raised when both the authoritative file and editable database value changed.
 */
final class TranslationConflictException extends TranslationsException
{
    /**
     * Build an exception for one conflicting catalog identity.
     */
    public static function forIdentity(string $scope, string $identity): self
    {
        return new self("Translation sync conflict for [{$scope}:{$identity}].");
    }

    /**
     * Render a stable synchronization-conflict response for API consumers.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'translation_sync_conflict',
        ], 409);
    }
}
