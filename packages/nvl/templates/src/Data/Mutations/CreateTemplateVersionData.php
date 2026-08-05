<?php

declare(strict_types=1);

namespace Nvl\Templates\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Validated contract for creating an immutable publication candidate.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CreateTemplateVersionData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'metadata' => ['array'],
        ];
    }
}
