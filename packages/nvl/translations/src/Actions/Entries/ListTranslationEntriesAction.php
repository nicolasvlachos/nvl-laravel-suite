<?php

declare(strict_types=1);

namespace Nvl\Translations\Actions\Entries;

use Illuminate\Pagination\LengthAwarePaginator;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Translations\Models\TranslationEntry;

/**
 * Lists translation entries with query filters and sorting.
 */
final class ListTranslationEntriesAction
{
    /**
     * Execute listing query.
     *
     * @param  int  $perPage  Requested page size
     * @return LengthAwarePaginator<int, TranslationEntry>
     */
    public function execute(int $perPage = 25, ?FilterSet $filters = null): LengthAwarePaginator
    {
        $pageSize = max(1, min($perPage, 200));

        return TranslationEntry::query()
            ->applyFilterSet($filters ?? FilterSet::none())
            ->paginate($pageSize)
            ->withQueryString();
    }
}
