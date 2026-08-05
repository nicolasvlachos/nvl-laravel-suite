<?php

declare(strict_types=1);

namespace Nvl\Pages\Contracts;

use Nvl\Pages\Models\Page;

/**
 * Produces absolute canonical URLs for persisted static pages.
 */
interface PageUrlGenerator
{
    /**
     * Build one absolute page URL for an optional content locale.
     */
    public function url(Page $page, ?string $locale = null): string;
}
