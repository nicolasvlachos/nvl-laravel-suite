<?php

declare(strict_types=1);

namespace Nvl\Pages\Listeners;

use Nvl\Pages\Events\PageChanged;
use Nvl\Seo\Services\SitemapCache;

/**
 * Invalidates only the site scope changed by a committed page mutation.
 */
final readonly class InvalidatePageSitemap
{
    /**
     * Create the site-scoped sitemap invalidation listener.
     */
    public function __construct(private SitemapCache $cache) {}

    /**
     * Invalidate the sitemap scope named by the committed page event.
     */
    public function handle(PageChanged $event): void
    {
        $this->cache->forget($event->site);
    }
}
