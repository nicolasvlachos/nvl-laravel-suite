<?php

declare(strict_types=1);

namespace Nvl\Csv\Filters;

/**
 * Combines multiple filters with AND logic.
 */
final class AndFilter extends CSVFilter
{
    /** @var array<CSVFilter> */
    private array $filters;

    /**
     * Create an AND filter.
     *
     * @param  array<int, CSVFilter>  $filters  Filters to combine
     * @return void
     */
    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    /**
     * Add another filter.
     *
     * @param  CSVFilter  $filter  Filter to add
     * @return self Filter instance
     */
    public function add(CSVFilter $filter): self
    {
        $this->filters[] = $filter;

        return $this;
    }

    /**
     * Check if row passes all filters.
     *
     * @param  array<string, mixed>  $row  Row data
     * @return bool True when row passes all filters
     */
    public function passes(array $row): bool
    {
        foreach ($this->filters as $filter) {
            if (! $filter->passes($row)) {
                return false;
            }
        }

        return true;
    }
}
