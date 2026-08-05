<?php

declare(strict_types=1);

namespace Nvl\Forms\Data\Mutations;

use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Forms\Data\FormCorsSettings;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Enums\CorsPolicy;
use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Enums\Resolvement;
use Nvl\Forms\Rules\AllowedOriginExpressionRule;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Rules\SupportedLocaleMapRule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Write-only mutation DTO for form create and update operations.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
class MutateFormPayload extends Data
{
    use DataTransform;

    /**
     * @param  string|Optional|null  $handle  Form handle (slug)
     * @param  array<string, mixed>|Optional|null  $translations  Localized form definition and nested content
     * @param  FormStatus|Optional  $status  Form status
     * @param  Resolvement|Optional  $resolvement  Submission resolvement strategy
     * @param  FormType|Optional  $type  Form type (landing_page or iframe)
     * @param  bool|Optional  $restrictPublicAccess  Public access restriction flag
     * @param  bool|Optional  $allowMultipleRegistrations  Multiple registration flag
     * @param  bool|Optional  $dateRestricted  Date restriction flag
     * @param  Carbon|Optional|null  $availableFrom  Availability start
     * @param  Carbon|Optional|null  $availableUntil  Availability end
     * @param  bool|Optional  $enableHoneypot  Honeypot protection flag
     * @param  bool|Optional  $enableRateLimiting  Rate limiting flag
     * @param  int|Optional|null  $rateLimitPerHour  Submissions per hour limit
     * @param  bool|Optional  $requireCsrf  CSRF requirement flag
     * @param  FormCorsSettings|Optional|null  $corsSettings  CORS configuration
     * @param  array<string, mixed>|Optional|null  $options  Rendering options
     * @param  array<int, string>|Optional|null  $allowedOrigins  Allowed embedding origins
     * @param  TranslationSyncMode  $translationMode  Whether omitted locales are preserved or removed
     * @param  int|Optional  $expectedRevision  Required optimistic concurrency revision for updates
     */
    public function __construct(

        /** @var string|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $handle = new Optional,

        /** @var array<string, mixed>|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        #[Nullable]
        public readonly array|Optional|null $translations = new Optional,

        /** @var FormStatus|Optional */
        #[TypeScriptOptional]
        #[TypeScriptType(FormStatus::class)]
        public readonly FormStatus|Optional $status = new Optional,

        /** @var Resolvement|Optional */
        #[TypeScriptOptional]
        #[TypeScriptType(Resolvement::class)]
        public readonly Resolvement|Optional $resolvement = new Optional,

        /** @var FormType|Optional */
        #[TypeScriptOptional]
        #[TypeScriptType(FormType::class)]
        public readonly FormType|Optional $type = new Optional,

        /** @var bool|Optional */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $restrictPublicAccess = new Optional,

        /** @var bool|Optional */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $allowMultipleRegistrations = new Optional,

        /** @var bool|Optional */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $dateRestricted = new Optional,

        /** @var Carbon|Optional|null */
        #[TypeScriptOptional]
        public readonly Carbon|Optional|null $availableFrom = new Optional,

        /** @var Carbon|Optional|null */
        #[TypeScriptOptional]
        public readonly Carbon|Optional|null $availableUntil = new Optional,

        /** @var bool|Optional */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $enableHoneypot = new Optional,

        /** @var bool|Optional */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $enableRateLimiting = new Optional,

        /** @var int|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number | null')]
        public readonly int|Optional|null $rateLimitPerHour = new Optional,

        /** @var bool|Optional */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $requireCsrf = new Optional,

        /** @var FormCorsSettings|Optional|null */
        #[TypeScriptOptional]
        #[Nullable]
        public readonly FormCorsSettings|Optional|null $corsSettings = new Optional,

        /** @var array<string, mixed>|Optional|null */
        #[TypeScriptOptional]
        public readonly array|Optional|null $options = new Optional,

        /** @var array<int, string>|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string[] | null')]
        #[Nullable]
        public readonly array|Optional|null $allowedOrigins = new Optional,

        #[TypeScriptOptional]
        #[LiteralTypeScriptType("'patch' | 'replace'")]
        public readonly TranslationSyncMode $translationMode = TranslationSyncMode::Patch,

        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int|Optional $expectedRevision = new Optional,
    ) {}

    /**
     * Validation rules for form mutation payloads.
     *
     * @return array<string, array<int, mixed>> Validation rules
     */
    public static function rules(?ValidationContext $context = null): array
    {
        $payload = is_array($context?->fullPayload) ? $context->fullPayload : [];
        $updating = ($payload['_operation'] ?? null) === 'update';
        $formId = isset($payload['_formId']) && is_string($payload['_formId'])
            ? $payload['_formId']
            : null;

        return self::rulesFor($updating, $formId);
    }

    /**
     * Validate and create a transport-neutral create mutation.
     *
     * @param  array<string, mixed>  $payload  Untrusted mutation input
     * @return self Validated mutation data
     */
    public static function validateForCreate(array $payload): self
    {
        return self::validateAndCreate(array_replace($payload, [
            '_operation' => 'create',
            '_formId' => null,
        ]));
    }

    /**
     * Validate and create a transport-neutral update mutation.
     *
     * @param  array<string, mixed>  $payload  Untrusted mutation input
     * @param  string  $formId  Server-resolved form identifier
     * @return self Validated mutation data
     */
    public static function validateForUpdate(array $payload, string $formId): self
    {
        return self::validateAndCreate(array_replace($payload, [
            '_operation' => 'update',
            '_formId' => $formId,
        ]));
    }

    /**
     * Return validation rules for a create mutation.
     *
     * @return array<string, array<int, mixed>> Validation rules
     */
    public static function rulesForCreate(): array
    {
        return self::rulesFor(false, null);
    }

    /**
     * Return validation rules for an update mutation.
     *
     * @param  string  $formId  Server-resolved form identifier
     * @return array<string, array<int, mixed>> Validation rules
     */
    public static function rulesForUpdate(string $formId): array
    {
        return self::rulesFor(true, $formId);
    }

    /**
     * Build mutation validation rules from explicit operation context.
     *
     * @return array<string, array<int, mixed>> Validation rules
     */
    private static function rulesFor(bool $updating, ?string $formId): array
    {
        $presenceRule = $updating ? 'sometimes' : 'required';

        return [
            'handle' => ['nullable', 'string', 'max:255', Rule::unique(FormsTables::FORMS, 'handle')->ignore($formId)],
            'translations' => [$presenceRule, 'array', new SupportedLocaleMapRule],
            'translations.*' => ['nullable', 'array'],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string', 'max:2000'],
            'translations.*.submitButtonLabel' => ['nullable', 'string', 'max:100'],
            'translations.*.successTitle' => ['nullable', 'string', 'max:255'],
            'translations.*.successMessage' => ['nullable', 'string', 'max:5000'],
            'translationMode' => ['sometimes', Rule::enum(TranslationSyncMode::class)],
            'expectedRevision' => [$updating ? 'required' : 'prohibited', 'integer', 'min:1'],
            'resolvement' => [$presenceRule, Rule::enum(Resolvement::class)],
            'type' => [$presenceRule, Rule::enum(FormType::class)],
            'status' => ['sometimes', Rule::enum(FormStatus::class)],
            'restrictPublicAccess' => ['boolean'],
            'allowMultipleRegistrations' => ['boolean'],
            'dateRestricted' => ['boolean'],
            'availableFrom' => ['nullable', 'date'],
            'availableUntil' => ['nullable', 'date', 'after_or_equal:availableFrom'],
            'enableHoneypot' => ['boolean'],
            'enableRateLimiting' => ['boolean'],
            'rateLimitPerHour' => ['nullable', 'integer', 'min:1'],
            'requireCsrf' => ['boolean'],
            'corsSettings' => ['nullable', 'array:policy,allowCredentials,allowWildcards,maxAge,allowedMethods,allowedHeaders'],
            'corsSettings.policy' => [Rule::enum(CorsPolicy::class)],
            'corsSettings.allowCredentials' => ['boolean'],
            'corsSettings.allowWildcards' => ['boolean'],
            'corsSettings.maxAge' => ['integer', 'min:0', 'max:86400'],
            'corsSettings.allowedMethods' => ['array', 'min:1'],
            'corsSettings.allowedMethods.*' => ['string', 'distinct', Rule::in(['GET', 'POST', 'OPTIONS'])],
            'corsSettings.allowedHeaders' => ['array', 'min:1'],
            'corsSettings.allowedHeaders.*' => ['string', 'distinct', 'regex:/^(?:\*|[A-Za-z0-9!#$%&\\\'*+.^_`|~-]+)$/'],
            'options' => ['nullable', 'array'],
            'options.showHeader' => ['boolean'],
            'options.showLogo' => ['boolean'],
            'options.submitButtonLabel' => ['nullable', 'string', 'max:100'],
            'options.successRedirectUrl' => ['nullable', 'string', 'url', 'max:500'],
            'options.theme' => ['nullable', 'string', 'in:light,dark,brand'],
            'options.maxWidth' => ['nullable', 'string', 'in:sm,md,lg,xl,2xl'],
            'options.backgroundColor' => ['nullable', 'string', 'max:50'],
            'options.accentColor' => ['nullable', 'string', 'max:50'],
            'options.showPoweredBy' => ['boolean'],
            'options.compactLayout' => ['boolean'],
            'allowedOrigins' => ['nullable', 'array'],
            'allowedOrigins.*' => ['string', 'max:255', 'distinct', new AllowedOriginExpressionRule],
        ];
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
