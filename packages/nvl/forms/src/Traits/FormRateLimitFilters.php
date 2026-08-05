<?php

declare(strict_types=1);

namespace Nvl\Forms\Traits;

use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Filterable\Traits\Filterable;
use Nvl\Forms\Models\FormRateLimit;

/**
 * Custom filter methods for the FormRateLimit model.
 */
trait FormRateLimitFilters
{
    /** @use Filterable<FormRateLimit> */
    use Filterable;

    /**
     * Declare rate-limit query aliases.
     */
    public function filterSchema(): FilterSchema
    {
        return new FilterSchema(
            filters: [
                new FilterDefinition('form_id', 'form_id'),
                new FilterDefinition('ip_address', 'ip_address'),
                new FilterDefinition('is_blocked', 'is_blocked', FilterValueType::Boolean),
                new FilterDefinition(
                    'created_at',
                    'created_at',
                    FilterValueType::DateTime,
                    [FilterOperator::Before, FilterOperator::After, FilterOperator::Between],
                ),
            ],
            sorts: array_map(
                static fn (string $column): SortDefinition => new SortDefinition($column, $column),
                ['ip_address', 'submission_count', 'violation_count', 'last_submission_at', 'created_at', 'id'],
            ),
            defaultSorts: ['-created_at'],
            tieBreakerSort: 'id',
        );
    }
}
