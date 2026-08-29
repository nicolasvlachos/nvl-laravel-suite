<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Services\EloquentFilterApplier;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Data\PageData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageFilterSchema;
use Nvl\Pages\Support\PagesConfiguration;

/**
 * Lists pages through authorization and an explicit filter allowlist.
 */
final readonly class ListPagesAction
{
    /**
     * Create the site-scoped page list action.
     */
    public function __construct(
        private PageAuthorization $authorization,
        private EloquentFilterApplier $filters,
        private PageFilterSchema $schema,
    ) {}

    /**
     * Return one authorized site-scoped management paginator.
     *
     * @return LengthAwarePaginator<int, PageData>
     */
    public function execute(
        FilterSet $filters,
        string $site,
        PageActorData $actor,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $this->authorization->authorize(
            PageAbility::List,
            $actor,
            context: new PageAuthorizationContextData(site: $site),
        );
        $query = Page::query()
            ->where('site', $site)
            ->with('translations');
        $this->filters->apply($query, $filters, $this->schema->make());

        $pages = $query->paginate(max(
            1,
            min(PagesConfiguration::limit('maximum_per_page', 100), $perPage),
        ));

        return $pages->through(
            static fn (Page $page): PageData => PageData::fromModel($page),
        );
    }
}
