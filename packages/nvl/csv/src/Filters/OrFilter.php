<?php

declare(strict_types=1);

namespace Nvl\Csv\Filters;

/**
 * Combines multiple filters with OR logic.
 */
final class OrFilter extends CSVFilter
{
    /** @var array<CSVFilter> */
    private array $filters;

    /**
     * Create an OR filter.
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
     * Check if row passes any filter.
     *
     * @param  array<string, mixed>  $row  Row data
     * @return bool True when row passes any filter
     */
    public function passes(array $row): bool
    {
        foreach ($this->filters as $filter) {
            if ($filter->passes($row)) {
                return true;
            }
        }

        return false;
    }
}
