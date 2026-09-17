<?php

declare(strict_types=1);

namespace Nvl\Seo\Contracts;

use Nvl\Seo\Support\SeoImageContext;

/** Verifies an external image reference before any tenant-aware resolution work. */
interface TenantSafeSeoImageResolver extends SeoImageResolver
{
    public function assertTenantSafe(SeoImageContext $context): void;
}
