<?php

declare(strict_types=1);

namespace Nvl\Pages\Contracts;

use Illuminate\Http\Request;
use Nvl\Pages\Data\PageRequestContextData;

/**
 * Resolves trusted public site and locale context at the HTTP boundary.
 */
interface PageRequestContextResolver
{
    /**
     * Resolve trusted public page context from one request.
     */
    public function resolve(Request $request): PageRequestContextData;
}
