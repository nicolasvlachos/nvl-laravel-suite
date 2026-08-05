<?php

declare(strict_types=1);

namespace Nvl\Filterable\Definitions;

use Nvl\Filterable\Exceptions\FilterableException;

/**
 * Immutable allowlist for a query endpoint.
 */
final readonly class FilterSchema
{
    /**
     * @var list<FilterDefinition>
     */
    public array $filters;

    /**
     * @var list<SortDefinition>
     */
    public array $sorts;

    /**
     * @var list<string>
     */
    public array $defaultSorts;

    /**
     * @var array<string, FilterDefinition>
     */
    private array $filtersByAlias;

    /**
     * @var array<string, SortDefinition>
     */
    private array $sortsByAlias;

    /**
     * Create a filter schema.
     *
     * @param  array<array-key, mixed>  $filters
     * @param  array<array-key, mixed>  $sorts
     * @param  array<array-key, mixed>  $defaultSorts  Sort aliases, optionally prefixed with `-`.
     */
    public function __construct(
        array $filters,
        array $sorts,
        array $defaultSorts = [],
        public int $maximumFilters = 25,
        public int $maximumSorts = 5,
        public int $maximumValuesPerFilter = 100,
        public int $maximumStringLength = 255,
        public ?string $tieBreakerSort = null,
    ) {
        $this->filters = $this->validatedFilters($filters);
        $this->sorts = $this->validatedSorts($sorts);
        $this->defaultSorts = $this->validatedDefaultSorts($defaultSorts);

        $this->assertLimit($maximumFilters, 1, 100, 'Maximum filters', 'invalid_maximum_filters');
        $this->assertLimit($maximumSorts, 1, 25, 'Maximum sorts', 'invalid_maximum_sorts');
        $this->assertLimit(
            $maximumValuesPerFilter,
            1,
            1_000,
            'Maximum values per filter',
            'invalid_maximum_filter_values',
        );
        $this->assertLimit(
            $maximumStringLength,
            1,
            10_000,
            'Maximum string length',
            'invalid_maximum_string_length',
        );

        $this->filtersByAlias = $this->indexFilters();
        $this->sortsByAlias = $this->indexSorts();
        $defaultAliases = [];

        foreach ($this->defaultSorts as $sort) {
            $alias = str_starts_with($sort, '-') ? substr($sort, 1) : $sort;

            if ($alias === '' || str_starts_with($alias, '-') || $this->sort($alias) === null) {
                throw new FilterableException(
                    "Default sort [{$alias}] is not declared.",
                    'unknown_default_sort',
                    'sort',
                );
            }

            $defaultAliases[] = $alias;
        }

        if (count($defaultAliases) !== count(array_unique($defaultAliases))) {
            throw new FilterableException(
                'Duplicate default sorts are not allowed.',
                'duplicate_default_sort',
                'sort',
            );
        }

        if (count($this->defaultSorts) > $maximumSorts) {
            throw new FilterableException(
                'Default sorts exceed the sort complexity limit.',
                'sort_complexity_exceeded',
                'sort',
            );
        }

        if ($tieBreakerSort !== null && $this->sort($tieBreakerSort) === null) {
            throw new FilterableException(
                "Tie-breaker sort [{$tieBreakerSort}] is not declared.",
                'unknown_tie_breaker_sort',
                'sort',
            );
        }
    }

    /**
     * Find a filter by its public alias.
     */
    public function filter(string $alias): ?FilterDefinition
    {
        return $this->filtersByAlias[$alias] ?? null;
    }

    /**
     * Find a sort by its public alias.
     */
    public function sort(string $alias): ?SortDefinition
    {
        return $this->sortsByAlias[$alias] ?? null;
    }

    /**
     * Validate and narrow filter definitions.
     *
     * @param  array<array-key, mixed>  $definitions
     * @return list<FilterDefinition>
     */
    private function validatedFilters(array $definitions): array
    {
        if (! array_is_list($definitions)) {
            throw new FilterableException('Filter definitions must be a list.', 'invalid_filter_schema');
        }

        $validated = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof FilterDefinition) {
                throw new FilterableException('Filter definitions are invalid.', 'invalid_filter_schema');
            }

            $validated[] = $definition;
        }

        return $validated;
    }

    /**
     * Validate and narrow sort definitions.
     *
     * @param  array<array-key, mixed>  $definitions
     * @return list<SortDefinition>
     */
    private function validatedSorts(array $definitions): array
    {
        if (! array_is_list($definitions)) {
            throw new FilterableException('Sort definitions must be a list.', 'invalid_filter_schema');
        }

        $validated = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof SortDefinition) {
                throw new FilterableException('Sort definitions are invalid.', 'invalid_filter_schema');
            }

            $validated[] = $definition;
        }

        return $validated;
    }

    /**
     * Validate and narrow default sorts.
     *
     * @param  array<array-key, mixed>  $sorts
     * @return list<string>
     */
    private function validatedDefaultSorts(array $sorts): array
    {
        if (! array_is_list($sorts)) {
            throw new FilterableException('Default sorts must be a list.', 'invalid_default_sort', 'sort');
        }

        $validated = [];

        foreach ($sorts as $sort) {
            if (! is_string($sort) || $sort === '') {
                throw new FilterableException(
                    'Default sorts must be non-empty strings.',
                    'invalid_default_sort',
                    'sort',
                );
            }

            $validated[] = $sort;
        }

        return $validated;
    }

    /**
     * Index filters and reject duplicate public aliases.
     *
     * @return array<string, FilterDefinition>
     */
    private function indexFilters(): array
    {
        $indexed = [];

        foreach ($this->filters as $definition) {
            if (isset($indexed[$definition->alias])) {
                throw new FilterableException(
                    "Duplicate filter alias [{$definition->alias}] is not allowed.",
                    'duplicate_filter_alias',
                    'filter',
                );
            }

            $indexed[$definition->alias] = $definition;
        }

        return $indexed;
    }

    /**
     * Index sorts and reject duplicate public aliases.
     *
     * @return array<string, SortDefinition>
     */
    private function indexSorts(): array
    {
        $indexed = [];

        foreach ($this->sorts as $definition) {
            if (isset($indexed[$definition->alias])) {
                throw new FilterableException(
                    "Duplicate sort alias [{$definition->alias}] is not allowed.",
                    'duplicate_sort_alias',
                    'sort',
                );
            }

            $indexed[$definition->alias] = $definition;
        }

        return $indexed;
    }

    /**
     * Validate a bounded schema complexity setting.
     */
    private function assertLimit(
        int $value,
        int $minimum,
        int $maximum,
        string $label,
        string $errorCode,
    ): void {
        if ($value < $minimum || $value > $maximum) {
            throw new FilterableException(
                "{$label} must be between {$minimum} and {$maximum}.",
                $errorCode,
            );
        }
    }
}
