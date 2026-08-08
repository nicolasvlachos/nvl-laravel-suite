# NVL Filterable — API and usage

[← NVL Laravel Suite](../../../README.md)

## Purpose

`nvl/filterable` translates explicit typed filter sets into allowlisted Eloquent predicates and sorting on Laravel 12–13 and PHP 8.3–8.5. It is not a facets engine, full-text search engine, authorization layer, or arbitrary query language.

The package depends only on `nvl/data` inside the NVL family. It has no migrations, routes, configuration, or host-model assumptions.

## Requirements and installation

```bash
composer require nvl/laravel-suite:^1.0
php artisan vendor:publish --tag=filterable-skills
```

## Declare a schema

Every public alias maps to a known column, relation, value type, operator set, null rule, or custom handler:

```php
use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;

$schema = new FilterSchema(
    filters: [
        new FilterDefinition(
            alias: 'status',
            column: 'status',
            type: FilterValueType::Enum,
            operators: [FilterOperator::Equals, FilterOperator::In],
            enumValues: ['draft', 'published'],
        ),
        new FilterDefinition(
            alias: 'author',
            column: 'author.name',
            type: FilterValueType::String,
            operators: [FilterOperator::Contains],
        ),
        new FilterDefinition(
            alias: 'publishedAt',
            column: 'published_at',
            type: FilterValueType::DateTime,
            operators: [
                FilterOperator::Before,
                FilterOperator::After,
                FilterOperator::Between,
                FilterOperator::IsNull,
            ],
            nullable: true,
        ),
    ],
    sorts: [
        new SortDefinition('publishedAt', 'published_at'),
        new SortDefinition('title', 'title'),
        new SortDefinition('id', 'id'),
    ],
    defaultSorts: ['-publishedAt', 'title'],
    maximumFilters: 10,
    maximumSorts: 3,
    maximumValuesPerFilter: 50,
    maximumStringLength: 255,
    tieBreakerSort: 'id',
);
```

Aliases are the public contract; columns and relation paths remain internal. Duplicate aliases, invalid identifiers or defaults, incompatible operators, undeclared enum values, unsafe sort relations, invalid null operators, and excessive relation depth fail during schema construction.

## Build a transport-neutral filter set

Programmatic callers construct `FilterSet`, `FilterCriterion`, and `SortCriterion` directly. HTTP callers use the isolated adapter:

```php
use Nvl\Filterable\Http\QueryFilterSetFactory;
use Nvl\Filterable\Services\EloquentFilterApplier;

$filterSet = app(QueryFilterSetFactory::class)->fromHttpQuery(
    $request->query(),
    $schema,
);

$query = app(EloquentFilterApplier::class)->apply(
    Article::query(),
    $filterSet,
    $schema,
);

$articles = $query->paginate();
```

Expected HTTP shape:

```text
?filter[status][operator]=in&filter[status][value]=draft,published
&filter[author][operator]=contains&filter[author][value]=Ada
&sort=-publishedAt,title
```

The adapter rejects unknown aliases, malformed filter objects, unsupported operators, invalid sort values, and excessive filter count. It never silently forwards unknown input.

Scalar shorthand always means `equals`:

```text
?filter[status]=draft
```

Explicit objects contain exactly `operator` and, except for null checks, `value`. `is_null` and `is_not_null` must omit `value`:

```text
?filter[publishedAt][operator]=is_null
```

Use `fromQuery()` outside an HTTP boundary when the caller should receive `FilterableException` directly. `fromHttpQuery()` converts malformed input to Laravel's standard 422 validation response.

## Model trait

A model may use `Filterable` and return an immutable schema:

```php
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Traits\Filterable;

final class Article extends Model
{
    use Filterable;

    public function filterSchema(): FilterSchema
    {
        return ArticleFilters::schema();
    }
}

$articles = Article::query()
    ->applyFilterSet($filterSet)
    ->paginate();
```

The trait accepts a `FilterSet`; it does not inspect the Request facade.

## Types and operators

Supported value types are boolean, integer, decimal, string, enum, date, and date-time. Set and range behavior comes from `in`, `not_in`, and `between`.

Supported operators are `equals`, `not_equals`, `contains`, `not_contains`, `in`, `not_in`, `between`, `before`, `after`, `gt`, `lt`, `gte`, `lte`, `is_null`, and `is_not_null`. A definition must explicitly allow each operator, and schema construction rejects operators that do not apply to the definition's value type.

Boolean parsing accepts only `true`, `false`, `1`, and `0`. Integers use strict base-10 syntax. Decimals reject locale separators and scientific notation and remain strings to avoid binary floating-point surprises. Dates require exact `YYYY-MM-DD` calendar values. Date-times require ISO-8601 instants with an explicit timezone and normalize to UTC database timestamps. Empty strings are rejected. Null operators require `nullable=true`.

`in` and `not_in` accept a list or a comma-separated string and reject empty or oversized sets. `between` requires exactly two values. Custom handlers receive values after the same strict type normalization and own the complete predicate for their alias; handlers must remain bounded and parameterized.

`SortCriterion` uses the `SortDirection` enum. HTTP sorts use `sort=-publishedAt,title` or an alias-to-direction object. Duplicate and excessive sorts are rejected. When `tieBreakerSort` is declared, its ascending column is appended unless already present, which makes offset and cursor pagination deterministic.

## Database portability

`contains` and `not_contains` escape `%`, `_`, and the escape character, so user input always has literal substring semantics. Case, collation, and accent behavior follows the database column and driver. If an endpoint requires a different strategy, provide a custom handler and test it on every supported driver.

Never expose raw SQL, arbitrary JSON paths, request-provided relations, or request-provided columns.

## TypeScript

Filter DTOs and enums generate under `Nvl.Filterable.*`:

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
```

## Failure behavior

Contract and input violations throw `FilterableException` with stable `errorCode` and `path` context before unsafe identifiers reach Eloquent. Query execution errors remain database exceptions. Controllers should call `fromHttpQuery()` after authorization for Laravel's safe 422 validation response.

## Verification

Tests cover SQL injection payloads, aliases, relations, custom handlers, null and empty input, booleans, decimals, enum sets, ranges, dates and time zones, sorting, complexity limits, and SQLite/PostgreSQL/MySQL parity.

See [UPGRADING.md](UPGRADING.md), [SECURITY.md](SECURITY.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE).
