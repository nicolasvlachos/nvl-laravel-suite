<?php

declare(strict_types=1);

namespace Nvl\Translations\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Raised when an editor attempts to overwrite a newer workspace revision.
 */
final class StaleTranslationWorkspaceException extends TranslationsException
{
    /**
     * Build a stale-revision exception for one workspace entry.
     */
    public static function forEntry(string $id): self
    {
        return new self(
            "Translation entry [{$id}] changed after it was read; reload it before saving.",
        );
    }

    /**
     * Render a stable optimistic-concurrency response for API consumers.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'stale_translation_workspace',
        ], 409);
    }
}
