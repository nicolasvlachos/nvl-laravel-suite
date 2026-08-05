<?php

declare(strict_types=1);

namespace Nvl\Seo\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Bounded aggregate profile status for management dashboards and imports.
 */
#[TypeScript]
final class SeoProfileStatusData extends Data
{
    /**
     * Create one aggregate profile status.
     */
    public function __construct(
        public readonly int $active,
        public readonly int $archived,
        public readonly int $total,
    ) {}
}
