<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Templates\Enums\TemplateStatus;

/**
 * Supplies the single allowlisted template-management query schema.
 */
final class TemplateFilterSchema
{
    public function make(): FilterSchema
    {
        return new FilterSchema(
            filters: [
                new FilterDefinition(
                    alias: 'status',
                    column: 'status',
                    type: FilterValueType::Enum,
                    operators: [FilterOperator::Equals, FilterOperator::In],
                    enumValues: array_column(TemplateStatus::cases(), 'value'),
                ),
                new FilterDefinition(
                    alias: 'renderer',
                    column: 'renderer',
                    operators: [FilterOperator::Equals, FilterOperator::In],
                ),
            ],
            sorts: [
                new SortDefinition('key', 'key'),
                new SortDefinition('created', 'created_at'),
                new SortDefinition('updated', 'updated_at'),
                new SortDefinition('id', 'id'),
            ],
            defaultSorts: ['key'],
            tieBreakerSort: 'id',
        );
    }
}
