<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Illuminate\Support\Str;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Results\FormSearchResult;

/** Searches forms with filtering and lightweight aggregation. */
final class SearchFormsAction
{
    /**
     * Execute the form search.
     *
     * @param  array<string, mixed>  $filters  Search filters and parameters
     */
    public function execute(array $filters): FormSearchResult
    {
        $normalizedFilters = [];
        foreach ($filters as $key => $value) {
            $normalizedFilters[Str::snake($key)] = $value;
        }

        $query = Form::query()
            ->select($this->getSelectColumns($normalizedFilters))
            ->withResolvedTranslations();

        // Apply has_submissions filter
        if (isset($normalizedFilters['has_submissions']) && (bool) $normalizedFilters['has_submissions'] === true) {
            $query->where('submissions_count', '>', 0);
        }

        if (isset($normalizedFilters['status']) && is_string($normalizedFilters['status']) && $normalizedFilters['status'] !== '') {
            $query->where('status', $normalizedFilters['status']);
        }

        // Apply recently_used filter
        if (isset($normalizedFilters['recently_used']) && (bool) $normalizedFilters['recently_used'] === true) {
            $query->whereNotNull('last_used_at')
                ->where('last_used_at', '>=', now()->subDays(30));
        }

        // Apply date filters
        if (isset($normalizedFilters['created_after']) && is_string($normalizedFilters['created_after'])) {
            $query->whereDate('created_at', '>=', $normalizedFilters['created_after']);
        }

        if (isset($normalizedFilters['created_before']) && is_string($normalizedFilters['created_before'])) {
            $query->whereDate('created_at', '<=', $normalizedFilters['created_before']);
        }

        // Apply relationships
        if (isset($normalizedFilters['with']) && is_array($normalizedFilters['with']) && count($normalizedFilters['with']) > 0) {
            /** @var array<int, string> $with */
            $with = array_values(array_filter($normalizedFilters['with'], fn ($v) => is_string($v)));
            $query->with($this->getSafeRelations($with));
        }

        $limit = 25;
        if (isset($normalizedFilters['limit']) && is_numeric($normalizedFilters['limit'])) {
            $limit = (int) $normalizedFilters['limit'];
        }

        $total = (clone $query)->count();

        $forms = $query
            ->orderBy('submissions_count', 'desc')
            ->orderBy('handle')
            ->limit($limit)
            ->get();

        return new FormSearchResult(
            forms: $forms,
            total: $total,
        );
    }

    /**
     * Get columns to select based on filters.
     *
     * @param  array  $filters  Request filters
     * @return array Array of column names
     */
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    private function getSelectColumns(array $filters): array
    {
        $base = [
            'id',
            'handle',
            'revision',
            'status',
            'restrict_public_access',
            'submissions_count',
            'last_used_at',
            'created_at',
            'updated_at',
        ];

        // Add additional columns if relations are requested
        if (isset($filters['with']) && is_array($filters['with'])) {
            $with = $filters['with'];
            // fields relation removed
            if (in_array('entries', $with, true)) {
                // Entries relation doesn't need additional columns
            }
        }

        return $base;
    }

    /**
     * Get safe relations to load.
     *
     * @param  array  $relations  Requested relations
     * @return array Array of safe relation constraints
     */
    /**
     * @param  array<int, string>  $relations
     * @return array<int, string>
     */
    private function getSafeRelations(array $relations): array
    {
        $allowed = [
            'entries' => 'entries:id,form_id,subject,email,created_at',
        ];

        return array_values(
            array_intersect_key($allowed, array_flip($relations))
        );
    }
}
