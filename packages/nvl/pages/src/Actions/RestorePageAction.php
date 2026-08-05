<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\Mutations\RestorePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Enums\PageChangeOperation;
use Nvl\Pages\Events\PageChanged;
use Nvl\Pages\Exceptions\InvalidPageMutationException;
use Nvl\Pages\Exceptions\StalePageException;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageDatabaseConflict;
use Nvl\Pages\Services\PageHierarchy;
use Nvl\Pages\Services\PageTreeLock;
use Nvl\Pages\Support\PagesConfiguration;

/**
 * Restores one soft-deleted page after locked hierarchy and revision validation.
 */
final readonly class RestorePageAction
{
    /**
     * Create the page restoration action.
     */
    public function __construct(
        private PageAuthorization $authorization,
        private PageDatabaseConflict $conflicts,
        private PageHierarchy $hierarchy,
        private PageTreeLock $treeLock,
    ) {}

    /**
     * Restore one deleted page while serializing its site tree.
     */
    public function execute(
        Page|string $page,
        RestorePageData $data,
        PageActorData $actor,
    ): Page {
        $pageId = $page instanceof Page ? $page->id : $page;
        $resolved = $page instanceof Page
            ? $page
            : Page::query()->withTrashed()->findOrFail($pageId);
        $site = $resolved->site;

        try {
            return DB::connection(PagesConfiguration::connection())
                ->transaction(function () use ($actor, $data, $pageId, $site): Page {
                    $this->treeLock->acquire($site);
                    $page = Page::query()
                        ->withTrashed()
                        ->lockForUpdate()
                        ->findOrFail($pageId);

                    if ($page->site !== $site) {
                        throw new StalePageException('The page site changed during the operation.');
                    }

                    if (! $page->trashed()) {
                        throw new InvalidPageMutationException(
                            'Only a deleted page may be restored.',
                        );
                    }

                    if ($page->revision !== $data->expectedRevision) {
                        throw StalePageException::forPage($page->id);
                    }

                    $this->authorization->authorize(PageAbility::Restore, $actor, $page);
                    $this->hierarchy->assertValid($page->site, $page->parent_id, $page->id);
                    $page->restore();
                    PageChanged::dispatch(
                        $page->id,
                        $page->site,
                        PageChangeOperation::Restored,
                        $page->revision,
                        $actor,
                        [$page->id],
                    );

                    return $page->refresh()->load('translations');
                }, attempts: PagesConfiguration::transactionAttempts());
        } catch (QueryException $exception) {
            $this->conflicts->rethrow($exception);
        }
    }
}
