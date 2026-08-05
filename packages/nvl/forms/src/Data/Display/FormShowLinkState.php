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
 * Derived public/link payload for a form show page.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class FormShowLinkState extends Data
{
    use DataTransform;

    public function __construct(
        /**
         * @var string Public form URL
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $viewUrl,

        /**
         * @var string Iframe embed snippet
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $embedCode,
    ) {}
}
