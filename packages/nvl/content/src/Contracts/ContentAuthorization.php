<?php

declare(strict_types=1);

namespace Nvl\Content\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Models\ContentBlock;

/**
 * Consumer-owned policy boundary for every content capability.
 */
interface ContentAuthorization
{
    /**
     * Throw when the actor cannot perform the requested ability.
     *
     * @param  array<string, mixed>  $context
     */
    public function authorize(
        ContentAbility $ability,
        ContentActorData $actor,
        ?ContentBlock $block = null,
        ?Model $owner = null,
        array $context = [],
    ): void;
}
