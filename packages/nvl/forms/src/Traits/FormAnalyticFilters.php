<?php

declare(strict_types=1);

namespace Nvl\Forms\Traits;

use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Filterable\Traits\Filterable;
use Nvl\Forms\Models\FormAnalytic;

/**
 * Custom filter methods for the FormAnalytic model.
 */
trait FormAnalyticFilters
{
    /** @use Filterable<FormAnalytic> */
    use Filterable;

    /**
     * Declare analytics query aliases.
     */
    public function filterSchema(): FilterSchema
    {
        return new FilterSchema(
            filters: [
                new FilterDefinition('form_id', 'form_id'),
                new FilterDefinition('event_type', 'event_type', operators: [FilterOperator::Equals, FilterOperator::In]),
                new FilterDefinition('origin', 'origin', operators: [FilterOperator::Equals, FilterOperator::Contains]),
                new FilterDefinition('ip_address', 'ip_address'),
                new FilterDefinition(
                    'created_at',
                    'created_at',
                    FilterValueType::DateTime,
                    [FilterOperator::Before, FilterOperator::After, FilterOperator::Between],
                ),
            ],
            sorts: [
                new SortDefinition('event_type', 'event_type'),
                new SortDefinition('origin', 'origin'),
                new SortDefinition('created_at', 'created_at'),
                new SortDefinition('id', 'id'),
            ],
            defaultSorts: ['-created_at'],
            tieBreakerSort: 'id',
        );
    }
}
