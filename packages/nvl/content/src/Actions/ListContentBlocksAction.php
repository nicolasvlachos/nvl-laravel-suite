<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Contracts\ContentBlockQueryScope;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentBlockData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Models\ContentBlock;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Services\EloquentFilterApplier;

/**
 * Lists blocks through authorization and an allowlisted filter schema.
 */
final readonly class ListContentBlocksAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private EloquentFilterApplier $filters,
    ) {}

    /**
     * @return LengthAwarePaginator<int, ContentBlockData>
     */
    public function execute(
        FilterSet $filterSet,
        ContentActorData $actor,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $this->authorization->authorize(ContentAbility::List, $actor);
        $query = ContentBlock::query()->with(['definition', 'translations']);

        if ($this->authorization instanceof ContentBlockQueryScope) {
            $this->authorization->scopeContentBlocks($query, $actor);
        }

        $this->filters->apply($query, $filterSet, ContentBlock::filterSchema());

        $blocks = $query->paginate(max(1, min(100, $perPage)));

        return $blocks->through(
            static fn (ContentBlock $block): ContentBlockData => ContentBlockData::fromModel($block),
        );
    }
}
