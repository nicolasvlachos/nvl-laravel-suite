<?php

declare(strict_types=1);

namespace Nvl\Filterable\Definitions;

use Nvl\Filterable\Exceptions\FilterableException;

/**
 * Declares one public sort alias.
 */
final readonly class SortDefinition
{
    /**
     * Create a sort definition.
     *
     * @param  non-empty-string  $alias
     * @param  non-empty-string  $column
     */
    public function __construct(
        public string $alias,
        public string $column,
    ) {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $alias)) {
            throw new FilterableException(
                "Sort alias [{$alias}] is invalid.",
                'invalid_sort_alias',
                'sort',
            );
        }

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new FilterableException(
                "Sort [{$alias}] has an invalid column.",
                'invalid_sort_column',
                "sort.{$alias}",
            );
        }
    }
}
