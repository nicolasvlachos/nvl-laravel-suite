<?php

declare(strict_types=1);

namespace Nvl\Forms\Data\Display;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * JSON-safe validation metadata for one public submission field.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class PublicSubmissionFieldPayload extends Data
{
    use DataTransform;

    /**
     * @param  string  $key  Submission field key
     * @param  bool  $required  Whether the field is required
     * @param  list<string>  $rules  Normalized validation rules
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $key,

        #[LiteralTypeScriptType('boolean')]
        public readonly bool $required,

        /** @var list<string> */
        #[LiteralTypeScriptType('string[]')]
        public readonly array $rules,
    ) {}
}
