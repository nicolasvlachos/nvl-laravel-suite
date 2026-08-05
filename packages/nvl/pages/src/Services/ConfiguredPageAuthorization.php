<?php

declare(strict_types=1);

namespace Nvl\Pages\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Models\Page;

/**
 * Allows public reads of eligible pages and requires an explicit policy for mutations.
 */
final class ConfiguredPageAuthorization implements PageAuthorization
{
    /**
     * Allow system operations and anonymous public reads while failing closed otherwise.
     */
    public function authorize(
        PageAbility $ability,
        PageActorData $actor,
        ?Page $page = null,
        ?PageAuthorizationContextData $context = null,
    ): void {
        if ($actor->system) {
            return;
        }

        if ($ability === PageAbility::View && $page instanceof Page) {
            $visible = $page->newQuery()
                ->whereKey($page->getKey())
                ->publiclyVisible()
                ->exists();

            if ($visible) {
                return;
            }
        }

        if ($ability === PageAbility::ViewNavigation) {
            return;
        }

        throw new AuthorizationException(
            "Page ability [{$ability->value}] requires a consumer authorization binding.",
        );
    }
}
