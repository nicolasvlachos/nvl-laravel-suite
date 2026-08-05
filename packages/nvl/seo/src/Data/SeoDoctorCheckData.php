<?php

declare(strict_types=1);

namespace Nvl\Seo\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One immutable read-only SEO installation diagnostic.
 */
#[TypeScript]
final class SeoDoctorCheckData extends Data
{
    public function __construct(
        public readonly string $key,
        #[LiteralTypeScriptType("'error' | 'warning'")]
        public readonly string $severity,
        public readonly bool $passed,
        public readonly string $message,
    ) {}
}
