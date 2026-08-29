<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use InvalidArgumentException;
use Nvl\Content\Content;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Contracts\PageUrlGenerator;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Data\PublicPageData;
use Nvl\Pages\Data\ResolvedPageData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageIdentityGuard;
use Nvl\Seo\Services\SeoMetadataResolver;
use Nvl\Translatable\Services\LocaleRegistry;

/**
 * Composes one public static Page projection from its persisted identity.
 */
final readonly class GetPagePublicationProjectionAction
{
    /**
     * Create the ID-based public Page projection reader.
     */
    public function __construct(
        private PageAuthorization $authorization,
        private PageUrlGenerator $urls,
        private PageIdentityGuard $identities,
        private LocaleRegistry $locales,
        private Content $content,
        private SeoMetadataResolver $seo,
    ) {}

    /**
     * Return one complete public projection for a visible static Page.
     */
    public function execute(
        string $pageId,
        string $locale,
        PageActorData $actor,
    ): ResolvedPageData {
        $pageId = $this->identities->id($pageId);
        $locale = $this->locales->assertSupported($locale);
        $page = Page::query()->publiclyVisible()->findOrFail($pageId);
        $this->authorization->authorize(
            PageAbility::View,
            $actor,
            $page,
            new PageAuthorizationContextData(
                site: $page->site,
                path: $page->path,
                locale: $locale,
            ),
        );

        if ($page->kind !== PageKind::Static) {
            throw new InvalidArgumentException(
                'ID-based publication projections support static Pages only.',
            );
        }

        $page->load('translations');

        return new ResolvedPageData(
            page: PublicPageData::fromModel($page, $locale, $this->urls),
            content: $this->content->render(
                $page,
                Page::CONTENT_GROUP,
                $locale,
                $actor->contentActor(),
                publicOnly: true,
            ),
            seo: $this->seo->resolve($page, $locale, $page->site),
            resource: null,
        );
    }
}
