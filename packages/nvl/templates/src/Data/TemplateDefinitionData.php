<?php

declare(strict_types=1);

namespace Nvl\Templates\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Source-controlled executable definition for one template key.
 */
#[TypeScript]
final class TemplateDefinitionData extends Data
{
    use DataTransform;

    /**
     * @param  list<string>  $profiles
     * @param  list<string>  $requiredRegions
     * @param  list<string>  $allowedContentDefinitions
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $rendererOptions
     */
    public function __construct(
        public readonly string $key,
        public readonly string $renderer,
        public readonly string $view,
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $profiles = ['default'],
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $schema = [],
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $rendererOptions = [],
        public readonly ?string $subjectPath = null,
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $requiredRegions = [],
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $allowedContentDefinitions = [],
    ) {}
}
