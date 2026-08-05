<?php

declare(strict_types=1);

namespace Nvl\Filterable\Definitions;

use Closure;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Filterable\Exceptions\FilterableException;

/**
 * Declares one public filter alias and its database behavior.
 */
final readonly class FilterDefinition
{
    /**
     * @var list<FilterOperator>
     */
    public array $operators;

    /**
     * @var list<string>
     */
    public array $enumValues;

    /**
     * Create a filter definition.
     *
     * @param  non-empty-string  $alias
     * @param  non-empty-string  $column
     * @param  array<array-key, mixed>  $operators
     * @param  array<array-key, mixed>  $enumValues
     * @param  (Closure(\Illuminate\Database\Eloquent\Builder<*>, \Nvl\Filterable\Data\FilterCriterion): \Illuminate\Database\Eloquent\Builder<*>)|null  $handler
     */
    public function __construct(
        public string $alias,
        public string $column,
        public FilterValueType $type = FilterValueType::String,
        array $operators = [FilterOperator::Equals],
        public bool $nullable = false,
        array $enumValues = [],
        public ?Closure $handler = null,
    ) {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $alias)) {
            throw new FilterableException(
                "Filter alias [{$alias}] is invalid.",
                'invalid_filter_alias',
                'filter',
            );
        }

        if (! preg_match(
            '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*){0,2}$/',
            $column,
        )) {
            throw new FilterableException(
                "Filter [{$alias}] has an invalid column or relation path.",
                'invalid_filter_column',
                "filter.{$alias}",
            );
        }

        $this->operators = $this->validatedOperators($operators);
        $this->enumValues = $this->validatedEnumValues($enumValues);

        if ($this->operators === []) {
            throw new FilterableException(
                "Filter [{$alias}] must declare a list of operators.",
                'invalid_filter_operators',
                "filter.{$alias}.operator",
            );
        }

        if (count($this->operators) !== count(array_unique($this->operators, SORT_REGULAR))) {
            throw new FilterableException(
                "Filter [{$alias}] cannot declare duplicate operators.",
                'duplicate_filter_operator',
                "filter.{$alias}.operator",
            );
        }

        if ($type === FilterValueType::Enum && $this->enumValues === []) {
            throw new FilterableException(
                "Enum filter [{$alias}] must declare allowed values.",
                'missing_enum_values',
                "filter.{$alias}.value",
            );
        }

        if ($type !== FilterValueType::Enum && $this->enumValues !== []) {
            throw new FilterableException(
                "Only enum filter [{$alias}] may declare enum values.",
                'unexpected_enum_values',
                "filter.{$alias}.value",
            );
        }

        if (count($this->enumValues) !== count(array_unique($this->enumValues))) {
            throw new FilterableException(
                "Enum filter [{$alias}] cannot declare duplicate values.",
                'duplicate_enum_value',
                "filter.{$alias}.value",
            );
        }

        foreach ($this->operators as $operator) {
            if (! $this->supports($operator)) {
                throw new FilterableException(
                    "Operator [{$operator->value}] is incompatible with filter [{$alias}].",
                    'incompatible_filter_operator',
                    "filter.{$alias}.operator",
                );
            }

            if (
                in_array($operator, [FilterOperator::IsNull, FilterOperator::IsNotNull], true)
                && ! $nullable
            ) {
                throw new FilterableException(
                    "Filter [{$alias}] must be nullable to allow [{$operator->value}].",
                    'non_nullable_filter_operator',
                    "filter.{$alias}.operator",
                );
            }
        }
    }

    /**
     * Validate and narrow an operator list.
     *
     * @param  array<array-key, mixed>  $operators
     * @return list<FilterOperator>
     */
    private function validatedOperators(array $operators): array
    {
        if (! array_is_list($operators)) {
            throw new FilterableException(
                "Filter [{$this->alias}] operators must be a list.",
                'invalid_filter_operators',
                "filter.{$this->alias}.operator",
            );
        }

        $validated = [];

        foreach ($operators as $operator) {
            if (! $operator instanceof FilterOperator) {
                throw new FilterableException(
                    "Filter [{$this->alias}] contains an invalid operator.",
                    'invalid_filter_operators',
                    "filter.{$this->alias}.operator",
                );
            }

            $validated[] = $operator;
        }

        return $validated;
    }

    /**
     * Validate and narrow an enum-value list.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function validatedEnumValues(array $values): array
    {
        if (! array_is_list($values)) {
            throw new FilterableException(
                "Enum filter [{$this->alias}] values must be a list.",
                'invalid_enum_values',
                "filter.{$this->alias}.value",
            );
        }

        $validated = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new FilterableException(
                    "Enum filter [{$this->alias}] values must be non-empty strings.",
                    'invalid_enum_values',
                    "filter.{$this->alias}.value",
                );
            }

            $validated[] = $value;
        }

        return $validated;
    }

    /**
     * Determine whether an operator is meaningful for this value type.
     */
    private function supports(FilterOperator $operator): bool
    {
        $common = [
            FilterOperator::Equals,
            FilterOperator::NotEquals,
            FilterOperator::In,
            FilterOperator::NotIn,
            FilterOperator::IsNull,
            FilterOperator::IsNotNull,
        ];

        return match ($this->type) {
            FilterValueType::Boolean, FilterValueType::Enum => in_array($operator, $common, true),
            FilterValueType::String => in_array(
                $operator,
                [...$common, FilterOperator::Contains, FilterOperator::NotContains],
                true,
            ),
            FilterValueType::Integer, FilterValueType::Decimal => in_array(
                $operator,
                [
                    ...$common,
                    FilterOperator::Between,
                    FilterOperator::Gt,
                    FilterOperator::Lt,
                    FilterOperator::Gte,
                    FilterOperator::Lte,
                ],
                true,
            ),
            FilterValueType::Date, FilterValueType::DateTime => in_array(
                $operator,
                [
                    ...$common,
                    FilterOperator::Before,
                    FilterOperator::After,
                    FilterOperator::Between,
                    FilterOperator::Gt,
                    FilterOperator::Lt,
                    FilterOperator::Gte,
                    FilterOperator::Lte,
                ],
                true,
            ),
        };
    }
}
