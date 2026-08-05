<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\Mutations\DeletePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Enums\PageChangeOperation;
use Nvl\Pages\Events\PageChanged;
use Nvl\Pages\Exceptions\PageHierarchyException;
use Nvl\Pages\Exceptions\StalePageException;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageTreeLock;
use Nvl\Pages\Support\PagesConfiguration;

/**
 * Soft-deletes one leaf page while preserving composed data for restoration.
 */
final readonly class DeletePageAction
{
    /**
     * Create the page deletion action.
     */
    public function __construct(
        private PageAuthorization $authorization,
        private PageTreeLock $treeLock,
    ) {}

    /**
     * Soft-delete one childless page after exact revision validation.
     */
    public function execute(
        Page|string $page,
        DeletePageData $data,
        PageActorData $actor,
    ): bool {
        $pageId = $page instanceof Page ? $page->id : $page;
        $page = $page instanceof Page ? $page : Page::query()->findOrFail($pageId);
        $site = $page->site;

        return DB::connection(PagesConfiguration::connection())
            ->transaction(function () use ($actor, $data, $pageId, $site): bool {
                $this->treeLock->acquire($site);
                $page = Page::query()->lockForUpdate()->findOrFail($pageId);

                if ($page->site !== $site) {
                    throw new StalePageException('The page site changed during the operation.');
                }

                if ($page->revision !== $data->expectedRevision) {
                    throw StalePageException::forPage($page->id);
                }

                $this->authorization->authorize(PageAbility::Delete, $actor, $page);

                if ($page->children()->exists()) {
                    throw new PageHierarchyException(
                        'A page with child pages cannot be deleted.',
                    );
                }

                $deleted = $page->delete() === true;
                PageChanged::dispatch(
                    $page->id,
                    $page->site,
                    PageChangeOperation::Deleted,
                    $page->revision,
                    $actor,
                    [$page->id],
                );

                return $deleted;
            }, attempts: PagesConfiguration::transactionAttempts());
    }
}
