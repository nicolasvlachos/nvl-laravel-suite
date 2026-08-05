<?php

declare(strict_types=1);

namespace Nvl\Forms\Data;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Enums\CorsPolicy;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Rules\AllowedOriginExpressionRule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Transfer object for allowed origin records scoped to forms.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
class AllowedOriginPayload extends Data
{
    use DataTransform;

    /**
     * Create the allowed origin data transfer object.
     *
     * @param  string|Optional  $id  Allowed origin identifier
     * @param  string|Optional  $formId  Parent form identifier
     * @param  string|Optional  $origin  Allowed origin host
     * @param  bool|Optional  $isActive  Active flag
     * @param  string|Optional|null  $description  Optional description
     * @param  FormCorsSettings|Optional|null  $corsSettings  CORS configuration overrides
     * @param  int|Optional  $usageCount  Total usage count
     * @param  Carbon|Optional|null  $lastUsedAt  Timestamp of last usage
     * @param  Lazy|Optional|FormPayload|null  $form  Related form data
     * @param  Carbon|Optional|null  $createdAt  Creation timestamp
     * @param  Carbon|Optional|null  $updatedAt  Update timestamp
     */
    public function __construct(

        /**
         * @var string|Optional $id
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string|Optional $id,

        /**
         * @var string|Optional
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string|Optional $formId,

        /**
         * @var string|Optional
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string|Optional $origin,

        /**
         * @var bool|Optional
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $isActive = true,

        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $description = null,

        /** @var FormCorsSettings|Optional|null */
        #[TypeScriptOptional]
        #[Nullable]
        public readonly FormCorsSettings|Optional|null $corsSettings = null,

        /**
         * @var int|Optional
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int|Optional $usageCount = 0,

        /**
         * @var Carbon|Optional|null
         */
        #[TypeScriptOptional]
        public readonly Carbon|Optional|null $lastUsedAt = null,

        /**
         * @var Lazy|Optional|FormPayload|null
         */
        #[TypeScriptOptional]
        #[TypeScriptType(FormPayload::class)]
        public readonly Lazy|Optional|FormPayload|null $form = null,

        /**
         * @var Carbon|Optional|null
         */
        #[TypeScriptOptional]
        public readonly Carbon|Optional|null $createdAt = null,

        /**
         * @var Carbon|Optional|null
         */
        #[TypeScriptOptional]
        public readonly Carbon|Optional|null $updatedAt = null,
    ) {}

    /**
     * Create AllowedOrigin from AllowedOrigin model.
     *
     * @param  AllowedOrigin  $allowedOrigin  The allowed origin model instance
     * @return self The allowed origin data instance
     */
    public static function fromModel(AllowedOrigin $allowedOrigin): self
    {
        return new self(
            id: $allowedOrigin->id,
            formId: $allowedOrigin->form_id,
            origin: $allowedOrigin->origin,
            isActive: $allowedOrigin->is_active ?? true,
            description: $allowedOrigin->description,
            corsSettings: is_array($allowedOrigin->cors_settings)
                ? FormCorsSettings::from($allowedOrigin->cors_settings)
                : null,
            usageCount: $allowedOrigin->usage_count ?? 0,
            lastUsedAt: $allowedOrigin->last_used_at,
            form: Lazy::whenLoaded(
                'form',
                $allowedOrigin,
                fn () => FormPayload::from($allowedOrigin->form),
            ),
            createdAt: $allowedOrigin->created_at,
            updatedAt: $allowedOrigin->updated_at,
        );
    }

    /**
     * Get validation rules for allowed origin data.
     *
     * @return array<string, array<int, mixed>> Validation rules
     */
    public static function rules(?ValidationContext $context = null): array
    {
        $payload = is_array($context?->fullPayload) ? $context->fullPayload : [];
        $allowedOriginId = isset($payload['id']) && is_string($payload['id'])
            ? $payload['id']
            : null;
        $formId = isset($payload['formId']) && is_string($payload['formId'])
            ? $payload['formId']
            : null;

        return self::rulesFor($allowedOriginId, $formId);
    }

    /**
     * Build validation rules from explicit resource and form context.
     *
     * @param  string|null  $allowedOriginId  Existing resource identifier for updates
     * @param  string|null  $formId  Parent form identifier for scoped uniqueness
     * @return array<string, array<int, mixed>> Validation rules
     */
    public static function rulesFor(
        ?string $allowedOriginId,
        ?string $formId,
    ): array {
        $uniqueRule = Rule::unique(FormsTables::ALLOWED_ORIGINS, 'origin')->ignore($allowedOriginId);

        if (is_string($formId) && $formId !== '') {
            $uniqueRule = $uniqueRule->where(static fn (QueryBuilder $query) => $query->where('form_id', $formId));
        }

        return [
            'formId' => ['sometimes', 'uuid', 'exists:'.FormsTables::FORMS.',id'],
            'origin' => ['required', 'string', 'max:255', new AllowedOriginExpressionRule, $uniqueRule],
            'isActive' => ['boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'corsSettings' => ['nullable', 'array:policy,allowCredentials,allowWildcards,maxAge,allowedMethods,allowedHeaders'],
            'corsSettings.policy' => [Rule::enum(CorsPolicy::class)],
            'corsSettings.allowCredentials' => ['boolean'],
            'corsSettings.allowWildcards' => ['boolean'],
            'corsSettings.maxAge' => ['integer', 'min:0', 'max:86400'],
            'corsSettings.allowedMethods' => ['array', 'min:1'],
            'corsSettings.allowedMethods.*' => ['string', 'distinct', Rule::in(['GET', 'POST', 'OPTIONS'])],
            'corsSettings.allowedHeaders' => ['array', 'min:1'],
            'corsSettings.allowedHeaders.*' => ['string', 'distinct', 'regex:/^(?:\*|[A-Za-z0-9!#$%&\\\'*+.^_`|~-]+)$/'],
            'usageCount' => ['sometimes', 'integer', 'min:0'],
            'lastUsedAt' => ['nullable', 'date'],
        ];
    }

    /**
     * Get the default wrapper key for the data.
     *
     * @return string The wrapper key
     */
    public function defaultWrap(): string
    {
        return 'allowed_origin';
    }

    /**
     * Custom validation messages sourced from module translations.
     *
     * @return array<string, mixed>
     */
    public static function messages(): array
    {
        return self::translatedMessages('forms::forms');
    }

    /**
     * Attribute name mappings sourced from module translations.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return self::translatedAttributes('forms::forms');
    }
}
