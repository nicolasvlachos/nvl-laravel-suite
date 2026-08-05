<?php

declare(strict_types=1);

namespace Nvl\Seo\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Runtime redirect decision after chain flattening and expiry checks.
 */
#[TypeScript]
final class ResolvedRedirectData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $source,
        public readonly string $target,
        public readonly int $statusCode,
    ) {}
}
