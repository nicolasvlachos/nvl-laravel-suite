<?php

declare(strict_types=1);

namespace Nvl\Filterable\Services;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Filterable\Exceptions\FilterableException;
use Stringable;
use Throwable;

/**
 * Normalizes untrusted criterion values before query or custom-handler use.
 */
final class FilterCriterionNormalizer
{
    /**
     * Normalize one criterion using its immutable schema definition.
     */
    public function normalize(
        FilterCriterion $criterion,
        FilterDefinition $definition,
        FilterSchema $schema,
    ): FilterCriterion {
        if (in_array($criterion->operator, [FilterOperator::IsNull, FilterOperator::IsNotNull], true)) {
            if ($criterion->value !== null) {
                throw new FilterableException(
                    "Null-check filter [{$criterion->alias}] must not contain a value.",
                    'unexpected_filter_value',
                    "filter.{$criterion->alias}.value",
                );
            }

            return $criterion;
        }

        if (in_array($criterion->operator, [FilterOperator::In, FilterOperator::NotIn], true)) {
            return new FilterCriterion(
                $criterion->alias,
                $criterion->operator,
                $this->listValues($criterion->value, $definition, $schema),
            );
        }

        if ($criterion->operator === FilterOperator::Between) {
            $values = $this->listValues($criterion->value, $definition, $schema);

            if (count($values) !== 2) {
                throw new FilterableException(
                    "BETWEEN requires exactly two values for [{$criterion->alias}].",
                    'invalid_between_arity',
                    "filter.{$criterion->alias}.value",
                );
            }

            return new FilterCriterion($criterion->alias, $criterion->operator, $values);
        }

        if (is_array($criterion->value)) {
            throw new FilterableException(
                "Filter [{$criterion->alias}] requires one scalar value.",
                'invalid_filter_value_shape',
                "filter.{$criterion->alias}.value",
            );
        }

        return new FilterCriterion(
            $criterion->alias,
            $criterion->operator,
            $this->value($criterion->value, $definition, $schema),
        );
    }

    /**
     * Normalize a list or comma-separated string.
     *
     * @return list<mixed>
     */
    private function listValues(
        mixed $value,
        FilterDefinition $definition,
        FilterSchema $schema,
    ): array {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                throw new FilterableException(
                    "Filter [{$definition->alias}] values must be a list.",
                    'invalid_filter_value_shape',
                    "filter.{$definition->alias}.value",
                );
            }

            $values = $value;
        } elseif (is_string($value)) {
            $values = explode(',', $value);
        } else {
            throw new FilterableException(
                "Filter [{$definition->alias}] values must be a list or comma-separated string.",
                'invalid_filter_value_shape',
                "filter.{$definition->alias}.value",
            );
        }

        if ($values === []) {
            throw new FilterableException(
                "Filter [{$definition->alias}] requires at least one value.",
                'missing_filter_value',
                "filter.{$definition->alias}.value",
            );
        }

        if (count($values) > $schema->maximumValuesPerFilter) {
            throw new FilterableException(
                "Filter [{$definition->alias}] exceeds its value complexity limit.",
                'filter_value_complexity_exceeded',
                "filter.{$definition->alias}.value",
            );
        }

        return array_map(
            fn (mixed $item): mixed => $this->value($item, $definition, $schema),
            $values,
        );
    }

    /**
     * Normalize one value according to its declared type.
     */
    private function value(
        mixed $value,
        FilterDefinition $definition,
        FilterSchema $schema,
    ): mixed {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (is_string($value) && strlen($value) > $schema->maximumStringLength) {
            throw new FilterableException(
                "Filter [{$definition->alias}] exceeds the maximum string length.",
                'filter_string_too_long',
                "filter.{$definition->alias}.value",
            );
        }

        try {
            return match ($definition->type) {
                FilterValueType::Boolean => $this->boolean($value),
                FilterValueType::Integer => $this->integer($value),
                FilterValueType::Decimal => $this->decimal($value),
                FilterValueType::String => $this->string($value),
                FilterValueType::Enum => $this->enum($value, $definition),
                FilterValueType::Date => $this->date($value),
                FilterValueType::DateTime => $this->dateTime($value),
            };
        } catch (Throwable $throwable) {
            if ($throwable instanceof FilterableException) {
                throw new FilterableException(
                    $throwable->getMessage(),
                    $throwable->errorCode,
                    "filter.{$definition->alias}.value",
                    $throwable,
                );
            }

            throw new FilterableException(
                "Filter [{$definition->alias}] has an invalid value.",
                'invalid_filter_value',
                "filter.{$definition->alias}.value",
                $throwable,
            );
        }
    }

    /**
     * Normalize a deliberately small boolean vocabulary.
     */
    private function boolean(mixed $value): bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'true' => true,
            $value === false, $value === 0, $value === '0', $value === 'false' => false,
            default => throw new FilterableException(
                'Boolean filters accept only true, false, 1, and 0.',
                'invalid_boolean_filter_value',
            ),
        };
    }

    /**
     * Normalize a base-10 integer without coercion.
     */
    private function integer(mixed $value): int
    {
        if (
            (! is_int($value) && ! is_string($value))
            || (is_string($value) && ! preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value))
        ) {
            throw new FilterableException(
                'Integer filter value is invalid.',
                'invalid_integer_filter_value',
            );
        }

        $normalized = filter_var($value, FILTER_VALIDATE_INT);

        if ($normalized === false) {
            throw new FilterableException(
                'Integer filter value is invalid or outside the supported range.',
                'invalid_integer_filter_value',
            );
        }

        return $normalized;
    }

    /**
     * Normalize a fixed-point decimal without scientific notation.
     */
    private function decimal(mixed $value): string
    {
        if (
            (! is_int($value) && ! is_float($value) && ! is_string($value))
            || ! preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', (string) $value)
        ) {
            throw new FilterableException(
                'Decimal filter value is invalid.',
                'invalid_decimal_filter_value',
            );
        }

        return (string) $value;
    }

    /**
     * Normalize a non-empty string.
     */
    private function string(mixed $value): string
    {
        if (! is_string($value) && ! $value instanceof Stringable) {
            throw new FilterableException(
                'String filter value is invalid.',
                'invalid_string_filter_value',
            );
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            throw new FilterableException(
                'String filter value cannot be empty.',
                'empty_string_filter_value',
            );
        }

        return $normalized;
    }

    /**
     * Validate a declared enum value.
     */
    private function enum(mixed $value, FilterDefinition $definition): string
    {
        $normalized = $this->string($value);

        if (! in_array($normalized, $definition->enumValues, true)) {
            throw new FilterableException(
                "Enum filter [{$definition->alias}] has an invalid value.",
                'invalid_enum_filter_value',
                "filter.{$definition->alias}.value",
            );
        }

        return $normalized;
    }

    /**
     * Normalize an exact calendar date.
     */
    private function date(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new FilterableException(
                'Date filter value must use YYYY-MM-DD.',
                'invalid_date_filter_value',
            );
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new FilterableException(
                'Date filter value is not a valid calendar date.',
                'invalid_date_filter_value',
            );
        }

        return $value;
    }

    /**
     * Normalize an exact ISO-8601 instant to UTC.
     */
    private function dateTime(mixed $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (! is_string($value) || ! preg_match(
            '/^(?<date>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(?<fraction>\d{1,6}))?(?<zone>Z|[+-]\d{2}:\d{2})$/',
            $value,
            $matches,
        )) {
            throw new FilterableException(
                'Date-time filter value must be an ISO-8601 instant with a timezone.',
                'invalid_date_time_filter_value',
            );
        }

        $fraction = str_pad($matches['fraction'], 6, '0');
        $zone = $matches['zone'] === 'Z' ? '+00:00' : $matches['zone'];
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.uP',
            "{$matches['date']}.{$fraction}{$zone}",
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new FilterableException(
                'Date-time filter value is not a valid instant.',
                'invalid_date_time_filter_value',
            );
        }

        return CarbonImmutable::instance($date)->utc();
    }
}
