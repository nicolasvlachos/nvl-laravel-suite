<?php

declare(strict_types=1);

namespace Nvl\Content\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvl\Content\Models\ContentPlacement;

/**
 * Marks an Eloquent model as a registered owner of grouped Content compositions.
 */
interface ContentOwner
{
    /**
     * Return every composition group supported by this owner.
     *
     * @return list<string>
     */
    public function contentGroups(): array;

    /**
     * Return every Content placement directly associated with this owner.
     *
     * @return MorphMany<ContentPlacement, *>
     */
    public function contentPlacements(): MorphMany;
}
