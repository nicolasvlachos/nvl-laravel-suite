<?php

declare(strict_types=1);

namespace Nvl\Csv\Filters;

/**
 * Base filter for CSV row filtering.
 */
abstract class CSVFilter
{
    /**
     * Check if row passes filter.
     *
     * @param  array<string, mixed>  $row  Row data
     * @return bool True when row passes filter
     */
    abstract public function passes(array $row): bool;

    /**
     * Invert filter logic.
     *
     * @return NotFilter Inverted filter instance
     */
    public function not(): NotFilter
    {
        return new NotFilter($this);
    }

    /**
     * Combine with another filter using AND logic.
     *
     * @param  CSVFilter  $filter  Filter to combine
     * @return AndFilter Combined filter instance
     */
    public function and(CSVFilter $filter): AndFilter
    {
        return new AndFilter([$this, $filter]);
    }

    /**
     * Combine with another filter using OR logic.
     *
     * @param  CSVFilter  $filter  Filter to combine
     * @return OrFilter Combined filter instance
     */
    public function or(CSVFilter $filter): OrFilter
    {
        return new OrFilter([$this, $filter]);
    }

    /**
     * Create field filter.
     *
     * @param  string  $field  Field name to evaluate
     * @return FieldFilter Field filter instance
     */
    public static function field(string $field): FieldFilter
    {
        return new FieldFilter($field);
    }

    /**
     * Create custom filter.
     *
     * @param  callable  $callback  Callback filter predicate
     * @return CustomFilter Custom filter instance
     */
    public static function custom(callable $callback): CustomFilter
    {
        return new CustomFilter($callback);
    }

    /**
     * Create all filter (combines multiple filters with AND).
     *
     * @param  array<int, CSVFilter>  $filters  Filters to combine
     * @return AndFilter Combined filter instance
     */
    public static function all(array $filters): AndFilter
    {
        return new AndFilter($filters);
    }

    /**
     * Create any filter (combines multiple filters with OR).
     *
     * @param  array<int, CSVFilter>  $filters  Filters to combine
     * @return OrFilter Combined filter instance
     */
    public static function any(array $filters): OrFilter
    {
        return new OrFilter($filters);
    }
}
