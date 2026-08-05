<?php

declare(strict_types=1);

namespace Nvl\Forms\Traits;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Filterable\Traits\Filterable;
use Nvl\Forms\Models\FormEntry;

/**
 * Custom filter methods for the FormEntry model.
 */
trait FormEntryFilters
{
    /** @use Filterable<FormEntry> */
    use Filterable;

    /**
     * Declare form-entry query aliases.
     */
    public function filterSchema(): FilterSchema
    {
        $textOperators = [FilterOperator::Equals, FilterOperator::Contains];

        return new FilterSchema(
            filters: [
                new FilterDefinition(
                    'search',
                    'subject',
                    handler: fn (Builder $query, FilterCriterion $criterion): Builder => $this->filterSearch(
                        $query,
                        $criterion->value,
                    ),
                ),
                new FilterDefinition('subject', 'subject', operators: $textOperators),
                new FilterDefinition('email', 'email', operators: $textOperators),
                new FilterDefinition('first_name', 'first_name', operators: $textOperators),
                new FilterDefinition('last_name', 'last_name', operators: $textOperators),
                new FilterDefinition('phone', 'phone', operators: $textOperators),
                new FilterDefinition('submitted_from', 'submitted_from', operators: $textOperators),
                new FilterDefinition('ip_address', 'ip_address'),
                new FilterDefinition('is_spam', 'is_spam', FilterValueType::Boolean),
                new FilterDefinition('form_name', 'form.translations.name', operators: $textOperators),
                new FilterDefinition('form_handle', 'form.handle', operators: $textOperators),
                new FilterDefinition(
                    'created_at',
                    'created_at',
                    FilterValueType::DateTime,
                    [FilterOperator::Before, FilterOperator::After, FilterOperator::Between],
                ),
            ],
            sorts: array_map(
                static fn (string $column): SortDefinition => new SortDefinition($column, $column),
                ['subject', 'email', 'first_name', 'last_name', 'submitted_from', 'created_at', 'updated_at', 'id'],
            ),
            defaultSorts: ['-created_at'],
            tieBreakerSort: 'id',
        );
    }

    /**
     * Filter by search term across multiple fields.
     *
     * @param  Builder<*>  $query  Query builder instance
     * @return Builder<*> Modified query builder
     */
    public function filterSearch(Builder $query, mixed $value): Builder
    {
        if (! is_string($value)) {
            return $query;
        }

        $searchTerm = '%'.mb_strtolower($value).'%';

        return $query->where(function (Builder $q) use ($searchTerm): void {
            $q->whereRaw('LOWER(subject) LIKE ?', [$searchTerm])
                ->orWhereRaw('LOWER(email) LIKE ?', [$searchTerm])
                ->orWhereRaw('LOWER(first_name) LIKE ?', [$searchTerm])
                ->orWhereRaw('LOWER(last_name) LIKE ?', [$searchTerm])
                ->orWhereRaw('LOWER(body) LIKE ?', [$searchTerm]);
        });
    }
}
