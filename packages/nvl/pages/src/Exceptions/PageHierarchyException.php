<?php

declare(strict_types=1);

namespace Nvl\Pages\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Raised for invalid parent, cycle, cross-site, or depth operations.
 */
final class PageHierarchyException extends PagesException implements ShouldntReport
{
    /**
     * Render the hierarchy invariant failure as a conflict API response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
