<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\Mutations\UpdatePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Enums\PageChangeOperation;
use Nvl\Pages\Events\PageChanged;
use Nvl\Pages\Exceptions\StalePageException;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageDatabaseConflict;
use Nvl\Pages\Services\PageHierarchy;
use Nvl\Pages\Services\PageLifecycle;
use Nvl\Pages\Services\PageMutationValues;
use Nvl\Pages\Services\PageTreeLock;
use Nvl\Pages\Support\PagesConfiguration;
use Nvl\Translatable\Services\TranslationWriter;

/**
 * Replaces editable page state and locale rows with optimistic concurrency.
 */
final readonly class UpdatePageAction
{
    /**
     * Create the page replacement action.
     */
    public function __construct(
        private PageAuthorization $authorization,
        private PageDatabaseConflict $conflicts,
        private PageHierarchy $hierarchy,
        private PageLifecycle $lifecycle,
        private PageMutationValues $values,
        private PageTreeLock $treeLock,
        private TranslationWriter $translations,
    ) {}

    /**
     * Replace editable page state after exact revision and lifecycle authorization checks.
     */
    public function execute(
        Page|string $page,
        UpdatePageData $data,
        PageActorData $actor,
    ): Page {
        $pageId = $page instanceof Page ? $page->id : $page;
        $page = $page instanceof Page ? $page : Page::query()->findOrFail($pageId);
        $site = $page->site;

        try {
            return DB::connection(PagesConfiguration::connection())
                ->transaction(function () use ($actor, $data, $pageId, $site): Page {
                    $this->treeLock->acquire($site);
                    $page = Page::query()->lockForUpdate()->findOrFail($pageId);

                    if ($page->site !== $site) {
                        throw new StalePageException('The page site changed during the operation.');
                    }

                    if ($page->revision !== $data->expectedRevision) {
                        throw StalePageException::forPage($page->id);
                    }

                    $this->authorization->authorize(PageAbility::Update, $actor, $page);
                    $lifecycleAbility = $this->lifecycle->ability($page->status, $data->status);

                    if ($lifecycleAbility instanceof PageAbility) {
                        $this->authorization->authorize($lifecycleAbility, $actor, $page);
                    }

                    $this->values->assertKind($data->kind, $data->resource);
                    $this->hierarchy->assertValid($page->site, $page->parent_id, $pageId);
                    $dates = $this->values->dates(
                        $data->status,
                        $data->publishedAt,
                        $data->expiresAt,
                    );
                    $originalPath = $page->path;
                    $page->fill([
                        'slug' => $data->slug,
                        'path' => $this->hierarchy->path(
                            $page->site,
                            $page->parent_id,
                            $data->slug,
                        ),
                        'kind' => $data->kind,
                        'resource' => $data->resource,
                        'status' => $data->status,
                        'position' => $data->position,
                        'is_navigable' => $data->isNavigable,
                        'sitemap_included' => $data->sitemapIncluded,
                        'sitemap_priority' => $data->sitemapPriority,
                        'sitemap_change_frequency' => $data->sitemapChangeFrequency,
                        'published_at' => $dates['published_at'],
                        'expires_at' => $dates['expires_at'],
                    ])->save();
                    $descendants = $page->path !== $originalPath
                        ? $this->hierarchy->rebuildDescendantPaths($page)
                        : [];
                    $this->translations->replace(
                        $page,
                        $this->values->translations($data->translations),
                    );
                    PageChanged::dispatch(
                        $page->id,
                        $page->site,
                        PageChangeOperation::Updated,
                        $page->revision,
                        $actor,
                        [
                            $page->id,
                            ...array_map(
                                static fn (Page $descendant): string => $descendant->id,
                                $descendants,
                            ),
                        ],
                    );

                    return $page->refresh()->load('translations');
                }, attempts: PagesConfiguration::transactionAttempts());
        } catch (QueryException $exception) {
            $this->conflicts->rethrow($exception);
        }
    }
}
