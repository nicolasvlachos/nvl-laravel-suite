<?php

declare(strict_types=1);

namespace Nvl\Pages\Contracts;

use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Models\Page;

/**
 * Consumer-owned authorization boundary for every page operation.
 */
interface PageAuthorization
{
    /**
     * Authorize one page capability for an actor and optional domain context.
     */
    public function authorize(
        PageAbility $ability,
        PageActorData $actor,
        ?Page $page = null,
        ?PageAuthorizationContextData $context = null,
    ): void;
}
