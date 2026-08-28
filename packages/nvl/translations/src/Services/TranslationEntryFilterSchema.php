<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Translations\Enums\TranslationSyncStatus;
use Nvl\Translations\Exceptions\TranslationsException;

/**
 * Canonical transport-neutral filters and sorts for translation catalog reads.
 */
final class TranslationEntryFilterSchema
{
    /**
     * Build the translation entry filter schema.
     */
    public function make(): FilterSchema
    {
        $equalsOrSet = [FilterOperator::Equals, FilterOperator::In];

        return new FilterSchema(
            filters: [
                new FilterDefinition(
                    'search',
                    'key',
                    handler: fn (Builder $query, FilterCriterion $criterion): Builder => $this->filterSearch(
                        $query,
                        $criterion->value,
                    ),
                ),
                new FilterDefinition('scope_type', 'scope_type', operators: $equalsOrSet),
                new FilterDefinition('scope_name', 'scope_name', operators: $equalsOrSet),
                new FilterDefinition('locale', 'locale', operators: $equalsOrSet),
                new FilterDefinition('format', 'format', operators: $equalsOrSet),
                new FilterDefinition('group', 'group', operators: $equalsOrSet),
                new FilterDefinition('key', 'key', operators: [FilterOperator::Equals, FilterOperator::Contains]),
                new FilterDefinition(
                    'missing_value',
                    'value',
                    FilterValueType::Boolean,
                    handler: fn (Builder $query, FilterCriterion $criterion): Builder => $this->filterMissingValue(
                        $query,
                        $criterion->value,
                    ),
                ),
                new FilterDefinition(
                    'changed_since_import',
                    'updated_at',
                    FilterValueType::Boolean,
                    handler: fn (Builder $query, FilterCriterion $criterion): Builder => $this->filterChangedSinceImport(
                        $query,
                        $criterion->value,
                    ),
                ),
                new FilterDefinition(
                    'is_missing',
                    'is_missing',
                    FilterValueType::Boolean,
                    handler: fn (Builder $query, FilterCriterion $criterion): Builder => $this->filterIsMissing(
                        $query,
                        $criterion->value,
                    ),
                ),
            ],
            sorts: array_map(
                static fn (string $column): SortDefinition => new SortDefinition($column, $column),
                [
                    'scope_type',
                    'scope_name',
                    'locale',
                    'format',
                    'group',
                    'key',
                    'updated_at',
                    'last_imported_at',
                    'id',
                ],
            ),
            defaultSorts: ['-updated_at'],
            tieBreakerSort: 'id',
        );
    }

    /**
     * Search by key, group, scope, or value.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function filterSearch(Builder $query, mixed $value): Builder
    {
        $search = is_string($value) ? mb_strtolower(trim($value)) : '';
        if ($search === '') {
            return $query;
        }

        $grammar = $query->getQuery()->getGrammar();
        $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        return $query->where(static function (Builder $builder) use ($grammar, $needle): void {
            foreach (['scope_type', 'scope_name', 'locale', 'format', 'group', 'key', 'value'] as $index => $column) {
                $wrapped = $grammar->wrap($column);
                self::ensureSafeWrappedColumn($wrapped);
                $expression = "LOWER(COALESCE({$wrapped}, '')) LIKE ? ESCAPE '!'";

                if ($index === 0) {
                    $builder->whereRaw($expression, [$needle]);
                } else {
                    $builder->orWhereRaw($expression, [$needle]);
                }
            }
        });
    }

    /**
     * Filter rows by missing value state.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function filterMissingValue(Builder $query, mixed $value): Builder
    {
        $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return $query;
        }

        if ($enabled) {
            return $query->where(static function (Builder $builder): void {
                $builder->whereNull('value')->orWhere('value', '');
            });
        }

        return $query->whereNotNull('value')->where('value', '!=', '');
    }

    /**
     * Filter rows with database values that are not synchronized to source.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function filterChangedSinceImport(Builder $query, mixed $value): Builder
    {
        $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return $query;
        }

        $changedStatuses = [
            TranslationSyncStatus::Edited->value,
            TranslationSyncStatus::Conflict->value,
        ];

        if ($enabled) {
            return $query->whereIn('sync_status', $changedStatuses);
        }

        return $query->whereNotIn('sync_status', $changedStatuses);
    }

    /**
     * Filter rows marked as missing in source files.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function filterIsMissing(Builder $query, mixed $value): Builder
    {
        $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return $query;
        }

        return $query->where('is_missing', $enabled);
    }

    /**
     * Prove that a database grammar returned only a quoted, allowlisted identifier.
     *
     * @phpstan-assert literal-string $column
     */
    private static function ensureSafeWrappedColumn(string $column): void
    {
        if (preg_match('/\\A(?:[`"]?[a-z_]+[`"]?|\\[[a-z_]+\\])\\z/', $column) !== 1) {
            throw new TranslationsException('The database grammar returned an unsafe column identifier.');
        }
    }
}
