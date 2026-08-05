<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Data\SortCriterion;
use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Filterable\Enums\SortDirection;
use Nvl\Filterable\Exceptions\FilterableException;
use Nvl\Filterable\Http\QueryFilterSetFactory;
use Nvl\Filterable\Services\EloquentFilterApplier;
use Nvl\Filterable\Tests\Fixtures\FilterableGroup;
use Nvl\Filterable\Tests\Fixtures\FilterableRecord;

beforeEach(function (): void {
    Schema::dropIfExists('filterable_records');
    Schema::dropIfExists('filterable_groups');

    Schema::create('filterable_groups', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Schema::create('filterable_records', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('status');
        $table->integer('price');
        $table->decimal('amount', 10, 2);
        $table->boolean('is_active');
        $table->string('note')->nullable();
        $table->date('created_at');
        $table->dateTime('occurred_at');
        $table->unsignedBigInteger('group_id');
    });

    FilterableGroup::query()->insert([
        ['id' => 1, 'name' => 'Editorial'],
        ['id' => 2, 'name' => 'Sales'],
    ]);

    FilterableRecord::query()->insert([
        [
            'id' => 1,
            'name' => 'Alpha',
            'status' => 'active',
            'price' => 10,
            'amount' => '10.50',
            'is_active' => true,
            'note' => '100% ready',
            'created_at' => '2026-01-01',
            'occurred_at' => '2026-01-01 10:00:00',
            'group_id' => 1,
        ],
        [
            'id' => 2,
            'name' => 'Beta',
            'status' => 'draft',
            'price' => 20,
            'amount' => '20.00',
            'is_active' => false,
            'note' => null,
            'created_at' => '2026-01-02',
            'occurred_at' => '2026-01-02 12:00:00',
            'group_id' => 2,
        ],
        [
            'id' => 3,
            'name' => 'Gamma',
            'status' => 'active',
            'price' => 30,
            'amount' => '30.75',
            'is_active' => true,
            'note' => 'under_score',
            'created_at' => '2026-01-03',
            'occurred_at' => '2026-01-03 14:00:00',
            'group_id' => 1,
        ],
    ]);
});

afterEach(function (): void {
    Schema::dropIfExists('filterable_records');
    Schema::dropIfExists('filterable_groups');
});

it('applies typed allowlisted filters and enum-backed sorts', function (): void {
    $set = new FilterSet(
        filters: [
            new FilterCriterion('status', FilterOperator::Equals, 'active'),
            new FilterCriterion('price', FilterOperator::Gte, '15'),
        ],
        sorts: [new SortCriterion('price', SortDirection::Desc)],
    );

    $names = FilterableRecord::query()
        ->applyFilterSet($set)
        ->pluck('name')
        ->all();

    expect($names)->toBe(['Gamma']);
});

it('applies explicit negative equality and set operators', function (): void {
    $notDraft = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('status', FilterOperator::Equals, 'active'),
            new FilterCriterion('price', FilterOperator::NotIn, ['10', '20']),
        ]))
        ->pluck('name')
        ->all();

    $notAlpha = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('name', FilterOperator::NotEquals, 'Alpha'),
        ]))
        ->pluck('name')
        ->all();

    $included = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('price', FilterOperator::In, '10,30'),
        ]))
        ->pluck('name')
        ->all();

    expect($notDraft)->toBe(['Gamma'])
        ->and($notAlpha)->toBe(['Beta', 'Gamma'])
        ->and($included)->toBe(['Alpha', 'Gamma']);
});

it('treats contains wildcards as literal characters', function (): void {
    $percent = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('note', FilterOperator::Contains, '%'),
        ]))
        ->pluck('name')
        ->all();

    $underscore = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('note', FilterOperator::Contains, '_'),
        ]))
        ->pluck('name')
        ->all();

    $notPercent = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('name', FilterOperator::NotContains, 'Alpha'),
        ]))
        ->pluck('name')
        ->all();

    expect($percent)->toBe(['Alpha'])
        ->and($underscore)->toBe(['Gamma'])
        ->and($notPercent)->toBe(['Beta', 'Gamma']);
});

it('normalizes strict scalar values before applying queries and custom handlers', function (): void {
    $booleans = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('active', FilterOperator::Equals, 'false'),
        ]))
        ->pluck('name')
        ->all();

    $custom = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('active_handler', FilterOperator::Equals, '1'),
        ]))
        ->pluck('name')
        ->all();

    $decimal = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('amount', FilterOperator::Gte, '20.00'),
        ]))
        ->pluck('name')
        ->all();

    expect($booleans)->toBe(['Beta'])
        ->and($custom)->toBe(['Alpha', 'Gamma'])
        ->and($decimal)->toBe(['Beta', 'Gamma']);
});

it('rejects permissive boolean, numeric, empty, and oversized values', function (
    string $alias,
    mixed $value,
    string $message,
): void {
    expect(fn () => FilterableRecord::query()->applyFilterSet(new FilterSet([
        new FilterCriterion($alias, FilterOperator::Equals, $value),
    ]))->get())->toThrow(FilterableException::class, $message);
})->with([
    'boolean word' => ['active', 'off', 'Boolean filters accept only'],
    'boolean null' => ['active', null, 'Boolean filters accept only'],
    'scientific decimal' => ['amount', '1e2', 'Decimal filter value is invalid'],
    'leading-zero integer' => ['price', '01', 'Integer filter value is invalid'],
    'empty string' => ['name', '   ', 'cannot be empty'],
    'oversized string' => ['name', str_repeat('a', 33), 'maximum string length'],
]);

it('normalizes exact dates, ranges, and timezone-aware instants', function (): void {
    $range = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('created', FilterOperator::Between, ['2026-01-02', '2026-01-03']),
        ]))
        ->pluck('name')
        ->all();

    $instant = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('occurred', FilterOperator::Equals, '2026-01-01T12:00:00+02:00'),
        ]))
        ->pluck('name')
        ->all();

    expect($range)->toBe(['Beta', 'Gamma'])
        ->and($instant)->toBe(['Alpha']);
});

it('rejects relative, rollover, timezone-less, and malformed ranges', function (
    string $alias,
    FilterOperator $operator,
    mixed $value,
    string $message,
): void {
    expect(fn () => FilterableRecord::query()->applyFilterSet(new FilterSet([
        new FilterCriterion($alias, $operator, $value),
    ]))->get())->toThrow(FilterableException::class, $message);
})->with([
    'relative date' => ['created', FilterOperator::Equals, 'tomorrow', 'must use YYYY-MM-DD'],
    'rollover date' => ['created', FilterOperator::Equals, '2026-02-30', 'valid calendar date'],
    'timezone-less instant' => ['occurred', FilterOperator::Equals, '2026-01-01 10:00:00', 'ISO-8601'],
    'rollover instant' => ['occurred', FilterOperator::Equals, '2026-02-30T10:00:00Z', 'valid instant'],
    'short range' => ['price', FilterOperator::Between, ['10'], 'exactly two values'],
    'long range' => ['price', FilterOperator::Between, ['10', '20', '30'], 'exactly two values'],
]);

it('applies null checks without accepting dummy values', function (): void {
    $null = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('note', FilterOperator::IsNull),
        ]))
        ->pluck('name')
        ->all();

    $notNull = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('note', FilterOperator::IsNotNull),
        ]))
        ->pluck('name')
        ->all();

    expect($null)->toBe(['Beta'])
        ->and($notNull)->toBe(['Alpha', 'Gamma'])
        ->and(fn () => FilterableRecord::query()->applyFilterSet(new FilterSet([
            new FilterCriterion('note', FilterOperator::IsNull, 'ignored'),
        ]))->get())->toThrow(FilterableException::class, 'must not contain a value');
});

it('applies comparison operator aliases', function (
    FilterOperator $operator,
    string $value,
    array $expected,
): void {
    $names = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('price', $operator, $value),
        ]))
        ->pluck('name')
        ->all();

    expect($names)->toBe($expected);
})->with([
    'greater than' => [FilterOperator::Gt, '20', ['Gamma']],
    'less than' => [FilterOperator::Lt, '20', ['Alpha']],
    'less than or equal' => [FilterOperator::Lte, '20', ['Alpha', 'Beta']],
]);

it('applies allowlisted nested relation filters', function (): void {
    $names = FilterableRecord::query()
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('group', FilterOperator::Contains, 'ditor'),
        ]))
        ->pluck('name')
        ->all();

    expect($names)->toBe(['Alpha', 'Gamma']);
});

it('fails closed for unknown aliases and unsupported operators', function (): void {
    expect(fn () => FilterableRecord::query()->applyFilterSet(new FilterSet([
        new FilterCriterion('private_column', FilterOperator::Equals, 'secret'),
    ]))->get())->toThrow(FilterableException::class, 'Unknown filter alias');

    expect(fn () => FilterableRecord::query()->applyFilterSet(new FilterSet([
        new FilterCriterion('status', FilterOperator::Contains, 'active'),
    ]))->get())->toThrow(FilterableException::class, 'is not allowed');
});

it('parses scalar shorthand, exact filter objects, null operators, and sort syntax', function (): void {
    $factory = app(QueryFilterSetFactory::class);
    $schema = (new FilterableRecord)->filterSchema();
    $set = $factory->fromQuery([
        'filter' => [
            'name' => 'Alpha',
            'status' => ['operator' => 'in', 'value' => ['active']],
            'note' => ['operator' => 'is_null'],
        ],
        'sort' => '-price,name',
    ], $schema);

    expect($set->filters)->toHaveCount(3)
        ->and($set->filters[0]->operator)->toBe(FilterOperator::Equals)
        ->and($set->filters[2]->operator)->toBe(FilterOperator::IsNull)
        ->and($set->filters[2]->value)->toBeNull()
        ->and($set->sorts[0]->direction)->toBe(SortDirection::Desc)
        ->and($set->sorts[1]->direction)->toBe(SortDirection::Asc);
});

it('rejects malformed filter objects at the adapter boundary', function (
    array $payload,
    string $message,
): void {
    $factory = app(QueryFilterSetFactory::class);
    $schema = (new FilterableRecord)->filterSchema();

    expect(fn () => $factory->fromQuery(['filter' => ['note' => $payload]], $schema))
        ->toThrow(FilterableException::class, $message);
})->with([
    'extra key' => [['operator' => 'equals', 'value' => 'x', 'column' => 'password'], 'must contain only'],
    'missing operator' => [['value' => 'x'], 'must contain only'],
    'missing value' => [['operator' => 'equals'], 'requires a value'],
    'dummy null value' => [['operator' => 'is_null', 'value' => null], 'must not contain a value'],
]);

it('rejects unknown or disallowed HTTP operators before query application', function (): void {
    $factory = app(QueryFilterSetFactory::class);
    $schema = (new FilterableRecord)->filterSchema();

    expect(fn () => $factory->fromQuery([
        'filter' => ['status' => ['operator' => 'contains', 'value' => 'active']],
    ], $schema))->toThrow(FilterableException::class, 'is not allowed');

    expect(fn () => $factory->fromQuery([
        'filter' => ['status' => ['operator' => 'drop', 'value' => 'active']],
    ], $schema))->toThrow(FilterableException::class, 'Unsupported filter operator');
});

it('maps malformed HTTP filters and values to Laravel validation exceptions', function (
    array $query,
    string $path,
): void {
    $factory = app(QueryFilterSetFactory::class);
    $schema = (new FilterableRecord)->filterSchema();

    try {
        $factory->fromHttpQuery($query, $schema);
    } catch (ValidationException $exception) {
        expect($exception->status)->toBe(422)
            ->and($exception->errors())->toHaveKey($path);

        return;
    }

    $this->fail('A malformed HTTP filter did not produce a validation exception.');
})->with([
    'unknown alias' => [
        ['filter' => ['private' => 'value']],
        'filter.private',
    ],
    'invalid normalized value' => [
        ['filter' => ['active' => 'off']],
        'filter.active.value',
    ],
]);

it('enforces filter, sort, list-value, and string complexity limits', function (): void {
    $factory = app(QueryFilterSetFactory::class);
    $applier = app(EloquentFilterApplier::class);
    $schema = new FilterSchema(
        filters: [
            new FilterDefinition('name', 'name', operators: [FilterOperator::Equals, FilterOperator::In]),
            new FilterDefinition('status', 'status'),
        ],
        sorts: [
            new SortDefinition('name', 'name'),
            new SortDefinition('id', 'id'),
        ],
        maximumFilters: 1,
        maximumSorts: 1,
        maximumValuesPerFilter: 2,
        maximumStringLength: 4,
    );

    expect(fn () => $factory->fromQuery([
        'filter' => ['name' => 'a', 'status' => 'b'],
    ], $schema))->toThrow(FilterableException::class, 'filter complexity');

    expect(fn () => $factory->fromQuery([
        'sort' => 'name,id',
    ], $schema))->toThrow(FilterableException::class, 'sort complexity');

    expect(fn () => $applier->apply(FilterableRecord::query(), new FilterSet([
        new FilterCriterion('name', FilterOperator::In, ['a', 'b', 'c']),
    ]), $schema)->get())->toThrow(FilterableException::class, 'value complexity');

    expect(fn () => $applier->apply(FilterableRecord::query(), new FilterSet([
        new FilterCriterion('name', FilterOperator::Equals, 'abcde'),
    ]), $schema)->get())->toThrow(FilterableException::class, 'maximum string length');
});

it('rejects duplicate HTTP and programmatic sorts', function (): void {
    $factory = app(QueryFilterSetFactory::class);
    $schema = (new FilterableRecord)->filterSchema();

    expect(fn () => $factory->fromQuery(['sort' => 'name,-name'], $schema))
        ->toThrow(FilterableException::class, 'Duplicate sort');

    expect(fn () => FilterableRecord::query()->applyFilterSet(new FilterSet(
        sorts: [
            new SortCriterion('name'),
            new SortCriterion('name', SortDirection::Desc),
        ],
    ))->get())->toThrow(FilterableException::class, 'Duplicate sort');
});

it('appends a stable tie-breaker to default and explicit sorts', function (): void {
    $defaultQuery = FilterableRecord::query()->applyFilterSet(FilterSet::none());
    $explicitQuery = FilterableRecord::query()->applyFilterSet(new FilterSet(
        sorts: [new SortCriterion('price', SortDirection::Desc)],
    ));

    expect($defaultQuery->getQuery()->orders)->toBe([
        ['column' => 'name', 'direction' => 'asc'],
        ['column' => 'id', 'direction' => 'asc'],
    ])->and($explicitQuery->getQuery()->orders)->toBe([
        ['column' => 'price', 'direction' => 'desc'],
        ['column' => 'id', 'direction' => 'asc'],
    ]);
});

it('validates schema identifiers, compatibility, nullability, defaults, and tie-breakers', function (
    Closure $definition,
    string $message,
): void {
    expect($definition)->toThrow(FilterableException::class, $message);
})->with([
    'filter alias' => [
        fn (): FilterDefinition => new FilterDefinition('bad alias', 'name'),
        'alias',
    ],
    'relation depth' => [
        fn (): FilterDefinition => new FilterDefinition('name', 'one.two.three.four'),
        'relation path',
    ],
    'unsafe column' => [
        fn (): FilterDefinition => new FilterDefinition('name', 'name;drop'),
        'relation path',
    ],
    'sort relation' => [
        fn (): SortDefinition => new SortDefinition('author', 'author.name'),
        'invalid column',
    ],
    'incompatible operator' => [
        fn (): FilterDefinition => new FilterDefinition(
            'active',
            'active',
            FilterValueType::Boolean,
            [FilterOperator::Contains],
        ),
        'incompatible',
    ],
    'non-null null operator' => [
        fn (): FilterDefinition => new FilterDefinition(
            'name',
            'name',
            operators: [FilterOperator::IsNull],
        ),
        'must be nullable',
    ],
    'unknown default' => [
        fn (): FilterSchema => new FilterSchema(
            filters: [],
            sorts: [new SortDefinition('name', 'name')],
            defaultSorts: ['private'],
        ),
        'not declared',
    ],
    'unknown tie breaker' => [
        fn (): FilterSchema => new FilterSchema(
            filters: [],
            sorts: [new SortDefinition('name', 'name')],
            tieBreakerSort: 'id',
        ),
        'Tie-breaker sort',
    ],
]);

it('exposes stable machine-readable exception context', function (): void {
    $exception = new FilterableException(
        'Invalid input.',
        'invalid_example',
        'filter.example.value',
    );

    expect($exception->errorCode)->toBe('invalid_example')
        ->and($exception->path)->toBe('filter.example.value');
});
