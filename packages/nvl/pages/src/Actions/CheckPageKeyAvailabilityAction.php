<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Data\PageKeyAvailabilityData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageIdentityGuard;

/**
 * Checks the actual globally unique Page key constraint without exposing models.
 */
final readonly class CheckPageKeyAvailabilityAction
{
    /**
     * Create the authorized Page key availability check.
     */
    public function __construct(
        private PageAuthorization $authorization,
        private PageIdentityGuard $identities,
    ) {}

    /**
     * Return key availability and only disclose same-site conflict identity.
     */
    public function execute(
        string $site,
        string $key,
        PageActorData $actor,
        ?string $exceptId = null,
    ): PageKeyAvailabilityData {
        $site = $this->identities->site($site);
        $key = $this->identities->key($key);
        $exceptId = $exceptId !== null ? $this->identities->id($exceptId) : null;
        $this->authorization->authorize(
            PageAbility::List,
            $actor,
            context: new PageAuthorizationContextData(site: $site),
        );
        $conflict = Page::query()
            ->withTrashed()
            ->where('key', $key)
            ->first(['id', 'site']);
        $exceptsSameSiteConflict = $conflict instanceof Page
            && $conflict->site === $site
            && $conflict->id === $exceptId;
        $available = ! ($conflict instanceof Page) || $exceptsSameSiteConflict;
        $conflictingPageId = null;

        if (! $available && $conflict->site === $site) {
            $conflictingPageId = $conflict->id;
        }

        return new PageKeyAvailabilityData(
            site: $site,
            key: $key,
            available: $available,
            conflictingPageId: $conflictingPageId,
        );
    }
}
