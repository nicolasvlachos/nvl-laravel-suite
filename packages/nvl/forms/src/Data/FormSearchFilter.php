<?php

declare(strict_types=1);

namespace Nvl\Forms\Data;

use Carbon\Carbon;
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
 * Query DTO used to filter forms for admin search APIs.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
class FormSearchFilter extends Data
{
    use DataTransform;

    /**
     * Create the form search query data transfer object.
     *
     * @param  string|Optional|null  $search  Search term
     * @param  string|Optional|null  $handle  Form handle filter
     * @param  string|Optional|null  $status  Form status filter
     * @param  bool|Optional|null  $restrictPublicAccess  Restrict access filter
     * @param  bool|Optional|null  $hasSubmissions  Submissions filter
     * @param  bool|Optional|null  $recentlyUsed  Recently used filter
     * @param  Carbon|Optional|null  $createdAfter  Created-after filter
     * @param  Carbon|Optional|null  $createdBefore  Created-before filter
     * @param  int|Optional  $limit  Result limit
     * @param  array<int, string>|Optional|null  $with  Relationships to load
     */
    public function __construct(
        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $search = null,

        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $handle = null,

        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $status = null,

        /**
         * @var bool|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean | null')]
        public readonly bool|Optional|null $restrictPublicAccess = null,

        /**
         * @var bool|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean | null')]
        public readonly bool|Optional|null $hasSubmissions = null,

        /**
         * @var bool|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean | null')]
        public readonly bool|Optional|null $recentlyUsed = null,

        /**
         * @var Carbon|Optional|null
         */
        #[TypeScriptOptional]
        public readonly Carbon|Optional|null $createdAfter = null,

        /**
         * @var Carbon|Optional|null
         */
        #[TypeScriptOptional]
        public readonly Carbon|Optional|null $createdBefore = null,

        /**
         * @var int|Optional
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int|Optional $limit = 25,

        /**
         * @var array<int, string>|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string[] | null')]
        public readonly array|Optional|null $with = null,
    ) {}

    /**
     * Validation rules for the search payload.
     *
     * @return array<string, array<int, mixed>|string>
     */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'handle' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:draft,active,paused,archived'],
            'restrictPublicAccess' => ['nullable', 'boolean'],
            'hasSubmissions' => ['nullable', 'boolean'],
            'recentlyUsed' => ['nullable', 'boolean'],
            'createdAfter' => ['nullable', 'date'],
            'createdBefore' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'with' => ['nullable', 'array'],
            'with.*' => ['string', 'in:entries'],
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
     * Localized validation messages for search rules.
     *
     * @return array<string, mixed>
     */
    public static function messages(): array
    {
        return self::translatedMessages('forms::forms');
    }
}
