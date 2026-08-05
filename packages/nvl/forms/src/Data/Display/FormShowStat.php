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
 * Single metric tile payload for form show-page stats.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class FormShowStat extends Data
{
    use DataTransform;

    public function __construct(
        /**
         * @var string Icon key used by the frontend icon map
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $icon,

        /**
         * @var string Stat label
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $label,

        /**
         * @var string Stat value
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $value,

        /**
         * @var string Stat helper description
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $description,
    ) {}
}
