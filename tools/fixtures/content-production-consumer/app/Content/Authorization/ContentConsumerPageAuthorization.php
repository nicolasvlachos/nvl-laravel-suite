<?php

declare(strict_types=1);

namespace App\Content\Authorization;

use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Models\Page;

/** Typed Pages authorization adapter. */
final readonly class ContentConsumerPageAuthorization implements PageAuthorization
{
    public function __construct(private ContentConsumerAccess $access) {}

    public function authorize(
        PageAbility $ability,
        PageActorData $actor,
        ?Page $page = null,
        ?PageAuthorizationContextData $context = null,
    ): void {
        $this->access->authorizePage($ability, $actor);
    }
}
