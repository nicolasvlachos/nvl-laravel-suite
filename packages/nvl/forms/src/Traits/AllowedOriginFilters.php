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
use Nvl\Forms\Models\AllowedOrigin;

/**
 * Custom filter methods for the AllowedOrigin model.
 */
trait AllowedOriginFilters
{
    /** @use Filterable<AllowedOrigin> */
    use Filterable;

    /**
     * Declare allowed-origin query aliases.
     */
    public function filterSchema(): FilterSchema
    {
        return new FilterSchema(
            filters: [
                new FilterDefinition(
                    'search',
                    'origin',
                    handler: fn (Builder $query, FilterCriterion $criterion): Builder => $this->filterSearch(
                        $query,
                        $criterion->value,
                    ),
                ),
                new FilterDefinition('form_id', 'form_id'),
                new FilterDefinition(
                    'origin',
                    'origin',
                    operators: [FilterOperator::Equals, FilterOperator::Contains],
                ),
                new FilterDefinition('is_active', 'is_active', FilterValueType::Boolean),
                new FilterDefinition(
                    'created_at',
                    'created_at',
                    FilterValueType::DateTime,
                    [FilterOperator::Equals, FilterOperator::Before, FilterOperator::After, FilterOperator::Between],
                ),
                new FilterDefinition(
                    'last_used_at',
                    'last_used_at',
                    FilterValueType::DateTime,
                    [FilterOperator::Before, FilterOperator::After, FilterOperator::Between, FilterOperator::IsNull],
                    nullable: true,
                ),
            ],
            sorts: array_map(
                static fn (string $column): SortDefinition => new SortDefinition($column, $column),
                ['origin', 'usage_count', 'last_used_at', 'created_at', 'updated_at', 'id'],
            ),
            defaultSorts: ['origin'],
            tieBreakerSort: 'id',
        );
    }

    /**
     * Filter by search term across origin and description fields.
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
            $q->whereRaw('LOWER(origin) LIKE ?', [$searchTerm])
                ->orWhereRaw('LOWER(description) LIKE ?', [$searchTerm]);
        });
    }
}
