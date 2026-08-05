<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\NavigationData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageNavigationBuilder;

/**
 * Reads one site-scoped localized public navigation tree.
 */
final readonly class GetNavigationAction
{
    /**
     * Create the public navigation query action.
     */
    public function __construct(
        private PageAuthorization $authorization,
        private PageNavigationBuilder $builder,
    ) {}

    /**
     * Return visible static navigation for one site and content locale.
     */
    public function execute(
        string $site,
        string $locale,
        PageActorData $actor,
    ): NavigationData {
        $this->authorization->authorize(
            PageAbility::ViewNavigation,
            $actor,
            context: new PageAuthorizationContextData(site: $site, locale: $locale),
        );
        $pages = Page::query()
            ->where('site', $site)
            ->where('kind', PageKind::Static)
            ->publiclyVisible()
            ->with('translations')
            ->ordered()
            ->get();

        return new NavigationData(
            site: $site,
            locale: $locale,
            items: $this->builder->build($pages, $locale),
        );
    }
}
