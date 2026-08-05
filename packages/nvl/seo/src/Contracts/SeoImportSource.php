<?php

declare(strict_types=1);

namespace Nvl\Seo\Contracts;

use Nvl\Seo\Data\Import\SeoImportPageData;

/**
 * Consumer-owned, cursor-paginated source for neutral profile adoption.
 */
interface SeoImportSource
{
    public function page(?string $cursor, int $limit): SeoImportPageData;
}
