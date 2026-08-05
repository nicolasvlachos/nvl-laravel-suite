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
 * Query DTO powering select/combobox options endpoints.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
class FormSelectOption extends Data
{
    use DataTransform;

    /**
     * Create the form select query data transfer object.
     *
     * @param  string|Optional|null  $q  Search term
     * @param  bool|Optional|null  $activeOnly  Active-only filter
     * @param  bool|Optional|null  $publicOnly  Public-only filter
     * @param  bool|Optional|null  $withSubmissions  Submissions filter
     * @param  string|Optional|null  $status  Status filter
     */
    public function __construct(
        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $q = null,

        /**
         * @var bool|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean | null')]
        public readonly bool|Optional|null $activeOnly = null,

        /**
         * @var bool|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean | null')]
        public readonly bool|Optional|null $publicOnly = null,

        /**
         * @var bool|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean | null')]
        public readonly bool|Optional|null $withSubmissions = null,

        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $status = null,
    ) {}

    /**
     * Validation rules for select option filters.
     *
     * @return array<string, array<int, mixed>|string>
     */
    public static function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'activeOnly' => ['nullable', 'boolean'],
            'publicOnly' => ['nullable', 'boolean'],
            'withSubmissions' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:draft,active,paused,archived'],
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
     * Localized validation messages for select queries.
     *
     * @return array<string, mixed>
     */
    public static function messages(): array
    {
        return self::translatedMessages('forms::forms');
    }
}
