<?php

declare(strict_types=1);

namespace Nvl\Pages\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Raised when a concurrent write violates a canonical page uniqueness constraint.
 */
final class PageConflictException extends PagesException implements ShouldntReport
{
    /**
     * Render the write conflict as a conflict API response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
