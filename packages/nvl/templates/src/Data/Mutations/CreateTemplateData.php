<?php

declare(strict_types=1);

namespace Nvl\Templates\Data\Mutations;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Templates\Enums\TemplateStatus;
use Nvl\Translatable\Rules\SupportedLocaleMapRule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Validated contract for creating a structural template.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CreateTemplateData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function __construct(
        public readonly string $key,
        public readonly TemplateStatus $status = TemplateStatus::Active,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $metadata = [],
        #[LiteralTypeScriptType('Record<string, Record<string, unknown>>')]
        public readonly array $translations = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'status' => ['required', Rule::enum(TemplateStatus::class)],
            'metadata' => ['array'],
            'translations' => ['array', new SupportedLocaleMapRule],
            'translations.*.title' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
        ];
    }
}
