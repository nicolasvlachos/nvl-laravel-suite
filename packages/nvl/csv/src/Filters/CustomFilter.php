<?php

declare(strict_types=1);

namespace Nvl\Csv\Filters;

use Closure;

/**
 * Custom filter using a callback function.
 */
final class CustomFilter extends CSVFilter
{
    private Closure $callback;

    /**
     * Create a custom filter using a callback.
     *
     * @param  callable  $callback  Callback that returns true when row passes
     * @return void
     */
    public function __construct(callable $callback)
    {
        $this->callback = Closure::fromCallable($callback);
    }

    /**
     * Check if row passes custom filter.
     *
     * @param  array<string, mixed>  $row  Row data
     * @return bool True when row passes filter
     */
    public function passes(array $row): bool
    {
        return (bool) ($this->callback)($row);
    }
}
