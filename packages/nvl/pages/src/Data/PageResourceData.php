<?php

declare(strict_types=1);

namespace Nvl\Pages\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Sanitized transport projection returned by a dynamic resource handler.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class PageResourceData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $payload,
    ) {}
}
