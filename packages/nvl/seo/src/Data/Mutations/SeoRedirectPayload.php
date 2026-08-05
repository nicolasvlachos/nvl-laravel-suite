<?php

declare(strict_types=1);

namespace Nvl\Seo\Data\Mutations;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Create/update contract for a scoped redirect.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class SeoRedirectPayload extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>|Optional|null  $metadata
     */
    public function __construct(
        public readonly string $sourcePath,
        public readonly string $target,
        #[TypeScriptOptional]
        public readonly ?string $locale = null,
        #[TypeScriptOptional]
        public readonly int $statusCode = 301,
        #[TypeScriptOptional]
        public readonly bool $isActive = true,
        #[TypeScriptOptional]
        public readonly ?string $expiresAt = null,
        #[TypeScriptOptional]
        public readonly array|Optional|null $metadata = new Optional,
        #[TypeScriptOptional]
        public readonly int|Optional|null $expectedRevision = new Optional,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'sourcePath' => ['required', 'string', 'max:2048', 'starts_with:/'],
            'target' => ['required', 'string', 'max:2048'],
            'locale' => ['nullable', 'string', 'max:35'],
            'statusCode' => ['integer', Rule::in([301, 302, 307, 308])],
            'isActive' => ['boolean'],
            'expiresAt' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
            'expectedRevision' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
