<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Nvl\Content\Content;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Data\PageData;
use Nvl\Pages\Data\PreviewPageData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageMatcher;
use Nvl\Seo\Services\SeoMetadataResolver;

/**
 * Resolves an authorized non-public page preview with draft content included.
 */
final readonly class PreviewPageAction
{
    /**
     * Create the authorized page preview action.
     */
    public function __construct(
        private PageMatcher $matcher,
        private PageAuthorization $authorization,
        private Content $content,
        private SeoMetadataResolver $seo,
    ) {}

    /**
     * Resolve one preview path without applying public page or content visibility.
     */
    public function execute(
        string $path,
        string $site,
        string $locale,
        PageActorData $actor,
    ): PreviewPageData {
        $match = $this->matcher->resolve($path, $site, $locale, publicOnly: false);
        $this->authorization->authorize(
            PageAbility::Preview,
            $actor,
            $match->page,
            new PageAuthorizationContextData(
                site: $site,
                path: $path,
                locale: $locale,
                resourceId: $match->resource?->id,
            ),
        );

        return new PreviewPageData(
            page: PageData::fromModel($match->page),
            content: $this->content->render(
                $match->page,
                Page::CONTENT_GROUP,
                $locale,
                $actor->contentActor(),
                publicOnly: false,
            ),
            seo: $this->seo->resolve($match->page, $locale, $site),
            resource: $match->resource,
        );
    }
}
