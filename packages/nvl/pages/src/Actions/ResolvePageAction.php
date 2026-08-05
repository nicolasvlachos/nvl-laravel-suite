<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Nvl\Content\Content;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Contracts\PageUrlGenerator;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Data\PublicPageData;
use Nvl\Pages\Data\ResolvedPageData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageMatcher;
use Nvl\Seo\Services\SeoMetadataResolver;

/**
 * Resolves one public path into Page, Content, SEO, and optional resource DTOs.
 */
final readonly class ResolvePageAction
{
    /**
     * Create the public page resolution action.
     */
    public function __construct(
        private PageMatcher $matcher,
        private PageAuthorization $authorization,
        private PageUrlGenerator $urls,
        private Content $content,
        private SeoMetadataResolver $seo,
    ) {}

    /**
     * Resolve one public page and its canonical Content group.
     */
    public function execute(
        string $path,
        string $site,
        string $locale,
        PageActorData $actor,
    ): ResolvedPageData {
        $match = $this->matcher->resolve($path, $site, $locale);
        $this->authorization->authorize(
            PageAbility::View,
            $actor,
            $match->page,
            new PageAuthorizationContextData(
                site: $site,
                path: $path,
                locale: $locale,
                resourceId: $match->resource?->id,
            ),
        );

        return new ResolvedPageData(
            page: PublicPageData::fromModel($match->page, $locale, $this->urls),
            content: $this->content->render(
                $match->page,
                Page::CONTENT_GROUP,
                $locale,
                $actor->contentActor(),
                publicOnly: true,
            ),
            seo: $this->seo->resolve($match->page, $locale, $site),
            resource: $match->resource,
        );
    }
}
