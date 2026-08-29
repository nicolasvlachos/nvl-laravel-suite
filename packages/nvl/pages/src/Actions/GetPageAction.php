<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Models\Page;

/**
 * Reads one page through the package authorization boundary.
 */
final readonly class GetPageAction
{
    /**
     * Create the authorized page read action.
     */
    public function __construct(private PageAuthorization $authorization) {}

    /**
     * Return one page with its management relationships loaded.
     */
    public function execute(Page|string $page, PageActorData $actor): PageData
    {
        $page = $page instanceof Page ? $page : Page::query()->findOrFail($page);
        $this->authorization->authorize(PageAbility::View, $actor, $page);

        $page->loadMissing('translations', 'parent', 'children');

        return PageData::fromModel($page);
    }
}
