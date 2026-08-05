<?php

declare(strict_types=1);

namespace Nvl\Filterable\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Data\SortCriterion;
use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\SortDirection;
use Nvl\Filterable\Exceptions\FilterableException;

/**
 * Applies validated aliases without accepting raw database identifiers.
 */
final readonly class EloquentFilterApplier
{
    /**
     * Create an Eloquent filter applier.
     */
    public function __construct(
        private FilterCriterionNormalizer $normalizer,
    ) {}

    /**
     * Apply filters and deterministic sorting to an Eloquent query.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, FilterSet $set, FilterSchema $schema): Builder
    {
        if (count($set->filters) > $schema->maximumFilters) {
            throw new FilterableException(
                'The filter complexity limit was exceeded.',
                'filter_complexity_exceeded',
                'filter',
            );
        }

        foreach ($set->filters as $criterion) {
            $definition = $schema->filter($criterion->alias);

            if ($definition === null) {
                throw new FilterableException(
                    "Unknown filter alias [{$criterion->alias}].",
                    'unknown_filter_alias',
                    "filter.{$criterion->alias}",
                );
            }

            if (! in_array($criterion->operator, $definition->operators, true)) {
                throw new FilterableException(
                    "Operator [{$criterion->operator->value}] is not allowed for [{$criterion->alias}].",
                    'disallowed_filter_operator',
                    "filter.{$criterion->alias}.operator",
                );
            }

            $normalized = $this->normalizer->normalize($criterion, $definition, $schema);
            $this->applyDefinition($query, $normalized, $definition);
        }

        $this->applySorts($query, $set, $schema);

        return $query;
    }

    /**
     * Apply one declared filter, including a registered relation alias.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyDefinition(
        Builder $query,
        FilterCriterion $criterion,
        FilterDefinition $definition,
    ): void {
        if ($definition->handler !== null) {
            ($definition->handler)($query, $criterion);

            return;
        }

        $segments = explode('.', $definition->column);
        $column = array_pop($segments);

        if ($segments === []) {
            $this->applyOperator($query, $column, $criterion);

            return;
        }

        $query->whereHas(
            implode('.', $segments),
            fn (Builder $related): Builder => $this->applyOperator($related, $column, $criterion),
        );
    }

    /**
     * Apply one normalized operator.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function applyOperator(
        Builder $query,
        string $column,
        FilterCriterion $criterion,
    ): Builder {
        $operator = $criterion->operator;

        if ($operator === FilterOperator::IsNull) {
            return $query->whereNull($column);
        }

        if ($operator === FilterOperator::IsNotNull) {
            return $query->whereNotNull($column);
        }

        if ($operator === FilterOperator::In) {
            return $query->whereIn($column, $this->normalizedList($criterion));
        }

        if ($operator === FilterOperator::NotIn) {
            return $query->whereNotIn($column, $this->normalizedList($criterion));
        }

        if ($operator === FilterOperator::Between) {
            return $query->whereBetween($column, $this->normalizedList($criterion));
        }

        return match ($operator) {
            FilterOperator::Equals => $query->where($column, '=', $criterion->value),
            FilterOperator::NotEquals => $query->where($column, '!=', $criterion->value),
            FilterOperator::Contains => $this->whereContains($query, $column, $criterion, false),
            FilterOperator::NotContains => $this->whereContains($query, $column, $criterion, true),
            FilterOperator::Before, FilterOperator::Lt => $query->where($column, '<', $criterion->value),
            FilterOperator::After, FilterOperator::Gt => $query->where($column, '>', $criterion->value),
            FilterOperator::Gte => $query->where($column, '>=', $criterion->value),
            FilterOperator::Lte => $query->where($column, '<=', $criterion->value),
        };
    }

    /**
     * Apply literal substring matching with portable wildcard escaping.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function whereContains(
        Builder $query,
        string $column,
        FilterCriterion $criterion,
        bool $negated,
    ): Builder {
        if (! is_string($criterion->value)) {
            throw new FilterableException(
                "Filter [{$criterion->alias}] requires a string value.",
                'invalid_string_filter_value',
                "filter.{$criterion->alias}.value",
            );
        }

        $grammar = $query->getQuery()->getGrammar();
        $wrapped = $grammar->wrap($column);
        $this->ensureSafeWrappedColumn($wrapped);
        $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $criterion->value).'%';

        return $negated
            ? $query->whereRaw("{$wrapped} NOT LIKE ? ESCAPE '!'", [$needle])
            : $query->whereRaw("{$wrapped} LIKE ? ESCAPE '!'", [$needle]);
    }

    /**
     * Read an already-normalized list value.
     *
     * @return list<mixed>
     */
    private function normalizedList(FilterCriterion $criterion): array
    {
        if (! is_array($criterion->value) || ! array_is_list($criterion->value)) {
            throw new FilterableException(
                "Filter [{$criterion->alias}] requires a normalized value list.",
                'invalid_filter_value_shape',
                "filter.{$criterion->alias}.value",
            );
        }

        return $criterion->value;
    }

    /**
     * Prove that a database grammar returned only one quoted, allowlisted identifier.
     *
     * @phpstan-assert literal-string $column
     */
    private function ensureSafeWrappedColumn(string $column): void
    {
        if (preg_match('/\A(?:[A-Za-z_][A-Za-z0-9_]*|`[A-Za-z_][A-Za-z0-9_]*`|"[A-Za-z_][A-Za-z0-9_]*"|\[[A-Za-z_][A-Za-z0-9_]*\])\z/', $column) !== 1) {
            throw new FilterableException(
                'The database grammar returned an unsafe column identifier.',
                'unsafe_wrapped_filter_column',
            );
        }
    }

    /**
     * Apply explicit or default sorts and append the declared tie-breaker.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applySorts(Builder $query, FilterSet $set, FilterSchema $schema): void
    {
        if (count($set->sorts) > $schema->maximumSorts) {
            throw new FilterableException(
                'The sort complexity limit was exceeded.',
                'sort_complexity_exceeded',
                'sort',
            );
        }

        $sorts = $set->sorts === []
            ? array_map(
                static fn (string $sort): SortCriterion => new SortCriterion(
                    str_starts_with($sort, '-') ? substr($sort, 1) : $sort,
                    str_starts_with($sort, '-') ? SortDirection::Desc : SortDirection::Asc,
                ),
                $schema->defaultSorts,
            )
            : $set->sorts;

        $aliases = [];

        foreach ($sorts as $sort) {
            if (in_array($sort->alias, $aliases, true)) {
                throw new FilterableException(
                    "Duplicate sort [{$sort->alias}] is not allowed.",
                    'duplicate_sort',
                    'sort',
                );
            }

            $definition = $schema->sort($sort->alias);

            if ($definition === null) {
                throw new FilterableException(
                    "Unknown sort alias [{$sort->alias}].",
                    'unknown_sort_alias',
                    'sort',
                );
            }

            $query->orderBy($definition->column, $sort->direction->value);
            $aliases[] = $sort->alias;
        }

        if ($schema->tieBreakerSort !== null && ! in_array($schema->tieBreakerSort, $aliases, true)) {
            $tieBreaker = $schema->sort($schema->tieBreakerSort);

            if ($tieBreaker === null) {
                throw new FilterableException(
                    "Unknown tie-breaker sort [{$schema->tieBreakerSort}].",
                    'unknown_tie_breaker_sort',
                    'sort',
                );
            }

            $query->orderBy($tieBreaker->column);
        }
    }
}
