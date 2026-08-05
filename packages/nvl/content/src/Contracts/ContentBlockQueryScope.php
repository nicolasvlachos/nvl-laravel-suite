<?php

declare(strict_types=1);

namespace Nvl\Content\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Models\ContentBlock;

/**
 * Optionally constrains block catalog queries through a trusted authorization adapter.
 */
interface ContentBlockQueryScope
{
    /**
     * Apply actor-owned constraints before caller-controlled filters and pagination.
     *
     * @param  Builder<ContentBlock>  $query
     */
    public function scopeContentBlocks(Builder $query, ContentActorData $actor): void;
}
