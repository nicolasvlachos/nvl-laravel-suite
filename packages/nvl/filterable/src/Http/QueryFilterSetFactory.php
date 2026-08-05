<?php

declare(strict_types=1);

namespace Nvl\Filterable\Http;

use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Data\SortCriterion;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\SortDirection;
use Nvl\Filterable\Exceptions\FilterableException;
use Nvl\Filterable\Services\FilterCriterionNormalizer;

/**
 * Converts HTTP query parameters into a validated transport-neutral filter set.
 */
final class QueryFilterSetFactory
{
    /**
     * Create an HTTP query adapter.
     */
    public function __construct(
        private readonly FilterCriterionNormalizer $normalizer,
    ) {}

    /**
     * Build a filter set from query parameters.
     *
     * @param  array<string, mixed>  $query
     */
    public function fromQuery(array $query, FilterSchema $schema): FilterSet
    {
        $rawFilters = $query['filter'] ?? [];
        $rawSorts = $query['sort'] ?? [];

        if (! is_array($rawFilters)) {
            throw new FilterableException(
                'The filter parameter must be an object.',
                'invalid_filter_shape',
                'filter',
            );
        }

        if (count($rawFilters) > $schema->maximumFilters) {
            throw new FilterableException(
                'The filter complexity limit was exceeded.',
                'filter_complexity_exceeded',
                'filter',
            );
        }

        $filters = [];

        foreach ($rawFilters as $alias => $payload) {
            if (! is_string($alias)) {
                throw new FilterableException(
                    'Filter aliases must be strings.',
                    'invalid_filter_alias',
                    'filter',
                );
            }

            $definition = $schema->filter($alias);

            if ($definition === null) {
                throw new FilterableException(
                    "Unknown filter alias [{$alias}].",
                    'unknown_filter_alias',
                    "filter.{$alias}",
                );
            }

            if (! is_array($payload)) {
                $rawOperator = FilterOperator::Equals->value;
                $value = $payload;
            } else {
                $unexpectedKeys = array_diff(array_keys($payload), ['operator', 'value']);

                if ($unexpectedKeys !== [] || ! array_key_exists('operator', $payload)) {
                    throw new FilterableException(
                        "Filter [{$alias}] must contain only an operator and its required value.",
                        'invalid_filter_shape',
                        "filter.{$alias}",
                    );
                }

                $rawOperator = $payload['operator'];
                $value = $payload['value'] ?? null;
            }

            $operator = is_string($rawOperator) ? FilterOperator::tryFrom($rawOperator) : null;

            if ($operator === null) {
                throw new FilterableException(
                    "Unsupported filter operator for [{$alias}].",
                    'unsupported_filter_operator',
                    "filter.{$alias}.operator",
                );
            }

            if (! in_array($operator, $definition->operators, true)) {
                throw new FilterableException(
                    "Operator [{$operator->value}] is not allowed for [{$alias}].",
                    'disallowed_filter_operator',
                    "filter.{$alias}.operator",
                );
            }

            $isNullCheck = in_array($operator, [FilterOperator::IsNull, FilterOperator::IsNotNull], true);
            $hasValue = is_array($payload) && array_key_exists('value', $payload);

            if ($isNullCheck && $hasValue) {
                throw new FilterableException(
                    "Null-check filter [{$alias}] must not contain a value.",
                    'unexpected_filter_value',
                    "filter.{$alias}.value",
                );
            }

            if (! $isNullCheck && is_array($payload) && ! $hasValue) {
                throw new FilterableException(
                    "Filter [{$alias}] requires a value.",
                    'missing_filter_value',
                    "filter.{$alias}.value",
                );
            }

            $filters[] = $this->normalizer->normalize(
                new FilterCriterion($alias, $operator, $isNullCheck ? null : $value),
                $definition,
                $schema,
            );
        }

        return new FilterSet($filters, $this->sorts($rawSorts, $schema));
    }

    /**
     * Build a filter set and map malformed HTTP input to a standard 422 response.
     *
     * @param  array<string, mixed>  $query
     */
    public function fromHttpQuery(array $query, FilterSchema $schema): FilterSet
    {
        try {
            return $this->fromQuery($query, $schema);
        } catch (FilterableException $exception) {
            throw $exception->toValidationException();
        }
    }

    /**
     * Parse HTTP sort syntax.
     *
     * @return list<SortCriterion>
     */
    private function sorts(mixed $input, FilterSchema $schema): array
    {
        if ($input === null || $input === '') {
            return [];
        }

        if (! is_string($input) && ! is_array($input)) {
            throw new FilterableException(
                'The sort parameter must be a string or object.',
                'invalid_sort_shape',
                'sort',
            );
        }

        $sorts = [];
        $aliases = [];
        $pairs = is_string($input) ? explode(',', $input) : $input;

        if (count($pairs) > $schema->maximumSorts) {
            throw new FilterableException(
                'The sort complexity limit was exceeded.',
                'sort_complexity_exceeded',
                'sort',
            );
        }

        foreach ($pairs as $key => $value) {
            if (is_int($key)) {
                if (! is_string($value) || $value === '') {
                    throw new FilterableException(
                        'Sort values must be non-empty strings.',
                        'invalid_sort_shape',
                        'sort',
                    );
                }

                $direction = str_starts_with($value, '-') ? SortDirection::Desc : SortDirection::Asc;
                $alias = $direction === SortDirection::Desc ? substr($value, 1) : $value;
            } else {
                $alias = $key;
                $direction = is_string($value)
                    ? SortDirection::tryFrom(strtolower($value))
                    : null;
            }

            if ($alias === '' || str_starts_with($alias, '-') || $schema->sort($alias) === null) {
                throw new FilterableException(
                    "Unknown sort alias [{$alias}].",
                    'unknown_sort_alias',
                    'sort',
                );
            }

            if ($direction === null) {
                throw new FilterableException(
                    "Sort [{$alias}] direction must be asc or desc.",
                    'invalid_sort_direction',
                    'sort',
                );
            }

            if (in_array($alias, $aliases, true)) {
                throw new FilterableException(
                    "Duplicate sort [{$alias}] is not allowed.",
                    'duplicate_sort',
                    'sort',
                );
            }

            $sorts[] = new SortCriterion($alias, $direction);
            $aliases[] = $alias;
        }

        return $sorts;
    }
}
