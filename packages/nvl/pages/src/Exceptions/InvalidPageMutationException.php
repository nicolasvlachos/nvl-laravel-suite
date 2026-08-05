<?php

declare(strict_types=1);

namespace Nvl\Pages\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Raised when a validated transport payload violates a page domain invariant.
 */
final class InvalidPageMutationException extends PagesException implements ShouldntReport
{
    /**
     * Render the invariant failure as an unprocessable API response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
