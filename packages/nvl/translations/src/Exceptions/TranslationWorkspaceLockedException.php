<?php

declare(strict_types=1);

namespace Nvl\Translations\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Raised when another synchronization process owns the workspace lock.
 */
final class TranslationWorkspaceLockedException extends TranslationsException
{
    /**
     * Build a lock-contention exception for one workspace operation.
     */
    public static function forOperation(string $operation): self
    {
        return new self(
            "The translation workspace is already running [{$operation}].",
        );
    }

    /**
     * Render a stable workspace-lock response for API consumers.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'translation_workspace_locked',
        ], 423);
    }
}
