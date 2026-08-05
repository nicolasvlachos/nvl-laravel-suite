<?php

declare(strict_types=1);

namespace Nvl\Filterable\Data;

use Nvl\Filterable\Enums\FilterOperator;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One validated filter request.
 */
#[TypeScript]
final class FilterCriterion extends Data
{
    /**
     * Create a filter criterion.
     */
    public function __construct(
        public readonly string $alias,
        public readonly FilterOperator $operator,
        #[LiteralTypeScriptType('string | number | boolean | null | Array<string | number | boolean | null>')]
        public readonly mixed $value = null,
    ) {}
}
