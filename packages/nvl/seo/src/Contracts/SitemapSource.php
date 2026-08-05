<?php

declare(strict_types=1);

namespace Nvl\Seo\Contracts;

use Nvl\Seo\Data\SitemapEntry;

/**
 * Streams canonical URLs owned by one application capability.
 */
interface SitemapSource
{
    /**
     * @return iterable<SitemapEntry>
     */
    public function entries(string $scope): iterable;
}
