<?php

declare(strict_types=1);

namespace Nvl\Filterable\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Services\EloquentFilterApplier;
use Nvl\Filterable\Services\FilterCriterionNormalizer;

/**
 * Applies explicit filter sets using the model's immutable allowlist.
 *
 * @template TModel of Model
 */
trait Filterable
{
    /**
     * Declare public filter and sort aliases for this model.
     */
    abstract public function filterSchema(): FilterSchema;

    /**
     * Apply a transport-neutral filter set.
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeApplyFilterSet(Builder $query, FilterSet $filters): Builder
    {
        return (new EloquentFilterApplier(new FilterCriterionNormalizer))
            ->apply($query, $filters, $this->filterSchema());
    }
}
