<?php

declare(strict_types=1);

namespace Nvl\Seo\Contracts;

use Nvl\Seo\Data\SeoImage;
use Nvl\Seo\Support\SeoImageContext;

/**
 * Resolves social image URLs from direct URLs or application media references.
 */
interface SeoImageResolver
{
    public function resolve(SeoImageContext $context): ?SeoImage;
}
