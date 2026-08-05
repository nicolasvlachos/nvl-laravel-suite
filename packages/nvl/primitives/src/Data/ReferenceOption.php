<?php

declare(strict_types=1);

namespace Nvl\Primitives\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Framework-neutral option returned by ISO and configured reference catalogs.
 */
#[TypeScript]
final class ReferenceOption extends Data
{
    use DataTransform;

    /**
     * Create a normalized reference option.
     *
     * @param  array<string, bool|int|float|string|null>  $metadata
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        #[LiteralTypeScriptType('Record<string, boolean | number | string | null>')]
        public readonly array $metadata = [],
    ) {}
}
