<?php

declare(strict_types=1);

namespace Nvl\Pages\Services;

use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Enums\PageStatus;

/**
 * Supplies the fixed allowlist used by page management queries.
 */
final class PageFilterSchema
{
    /**
     * Return the fixed management filter and sort allowlist.
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
                    enumValues: array_column(PageStatus::cases(), 'value'),
                ),
                new FilterDefinition(
                    alias: 'kind',
                    column: 'kind',
                    type: FilterValueType::Enum,
                    operators: [FilterOperator::Equals, FilterOperator::In],
                    enumValues: array_column(PageKind::cases(), 'value'),
                ),
                new FilterDefinition(
                    alias: 'parent',
                    column: 'parent_id',
                    operators: [FilterOperator::Equals, FilterOperator::IsNull],
                    nullable: true,
                ),
                new FilterDefinition(
                    alias: 'navigable',
                    column: 'is_navigable',
                    type: FilterValueType::Boolean,
                    operators: [FilterOperator::Equals],
                ),
            ],
            sorts: [
                new SortDefinition('position', 'position'),
                new SortDefinition('path', 'path'),
                new SortDefinition('created', 'created_at'),
                new SortDefinition('updated', 'updated_at'),
                new SortDefinition('id', 'id'),
            ],
            defaultSorts: ['position', 'path'],
            tieBreakerSort: 'id',
        );
    }
}
