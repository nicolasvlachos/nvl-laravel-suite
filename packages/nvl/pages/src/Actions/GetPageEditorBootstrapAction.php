<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Nvl\Content\Actions\GetOwnerContentEditorAction;
use Nvl\Metafields\Actions\Metafields\ListAuthorizedOwnerMetafieldsAction;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Data\PageData;
use Nvl\Pages\Data\PageEditorBootstrapData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageIdentityGuard;
use Nvl\Pages\Services\PageResourceRegistry;
use Nvl\Pages\Support\PagesConfiguration;
use Nvl\Seo\Actions\GetOwnerSeoProfileAction;
use Nvl\Translatable\Services\LocaleRegistry;

/**
 * Composes one authorized Page editor from package-owned read boundaries.
 *
 * Delegation to Content, SEO, and Metafields Actions is deliberate orchestration
 * so their authorization and projection contracts remain package-owned.
 */
final readonly class GetPageEditorBootstrapAction
{
    /**
     * Create the complete Page editor bootstrap reader.
     */
    public function __construct(
        private PageAuthorization $authorization,
        private PageIdentityGuard $identities,
        private LocaleRegistry $locales,
        private GetOwnerContentEditorAction $content,
        private GetOwnerSeoProfileAction $seo,
        private ListAuthorizedOwnerMetafieldsAction $metafields,
        private PageResourceRegistry $resources,
    ) {}

    /**
     * Return the complete editor bootstrap for one Page identity and locale.
     */
    public function execute(
        string $pageId,
        string $locale,
        PageActorData $actor,
    ): PageEditorBootstrapData {
        $pageId = $this->identities->id($pageId);
        $locale = $this->locales->assertSupported($locale);
        $page = Page::query()->findOrFail($pageId);
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
        $page->load('translations');

        return new PageEditorBootstrapData(
            page: PageData::fromModel($page),
            content: $this->content->execute(
                $page,
                Page::CONTENT_GROUP,
                $actor->contentActor(),
            ),
            seo: $this->seo->execute($page, $page->site),
            metafields: array_values($this->metafields->execute($page, $locale)->all()),
            pageKinds: array_column(PageKind::cases(), 'value'),
            pageStatuses: array_column(PageStatus::cases(), 'value'),
            resourceAliases: $this->resources->aliases(),
            maximumDepth: PagesConfiguration::maximumDepth(),
        );
    }
}
