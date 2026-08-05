<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Source-controlled block definition.
 */
#[TypeScript]
final class ContentDefinitionData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $defaults
     * @param  list<string>  $allowedScopes
     * @param  list<string>  $allowedRegions
     * @param  array<string, mixed>  $jsonSchema
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $category,
        public readonly int $version,
        public readonly ?string $view,
        public readonly ContentSchemaData $schema,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $defaults = [],
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $allowedScopes = ['global'],
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $allowedRegions = ['main'],
        public readonly bool $isActive = true,
        public readonly int $sortOrder = 0,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $jsonSchema = [],
    ) {}
}
