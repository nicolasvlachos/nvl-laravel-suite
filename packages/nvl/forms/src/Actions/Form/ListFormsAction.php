<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Forms\Exceptions\FormException;
use Nvl\Forms\Models\Form;

/** Lists forms with filtering and pagination. */
final class ListFormsAction
{
    /**
     * Execute the form listing.
     *
     * @param  bool  $paginate  Whether to paginate results
     * @param  int|null  $perPage  Items per page when paginating
     * @return LengthAwarePaginator<int, Form>|Collection<int, Form>
     *
     * @throws FormException When per_page parameter is invalid
     */
    public function execute(
        bool $paginate = true,
        ?int $perPage = null,
        ?FilterSet $filters = null,
    ): LengthAwarePaginator|Collection {
        $query = Form::query()
            ->select($this->getSelectColumns())
            ->with($this->getEagerLoadRelations())
            ->applyFilterSet($filters ?? FilterSet::none());

        $perPage = $perPage !== null && $perPage > 0 ? $perPage : 20;

        if ($paginate) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }

    /**
     * Get columns to select.
     *
     * @return array<string>
     */
    private function getSelectColumns(): array
    {
        return [
            'id',
            'handle',
            'revision',
            'status',
            'resolvement',
            'type',
            'restrict_public_access',
            'allow_multiple_registrations',
            'date_restricted',
            'available_from',
            'available_until',
            'submissions_count',
            'first_used_at',
            'last_used_at',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * Get relationships to eager load.
     *
     * @return array<string>
     */
    private function getEagerLoadRelations(): array
    {
        return ['translations'];
    }
}
