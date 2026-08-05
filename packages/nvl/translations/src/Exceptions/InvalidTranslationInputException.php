<?php

declare(strict_types=1);

namespace Nvl\Translations\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Raised when a public translation operation receives an unsafe or unsupported value.
 */
final class InvalidTranslationInputException extends TranslationsException
{
    /**
     * Render a stable validation response for API consumers.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'invalid_translation_input',
        ], 422);
    }
}
