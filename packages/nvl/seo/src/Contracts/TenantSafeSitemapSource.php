<?php

declare(strict_types=1);

namespace Nvl\Seo\Contracts;

/** Declares that a source queries only registered resources through tenant boundaries. */
interface TenantSafeSitemapSource extends SitemapSource
{
    /** @return list<string> */
    public function tenantResources(): array;
}
