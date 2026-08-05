<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Editor-facing contract for one reusable semantic field preset.
 */
#[TypeScript]
final class ContentFieldPresetData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $jsonSchema
     */
    public function __construct(
        public readonly string $alias,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ContentFieldDefinitionData $field,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $jsonSchema,
    ) {}
}
