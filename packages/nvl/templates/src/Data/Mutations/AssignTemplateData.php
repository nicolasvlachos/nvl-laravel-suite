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
 * Validated template-to-owner assignment contract.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class AssignTemplateData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly string $ownerType,
        public readonly string $ownerId,
        public readonly string $profile = 'default',
        public readonly ?string $versionId = null,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $settings = [],
        public readonly int $expectedRevision = 0,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'ownerType' => ['required', 'string', 'max:100'],
            'ownerId' => ['required', 'string', 'max:255'],
            'profile' => ['required', 'string', 'max:100'],
            'versionId' => ['nullable', 'uuid'],
            'settings' => ['array'],
            'expectedRevision' => ['required', 'integer', 'min:0'],
        ];
    }
}
