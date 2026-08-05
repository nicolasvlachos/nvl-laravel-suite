<?php

declare(strict_types=1);

namespace Nvl\Seo\Contracts;

use Nvl\Seo\Support\SeoAuthorizationContext;

/**
 * Consumer-owned authorization boundary for optional management operations.
 */
interface SeoAuthorization
{
    /**
     * Authorize one management operation with source and target context.
     */
    public function authorize(SeoAuthorizationContext $context): void;
}
