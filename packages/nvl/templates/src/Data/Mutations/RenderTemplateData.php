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
 * Validated synchronous or queued render request.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class RenderTemplateData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $locale,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $payload,
        public readonly ?string $ownerType = null,
        public readonly ?string $ownerId = null,
        public readonly string $profile = 'default',
        public readonly ?string $versionId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly bool $download = false,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'locale' => ['required', 'string', 'max:35'],
            'payload' => ['array'],
            'ownerType' => ['nullable', 'required_with:ownerId', 'string', 'max:100'],
            'ownerId' => ['nullable', 'required_with:ownerType', 'string', 'max:255'],
            'profile' => ['string', 'max:100'],
            'versionId' => ['nullable', 'uuid'],
            'idempotencyKey' => ['nullable', 'string', 'max:191'],
            'download' => ['boolean'],
        ];
    }
}
