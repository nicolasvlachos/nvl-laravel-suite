<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Data\PageData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageIdentityGuard;

/**
 * Finds one exact Page key inside an authorized site boundary.
 */
final readonly class FindPageByKeyAction
{
    /**
     * Create the site-scoped Page key lookup.
     */
    public function __construct(
        private PageAuthorization $authorization,
        private PageIdentityGuard $identities,
    ) {}

    /**
     * Return one authorized management Page projection by exact key.
     */
    public function execute(
        string $site,
        string $key,
        PageActorData $actor,
    ): PageData {
        $site = $this->identities->site($site);
        $key = $this->identities->key($key);
        $page = Page::query()
            ->where('site', $site)
            ->where('key', $key)
            ->firstOrFail();
        $this->authorization->authorize(
            PageAbility::View,
            $actor,
            $page,
            new PageAuthorizationContextData(site: $site),
        );
        $page->load('translations');

        return PageData::fromModel($page);
    }
}
