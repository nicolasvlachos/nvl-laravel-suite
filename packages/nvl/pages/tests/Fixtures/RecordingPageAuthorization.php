<?php

declare(strict_types=1);

namespace Nvl\Pages\Tests\Fixtures;

use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Models\Page;

/**
 * Records page authorization checks for package action tests.
 */
final class RecordingPageAuthorization implements PageAuthorization
{
    /** @var list<PageAbility> */
    public array $abilities = [];

    /**
     * Record one authorized package capability.
     */
    public function authorize(
        PageAbility $ability,
        PageActorData $actor,
        ?Page $page = null,
        ?PageAuthorizationContextData $context = null,
    ): void {
        $this->abilities[] = $ability;
    }
}
