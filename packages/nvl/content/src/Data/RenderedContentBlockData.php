<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Public-safe renderer-neutral block tree node.
 */
#[TypeScript]
final class RenderedContentBlockData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $fieldTypes
     * @param  list<RenderedContentBlockData>  $children
     */
    public function __construct(
        public readonly string $id,
        public readonly string $placementId,
        public readonly string $definitionKey,
        public readonly ?string $key,
        public readonly string $region,
        public readonly int $sortOrder,
        public readonly string $view,
        public readonly string $locale,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $values,
        #[LiteralTypeScriptType('Record<string, string>')]
        public readonly array $fieldTypes,
        #[DataCollectionOf(self::class)]
        public readonly array $children = [],
    ) {}
}
