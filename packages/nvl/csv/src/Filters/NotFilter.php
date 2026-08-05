<?php

declare(strict_types=1);

namespace Nvl\Csv\Filters;

/**
 * Inverts the logic of another filter.
 */
final class NotFilter extends CSVFilter
{
    private CSVFilter $filter;

    /**
     * Create an inverted filter.
     *
     * @param  CSVFilter  $filter  Filter to invert
     * @return void
     */
    public function __construct(CSVFilter $filter)
    {
        $this->filter = $filter;
    }

    /**
     * Check if row does NOT pass the wrapped filter.
     *
     * @param  array<string, mixed>  $row  Row data
     * @return bool True when row fails wrapped filter
     */
    public function passes(array $row): bool
    {
        return ! $this->filter->passes($row);
    }
}
