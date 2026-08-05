<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Templates\Enums\TemplateRenderStatus;

/**
 * Supplies the bounded query contract for durable template render history.
 */
final class TemplateRenderFilterSchema
{
    /**
     * Build the allowlisted render-history filter and sort schema.
     */
    public function make(): FilterSchema
    {
        return new FilterSchema(
            filters: [
                new FilterDefinition(
                    alias: 'status',
                    column: 'status',
                    type: FilterValueType::Enum,
                    operators: [FilterOperator::Equals, FilterOperator::In],
                    enumValues: array_column(TemplateRenderStatus::cases(), 'value'),
                ),
                new FilterDefinition(
                    alias: 'template',
                    column: 'template_id',
                    operators: [FilterOperator::Equals, FilterOperator::In],
                ),
            ],
            sorts: [
                new SortDefinition('created', 'created_at'),
                new SortDefinition('started', 'started_at'),
                new SortDefinition('completed', 'completed_at'),
                new SortDefinition('id', 'id'),
            ],
            defaultSorts: ['-created'],
            tieBreakerSort: 'id',
        );
    }
}
