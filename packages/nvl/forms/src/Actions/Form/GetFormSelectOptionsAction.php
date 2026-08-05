<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nvl\Forms\Data\FormSelectOptionItem;
use Nvl\Forms\Models\Form;

/** Retrieves form select options for dropdown/select components. */
final class GetFormSelectOptionsAction
{
    /**
     * Execute the form select options retrieval.
     *
     * @param  array<string, mixed>  $filters  Filter parameters for form selection
     * @return Collection<int, FormSelectOptionItem>
     */
    public function execute(array $filters): Collection
    {
        $normalizedFilters = collect($filters)
            ->mapWithKeys(static function ($value, string $key) {
                return [Str::snake($key) => $value];
            })
            ->toArray();

        $query = Form::query()
            ->withResolvedTranslations()
            ->select(['id', 'handle', 'status', 'restrict_public_access', 'submissions_count']);

        // Apply search query
        $searchValue = $normalizedFilters['q'] ?? null;
        $search = is_string($searchValue) ? trim($searchValue) : '';
        if ($search !== '') {
            $lower = mb_strtolower($search);
            $query->where(function (Builder $q) use ($lower) {
                $q->whereRaw('LOWER(handle) LIKE ?', ['%'.$lower.'%'])
                    ->orWhereHas('translations', function (Builder $translations) use ($lower): void {
                        $translations->whereRaw('LOWER(name) LIKE ?', ['%'.$lower.'%']);
                    });
            });
        }

        // Apply active_only filter
        if (isset($normalizedFilters['active_only']) && (bool) $normalizedFilters['active_only'] === true) {
            $query->where('status', 'active');
        }

        if (isset($normalizedFilters['status']) && is_string($normalizedFilters['status']) && $normalizedFilters['status'] !== '') {
            $query->where('status', $normalizedFilters['status']);
        }

        // Apply public_only filter
        if (isset($normalizedFilters['public_only']) && (bool) $normalizedFilters['public_only'] === true) {
            $query->where('restrict_public_access', false);
        }

        // Apply with_submissions filter
        if (isset($normalizedFilters['with_submissions']) && (bool) $normalizedFilters['with_submissions'] === true) {
            $query->where('submissions_count', '>', 0);
        }

        $options = [];

        $query
            ->orderBy('handle')
            ->limit(50)
            ->get()
            ->each(function (Form $form) use (&$options): void {
                $options[] = new FormSelectOptionItem(
                    id: $form->id,
                    label: $form->displayName(),
                    sublabel: $this->sublabelFromHandle($form->handle),
                );
            });

        return collect($options);
    }

    /**
     * Resolve the optional select-option sublabel from a form handle.
     */
    private function sublabelFromHandle(?string $handle): ?string
    {
        return $handle !== null && $handle !== '' ? $handle : null;
    }
}
