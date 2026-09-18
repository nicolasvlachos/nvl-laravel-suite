<?php

declare(strict_types=1);

namespace Nvl\Pages\Events;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Enums\PageChangeOperation;
use Nvl\Seo\Data\SitemapCacheIdentity;
use Nvl\Seo\Services\SitemapCache;

/**
 * Signals a committed page structure or lifecycle mutation.
 */
final readonly class PageChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public SitemapCacheIdentity $sitemapIdentity;

    /**
     * Create a committed page-change event.
     *
     * @param  list<string>  $affectedPageIds
     */
    public function __construct(
        public string $pageId,
        public string $site,
        public PageChangeOperation $operation,
        public int $revision,
        public PageActorData $actor,
        public array $affectedPageIds = [],
        ?SitemapCacheIdentity $sitemapIdentity = null,
    ) {
        $this->sitemapIdentity = $sitemapIdentity
            ?? Container::getInstance()->make(SitemapCache::class)->capture($site);
    }
}
