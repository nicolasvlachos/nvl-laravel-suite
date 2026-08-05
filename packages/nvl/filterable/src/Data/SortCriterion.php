<?php

declare(strict_types=1);

namespace Nvl\Filterable\Data;

use Nvl\Filterable\Enums\SortDirection;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One validated sort request.
 */
#[TypeScript]
final class SortCriterion extends Data
{
    /**
     * Create a sort criterion.
     */
    public function __construct(
        public readonly string $alias,
        public readonly SortDirection $direction = SortDirection::Asc,
    ) {}
}
