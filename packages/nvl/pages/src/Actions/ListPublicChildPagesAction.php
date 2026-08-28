<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Illuminate\Support\Collection;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Contracts\PageUrlGenerator;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Data\PageRequestContextData;
use Nvl\Pages\Data\PublicPageData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Enums\PublicChildPageOrder;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageIdentityGuard;
use Nvl\Pages\Support\PagesConfiguration;
use Nvl\Translatable\Services\LocaleRegistry;

/**
 * Lists one bounded level of publicly visible child Pages.
 */
final readonly class ListPublicChildPagesAction
{
    /**
     * Create the authorized public child projection.
     */
    public function __construct(
        private PageAuthorization $authorization,
        private PageUrlGenerator $urls,
        private PageIdentityGuard $identities,
        private LocaleRegistry $locales,
    ) {}

    /**
     * Return public children in the requested deterministic order.
     *
     * @return Collection<int, PublicPageData>
     */
    public function execute(
        string $parentId,
        PageRequestContextData $context,
        int $limit = 50,
        ?PageKind $kind = null,
        PublicChildPageOrder $order = PublicChildPageOrder::Sibling,
    ): Collection {
        $parentId = $this->identities->id($parentId);
        $site = $this->identities->site($context->site);
        $locale = $this->locales->assertSupported($context->locale);
        $at = now();
        $parent = Page::query()
            ->where('site', $site)
            ->publiclyVisible($at)
            ->findOrFail($parentId);
        $authorizationContext = new PageAuthorizationContextData(
            site: $site,
            locale: $locale,
        );
        $this->authorization->authorize(
            PageAbility::ViewNavigation,
            PageActorData::anonymous(),
            $parent,
            $authorizationContext,
        );
        $maximum = min(
            100,
            PagesConfiguration::limit('maximum_public_children', 100),
        );

        $query = Page::query()
            ->where('site', $site)
            ->where('parent_id', $parent->id)
            ->publiclyVisible($at)
            ->with('translations');

        if ($kind instanceof PageKind) {
            $query->where('kind', $kind);
        }

        match ($order) {
            PublicChildPageOrder::Sibling => $query->ordered(),
            PublicChildPageOrder::Newest => $query
                ->orderByRaw('COALESCE(published_at, created_at) DESC')
                ->orderBy('position')
                ->orderBy('id'),
        };

        return $query
            ->limit(max(1, min($maximum, $limit)))
            ->get()
            ->map(
                fn (Page $page): PublicPageData => PublicPageData::fromModel(
                    $page,
                    $locale,
                    $this->urls,
                ),
            );
    }
}
