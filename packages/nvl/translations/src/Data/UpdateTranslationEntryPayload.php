<?php

declare(strict_types=1);

namespace Nvl\Translations\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Update payload for translation values.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class UpdateTranslationEntryPayload extends Data
{
    use DataTransform;

    /**
     * @param  string|null  $value  New translation value
     */
    public function __construct(
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $value,
        #[LiteralTypeScriptType('number')]
        public readonly int $expectedRevision,
    ) {}

    /**
     * Validation rules.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'value' => ['required', 'nullable', 'string'],
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Validation messages.
     *
     * @return array<string, mixed>
     */
    public static function messages(): array
    {
        return self::translatedMessages('translations::translations');
    }

    /**
     * Validation attributes.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return self::translatedAttributes('translations::translations');
    }
}
