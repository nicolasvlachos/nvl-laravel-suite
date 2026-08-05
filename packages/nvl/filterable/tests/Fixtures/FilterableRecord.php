<?php

declare(strict_types=1);

namespace Nvl\Filterable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Filterable\Exceptions\FilterableException;
use Nvl\Filterable\Traits\Filterable;

/**
 * Minimal model used to verify filtering against a real Eloquent query.
 */
final class FilterableRecord extends Model
{
    /** @use Filterable<self> */
    use Filterable;

    public $timestamps = false;

    protected $table = 'filterable_records';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'status',
        'price',
        'amount',
        'is_active',
        'note',
        'created_at',
        'occurred_at',
        'group_id',
    ];

    /**
     * Declare query aliases and their accepted values.
     */
    public function filterSchema(): FilterSchema
    {
        return new FilterSchema(
            filters: [
                new FilterDefinition(
                    'name',
                    'name',
                    operators: [
                        FilterOperator::Equals,
                        FilterOperator::NotEquals,
                        FilterOperator::Contains,
                        FilterOperator::NotContains,
                        FilterOperator::In,
                        FilterOperator::NotIn,
                    ],
                ),
                new FilterDefinition(
                    'status',
                    'status',
                    FilterValueType::Enum,
                    [FilterOperator::Equals, FilterOperator::In],
                    enumValues: ['active', 'draft'],
                ),
                new FilterDefinition(
                    'price',
                    'price',
                    FilterValueType::Integer,
                    [
                        FilterOperator::Equals,
                        FilterOperator::Between,
                        FilterOperator::Gt,
                        FilterOperator::Gte,
                        FilterOperator::Lt,
                        FilterOperator::Lte,
                        FilterOperator::In,
                        FilterOperator::NotIn,
                    ],
                ),
                new FilterDefinition(
                    'amount',
                    'amount',
                    FilterValueType::Decimal,
                    [FilterOperator::Equals, FilterOperator::Gte, FilterOperator::Lte],
                ),
                new FilterDefinition(
                    'active',
                    'is_active',
                    FilterValueType::Boolean,
                    [FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::In],
                ),
                new FilterDefinition(
                    'active_handler',
                    'is_active',
                    FilterValueType::Boolean,
                    handler: static function (Builder $query, FilterCriterion $criterion): Builder {
                        if (! is_bool($criterion->value)) {
                            throw new FilterableException('Custom handlers must receive normalized values.');
                        }

                        return $query->where('is_active', $criterion->value);
                    },
                ),
                new FilterDefinition(
                    'note',
                    'note',
                    operators: [
                        FilterOperator::Equals,
                        FilterOperator::Contains,
                        FilterOperator::IsNull,
                        FilterOperator::IsNotNull,
                    ],
                    nullable: true,
                ),
                new FilterDefinition(
                    'created',
                    'created_at',
                    FilterValueType::Date,
                    [FilterOperator::Equals, FilterOperator::Between, FilterOperator::Before, FilterOperator::After],
                ),
                new FilterDefinition(
                    'occurred',
                    'occurred_at',
                    FilterValueType::DateTime,
                    [FilterOperator::Equals, FilterOperator::Between, FilterOperator::Before, FilterOperator::After],
                ),
                new FilterDefinition(
                    'group',
                    'group.name',
                    operators: [FilterOperator::Equals, FilterOperator::Contains],
                ),
            ],
            sorts: [
                new SortDefinition('name', 'name'),
                new SortDefinition('price', 'price'),
                new SortDefinition('id', 'id'),
            ],
            defaultSorts: ['name'],
            maximumFilters: 10,
            maximumSorts: 3,
            maximumValuesPerFilter: 3,
            maximumStringLength: 32,
            tieBreakerSort: 'id',
        );
    }

    /**
     * Return the record's allowlisted relation fixture.
     *
     * @return BelongsTo<FilterableGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(FilterableGroup::class, 'group_id');
    }
}
