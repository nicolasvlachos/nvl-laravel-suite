<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Forms\Exceptions\FormException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

/**
 * Lists form entries with filtering and pagination.
 */
final class ListFormEntriesAction
{
    /**
     * Execute the form entries listing.
     *
     * @param  Form|string|null  $form  Form instance, identifier, or null for all entries
     * @param  bool  $paginate  Whether to paginate results
     * @param  int|null  $perPage  Items per page when paginating
     * @return LengthAwarePaginator<int, FormEntry>|Collection<int, FormEntry>
     *
     * @throws FormException When per_page parameter is invalid
     */
    public function execute(
        Form|string|null $form = null,
        bool $paginate = true,
        ?int $perPage = null,
        ?FilterSet $filters = null,
    ): LengthAwarePaginator|Collection {
        $query = FormEntry::query()
            ->select($this->getSelectColumns())
            ->with($this->getEagerLoadRelations());

        // Filter by specific form if provided
        if ($form !== null) {
            $formId = $form instanceof Form ? $form->id : $form;
            $query->where('form_id', $formId);
        }

        $query->applyFilterSet($filters ?? FilterSet::none());

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
            'form_id',
            'subject',
            'email',
            'first_name',
            'last_name',
            'phone',
            'submitted_from',
            'created_at',
        ];
    }

    /**
     * Get relationships to eager load.
     *
     * @return array<string>
     */
    private function getEagerLoadRelations(): array
    {
        return [
            'form:id,handle',
        ];
    }
}
