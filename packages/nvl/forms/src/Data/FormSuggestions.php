<?php

declare(strict_types=1);

namespace Nvl\Forms\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * DTO for lightweight suggestion lookups while typing form names.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
class FormSuggestions extends Data
{
    use DataTransform;

    /**
     * Create the form suggestions query data transfer object.
     *
     * @param  string  $q  Search query
     * @param  int|Optional  $limit  Result limit
     */
    public function __construct(
        /**
         * @var string
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $q,

        /**
         * @var int|Optional
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int|Optional $limit = 10,
    ) {}

    /**
     * Validation rules for suggestion queries.
     *
     * @return array<string, array<int, mixed>|string>
     */
    public static function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * Attribute name mappings sourced from module translations.
     *
     * @return array<string, mixed>
     */
    public static function attributes(): array
    {
        return self::translatedAttributes('forms::forms');
    }

    /**
     * Localized validation messages for suggestion requests.
     *
     * @return array<string, mixed>
     */
    public static function messages(): array
    {
        return self::translatedMessages('forms::forms');
    }
}
