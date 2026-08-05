<?php

declare(strict_types=1);

namespace Nvl\Filterable\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Transport-neutral filters and sorts for one query.
 */
#[TypeScript]
final class FilterSet extends Data
{
    /**
     * Create a typed filter set.
     *
     * @param  list<FilterCriterion>  $filters
     * @param  list<SortCriterion>  $sorts
     */
    public function __construct(
        public readonly array $filters = [],
        public readonly array $sorts = [],
    ) {}

    /**
     * Create an empty filter set.
     */
    public static function none(): self
    {
        return new self;
    }
}
