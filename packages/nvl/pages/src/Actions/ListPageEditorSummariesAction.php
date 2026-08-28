<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Content\Actions\ListOwnerContentPlacementSummariesAction;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Data\PageData;
use Nvl\Pages\Data\PageEditorSummaryData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageIdentityGuard;
use Nvl\Pages\Support\PagesConfiguration;
use Nvl\Seo\Actions\ListOwnerSeoProfilesAction;
use Nvl\Translatable\Services\LocaleRegistry;

/**
 * Lists bounded Page editor summaries with fixed-query Content and SEO reads.
 *
 * Delegation to Content and SEO Actions is deliberate orchestration so their
 * authorization, batching, and bounded projections remain canonical.
 */
final readonly class ListPageEditorSummariesAction
{
    /**
     * Create the authorized Page editor index reader.
     */
    public function __construct(
        private PageAuthorization $authorization,
        private PageIdentityGuard $identities,
        private LocaleRegistry $locales,
        private ListOwnerContentPlacementSummariesAction $content,
        private ListOwnerSeoProfilesAction $seo,
    ) {}

    /**
     * Return one stable site-scoped Page editor paginator.
     *
     * @return LengthAwarePaginator<int, PageEditorSummaryData>
     */
    public function execute(
        string $site,
        string $locale,
        PageActorData $actor,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $site = $this->identities->site($site);
        $locale = $this->locales->assertSupported($locale);
        $this->authorization->authorize(
            PageAbility::List,
            $actor,
            context: new PageAuthorizationContextData(site: $site, locale: $locale),
        );
        $paginator = Page::query()
            ->select([
                'id',
                'parent_id',
                'key',
                'site',
                'slug',
                'path',
                'kind',
                'resource',
                'status',
                'position',
                'is_navigable',
                'sitemap_included',
                'sitemap_priority',
                'sitemap_change_frequency',
                'published_at',
                'expires_at',
                'revision',
                'created_at',
                'updated_at',
            ])
            ->where('site', $site)
            ->with([
                'translations' => static function (Relation $query): void {
                    $query->select([
                        'id',
                        'page_id',
                        'locale',
                        'title',
                        'navigation_label',
                        'summary',
                    ]);
                },
            ])
            ->ordered()
            ->paginate(max(
                1,
                min(
                    100,
                    PagesConfiguration::limit('maximum_per_page', 100),
                    $perPage,
                ),
            ));
        $pages = $paginator->items();
        $seoProfiles = $this->seo->execute($pages, $site);
        $placements = $this->content->execute(
            $pages,
            Page::CONTENT_GROUP,
            $actor->contentActor(),
        );
        $seoProfilesByPage = [];

        foreach ($pages as $index => $page) {
            $seoProfilesByPage[$page->id] = $seoProfiles[$index] ?? null;
        }

        return $paginator->through(
            static function (Page $page) use (
                $locale,
                $placements,
                $seoProfilesByPage,
            ): PageEditorSummaryData {
                $label = trim($page->displayTitle($locale));

                return new PageEditorSummaryData(
                    page: PageData::fromModel($page),
                    label: $label !== '' ? $label : $page->key,
                    placements: $placements[Page::CONTENT_OWNER_TYPE.':'.$page->id] ?? [],
                    seo: $seoProfilesByPage[$page->id] ?? null,
                );
            },
        );
    }
}
