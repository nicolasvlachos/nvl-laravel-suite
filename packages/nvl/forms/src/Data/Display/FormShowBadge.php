<?php

declare(strict_types=1);

namespace Nvl\Forms\Data\Display;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Badge payload used by forms show-page display states.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class FormShowBadge extends Data
{
    use DataTransform;

    public function __construct(
        /**
         * @var string Display label
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $label,

        /**
         * @var string Badge variant token
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType("'secondary' | 'main' | 'destructive'")]
        public readonly string $variant,
    ) {}
}
