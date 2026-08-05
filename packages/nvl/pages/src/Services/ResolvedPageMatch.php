<?php

declare(strict_types=1);

namespace Nvl\Pages\Services;

use Nvl\Pages\Data\PageResourceData;
use Nvl\Pages\Models\Page;

/**
 * Internal resolution result retaining the persisted page and sanitized resource projection.
 */
final readonly class ResolvedPageMatch
{
    /**
     * Create an internal resolved page and optional resource pair.
     */
    public function __construct(
        public Page $page,
        public ?PageResourceData $resource = null,
    ) {}
}
