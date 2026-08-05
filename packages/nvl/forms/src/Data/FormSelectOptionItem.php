<?php

declare(strict_types=1);

namespace Nvl\Forms\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Typed select option returned by form select endpoints.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class FormSelectOptionItem extends Data
{
    use DataTransform;

    /**
     * Create one form select option.
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $id,
        #[LiteralTypeScriptType('string')]
        public readonly string $label,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $sublabel,
    ) {}
}
