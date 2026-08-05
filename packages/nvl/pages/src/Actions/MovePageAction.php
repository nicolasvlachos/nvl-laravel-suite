<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\Mutations\MovePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Enums\PageChangeOperation;
use Nvl\Pages\Events\PageChanged;
use Nvl\Pages\Exceptions\StalePageException;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageDatabaseConflict;
use Nvl\Pages\Services\PageHierarchy;
use Nvl\Pages\Services\PageTreeLock;
use Nvl\Pages\Support\PagesConfiguration;

/**
 * Reparents a page subtree after locked cycle and depth validation.
 */
final readonly class MovePageAction
{
    /**
     * Create the page move action.
     */
    public function __construct(
        private PageAuthorization $authorization,
        private PageDatabaseConflict $conflicts,
        private PageHierarchy $hierarchy,
        private PageTreeLock $treeLock,
    ) {}

    /**
     * Reparent and reorder one page while serializing its complete site tree.
     */
    public function execute(
        Page|string $page,
        MovePageData $data,
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

                    $this->authorization->authorize(PageAbility::Move, $actor, $page);
                    $this->hierarchy->assertValid($page->site, $data->parentId, $page->id);
                    $originalPath = $page->path;
                    $page->parent_id = $data->parentId;
                    $page->position = $data->position;
                    $page->path = $this->hierarchy->path(
                        $page->site,
                        $data->parentId,
                        $page->slug,
                    );
                    $page->save();
                    $descendants = $page->path !== $originalPath
                        ? $this->hierarchy->rebuildDescendantPaths($page)
                        : [];
                    PageChanged::dispatch(
                        $page->id,
                        $page->site,
                        PageChangeOperation::Moved,
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
