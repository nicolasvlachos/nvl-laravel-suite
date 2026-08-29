<?php

declare(strict_types=1);

namespace Nvl\Translations\Traits;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Traits\Filterable;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Services\TranslationEntryFilterSchema;

/**
 * Query filters for translation entries.
 *
 * @mixin TranslationEntry
 */
trait TranslationEntryFilters
{
    /** @use Filterable<TranslationEntry> */
    use Filterable;

    /**
     * Declare translation workspace query aliases.
     */
    public function filterSchema(): FilterSchema
    {
        return (new TranslationEntryFilterSchema)->make();
    }

    /**
     * Search by key, group, scope, or value.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function filterSearch(Builder $query, mixed $value): Builder
    {
        return (new TranslationEntryFilterSchema)->filterSearch($query, $value);
    }

    /**
     * Filter rows by missing value state.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function filterMissingValue(Builder $query, mixed $value): Builder
    {
        return (new TranslationEntryFilterSchema)->filterMissingValue($query, $value);
    }

    /**
     * Filter rows with database values that are not synchronized to source.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function filterChangedSinceImport(Builder $query, mixed $value): Builder
    {
        return (new TranslationEntryFilterSchema)->filterChangedSinceImport($query, $value);
    }

    /**
     * Filter rows marked as missing in source files.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function filterIsMissing(Builder $query, mixed $value): Builder
    {
        return (new TranslationEntryFilterSchema)->filterIsMissing($query, $value);
    }
}
