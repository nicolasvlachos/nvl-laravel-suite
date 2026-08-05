<?php

declare(strict_types=1);

namespace Nvl\Forms\Traits;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Filterable\Exceptions\FilterableException;
use Nvl\Filterable\Traits\Filterable;
use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Models\Form;

/**
 * Custom filter methods for the Form model.
 */
trait FormFilters
{
    /** @use Filterable<Form> */
    use Filterable;

    /**
     * Declare form query aliases.
     */
    public function filterSchema(): FilterSchema
    {
        $dateOperators = [
            FilterOperator::Equals,
            FilterOperator::Before,
            FilterOperator::After,
            FilterOperator::Between,
            FilterOperator::IsNull,
        ];

        return new FilterSchema(
            filters: [
                new FilterDefinition(
                    'search',
                    'handle',
                    handler: fn (Builder $query, FilterCriterion $criterion): Builder => $this->filterSearch(
                        $query,
                        $criterion->value,
                    ),
                ),
                new FilterDefinition('handle', 'handle', operators: [FilterOperator::Equals, FilterOperator::Contains]),
                new FilterDefinition(
                    'status',
                    'status',
                    FilterValueType::Enum,
                    [FilterOperator::Equals, FilterOperator::In],
                    enumValues: array_column(FormStatus::cases(), 'value'),
                ),
                new FilterDefinition('restrict_public_access', 'restrict_public_access', FilterValueType::Boolean),
                new FilterDefinition(
                    'allow_multiple_registrations',
                    'allow_multiple_registrations',
                    FilterValueType::Boolean,
                ),
                new FilterDefinition('date_restricted', 'date_restricted', FilterValueType::Boolean),
                new FilterDefinition(
                    'available_from',
                    'available_from',
                    FilterValueType::DateTime,
                    $dateOperators,
                    nullable: true,
                ),
                new FilterDefinition(
                    'available_until',
                    'available_until',
                    FilterValueType::DateTime,
                    $dateOperators,
                    nullable: true,
                ),
                new FilterDefinition(
                    'availability',
                    'available_from',
                    FilterValueType::DateTime,
                    [FilterOperator::Equals, FilterOperator::Before, FilterOperator::After, FilterOperator::Between],
                    handler: fn (Builder $query, FilterCriterion $criterion): Builder => $this->filterAvailability(
                        $query,
                        $criterion->value,
                        $criterion->operator,
                    ),
                ),
                new FilterDefinition('enable_rate_limiting', 'enable_rate_limiting', FilterValueType::Boolean),
                new FilterDefinition('enable_honeypot', 'enable_honeypot', FilterValueType::Boolean),
                new FilterDefinition(
                    'created_at',
                    'created_at',
                    FilterValueType::DateTime,
                    [FilterOperator::Before, FilterOperator::After, FilterOperator::Between],
                ),
                new FilterDefinition(
                    'updated_at',
                    'updated_at',
                    FilterValueType::DateTime,
                    [FilterOperator::Before, FilterOperator::After, FilterOperator::Between],
                ),
                new FilterDefinition(
                    'last_used',
                    'last_used_at',
                    FilterValueType::Enum,
                    enumValues: ['today', 'this_week', 'this_month', 'never'],
                    handler: fn (Builder $query, FilterCriterion $criterion): Builder => $this->filterLastUsedAt(
                        $query,
                        $criterion->value,
                    ),
                ),
            ],
            sorts: array_map(
                static fn (string $column): SortDefinition => new SortDefinition($column, $column),
                [
                    'handle',
                    'status',
                    'submissions_count',
                    'views_count',
                    'spam_count',
                    'last_used_at',
                    'created_at',
                    'updated_at',
                    'id',
                ],
            ),
            defaultSorts: ['handle'],
            tieBreakerSort: 'id',
        );
    }

    /**
     * Filter by search term across multiple fields.
     *
     * @param  Builder<*>  $query  Query builder instance
     * @return Builder<*> Modified query builder
     */
    public function filterSearch(Builder $query, mixed $value): Builder
    {
        if (! is_string($value)) {
            return $query;
        }

        $searchTerm = '%'.mb_strtolower($value).'%';

        return $query->where(function (Builder $q) use ($searchTerm): void {
            $q->whereRaw('LOWER(handle) LIKE ?', [$searchTerm])
                ->orWhereHas('translations', function (Builder $translations) use ($searchTerm): void {
                    $translations->whereRaw('LOWER(name) LIKE ?', [$searchTerm]);
                });
        });
    }

    /**
     * Support semantic presets for last_used_at coming from FE select filter.
     * Accepts values: today | this_week | this_month | never | ''
     */
    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function filterLastUsedAt(Builder $query, mixed $value): Builder
    {
        if (! is_string($value)) {
            throw new FilterableException('The last-used filter must be a string.');
        }

        $val = trim(mb_strtolower($value));

        return match ($val) {
            'today' => $query->whereDate('last_used_at', now()->toDateString()),
            'this_week' => $query->whereDate('last_used_at', '>=', now()->startOfWeek()->toDateString()),
            'this_month' => $query->whereDate('last_used_at', '>=', now()->startOfMonth()->toDateString()),
            'never' => $query->whereNull('last_used_at'),
            default => throw new FilterableException('Unknown last-used filter value.'),
        };
    }

    /**
     * Filter by availability window using a single key `availability`.
     * Supports operators: between, before, after, equals
     * - between: forms overlapping with [from, to]
     * - before:  forms with available_until <= date OR unrestricted
     * - after:   forms with available_from >= date OR unrestricted
     * - equals:  forms available at the given date/time OR unrestricted
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function filterAvailability(
        Builder $query,
        mixed $val,
        FilterOperator $operator,
    ): Builder {
        $from = null;
        $to = null;
        if (is_array($val)) {
            $fromValue = $val[0] ?? null;
            $toValue = $val[1] ?? null;
            $from = is_string($fromValue) || $fromValue instanceof DateTimeInterface ? $fromValue : null;
            $to = is_string($toValue) || $toValue instanceof DateTimeInterface ? $toValue : null;
        } elseif (is_string($val) || $val instanceof DateTimeInterface) {
            if ($val instanceof DateTimeInterface) {
                $from = $val;
                $to = null;
            } else {
                $parts = explode(',', $val, 2);
                $from = $parts[0];
                $to = $parts[1] ?? null;
            }
        } else {
            throw new FilterableException('The availability filter must be a date or date range.');
        }

        return match ($operator) {
            FilterOperator::Between => $query->where(function (Builder $q) use ($from, $to): void {
                $q->whereRaw('date_restricted = ?', [false])
                    ->orWhere(function (Builder $w) use ($from, $to): void {
                        if ($from !== null && $from !== '') {
                            $w->where(function (Builder $ww) use ($from): void {
                                $ww->whereNull('available_until')
                                    ->orWhereRaw('available_until >= ?', [$from]);
                            });
                        }
                        if ($to !== null && $to !== '') {
                            $w->where(function (Builder $ww) use ($to): void {
                                $ww->whereNull('available_from')
                                    ->orWhereRaw('available_from <= ?', [$to]);
                            });
                        }
                    });
            }),
            FilterOperator::Before => $query->where(function (Builder $q) use ($from): void {
                $q->whereRaw('date_restricted = ?', [false])
                    ->orWhere(function (Builder $w) use ($from): void {
                        if ($from !== null && $from !== '') {
                            $w->whereNotNull('available_until')->whereRaw('available_until <= ?', [$from]);
                        }
                    });
            }),
            FilterOperator::After => $query->where(function (Builder $q) use ($from): void {
                $q->whereRaw('date_restricted = ?', [false])
                    ->orWhere(function (Builder $w) use ($from): void {
                        if ($from !== null && $from !== '') {
                            $w->whereNotNull('available_from')->whereRaw('available_from >= ?', [$from]);
                        }
                    });
            }),
            FilterOperator::Equals => $query->where(function (Builder $q) use ($from): void {
                $q->whereRaw('date_restricted = ?', [false])
                    ->orWhere(function (Builder $w) use ($from): void {
                        if ($from !== null && $from !== '') {
                            $w->where(function (Builder $ww) use ($from): void {
                                $ww->whereNull('available_from')->orWhereRaw('available_from <= ?', [$from]);
                            })->where(function (Builder $ww) use ($from): void {
                                $ww->whereNull('available_until')->orWhereRaw('available_until >= ?', [$from]);
                            });
                        }
                    });
            }),
            default => throw new FilterableException('Unsupported availability operator.'),
        };
    }
}
