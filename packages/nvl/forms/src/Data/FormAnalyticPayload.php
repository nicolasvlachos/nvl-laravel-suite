<?php

declare(strict_types=1);

namespace Nvl\Forms\Data;

use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\FormAnalytic;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Transfer object encapsulating individual form analytic events.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
class FormAnalyticPayload extends Data
{
    use DataTransform;

    /**
     * Create the form analytic data transfer object.
     *
     * @param  string|Optional  $id  Analytic identifier
     * @param  string|Optional  $formId  Parent form identifier
     * @param  FormAnalyticEventType|Optional  $eventType  Analytic event type
     * @param  string|Optional|null  $origin  Origin host
     * @param  string|Optional|null  $ipAddress  Visitor IP address
     * @param  string|Optional|null  $userAgent  Visitor user agent
     * @param  string|Optional|null  $sessionId  Session identifier
     * @param  array<string, mixed>|Optional|null  $metadata  Analytics metadata
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
         * @var FormAnalyticEventType|Optional
         */
        #[TypeScriptOptional]
        #[TypeScriptType(FormAnalyticEventType::class)]
        public readonly FormAnalyticEventType|Optional $eventType,

        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $origin,

        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $ipAddress,

        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $userAgent,

        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $sessionId,

        /**
         * @var array<string, mixed>|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        #[Nullable]
        public readonly array|Optional|null $metadata,

        /**
         * @var Lazy|Optional|FormPayload|null
         */
        #[TypeScriptOptional]
        #[TypeScriptType(FormPayload::class)]
        public readonly Lazy|Optional|FormPayload|null $form,

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
     * Create FormAnalytic from FormAnalytic model.
     *
     * @param  FormAnalytic  $formAnalytic  The form analytic model instance
     * @return self The form analytic data instance
     */
    public static function fromModel(FormAnalytic $formAnalytic): self
    {
        return new self(
            id: $formAnalytic->id,
            formId: $formAnalytic->form_id,
            eventType: $formAnalytic->event_type,
            origin: $formAnalytic->origin,
            ipAddress: $formAnalytic->ip_address,
            userAgent: $formAnalytic->user_agent,
            sessionId: $formAnalytic->session_id,
            metadata: $formAnalytic->metadata,
            form: Lazy::whenLoaded(
                'form',
                $formAnalytic,
                fn () => FormPayload::from($formAnalytic->form),
            ),
            createdAt: $formAnalytic->created_at,
            updatedAt: $formAnalytic->updated_at,
        );
    }

    /**
     * Get validation rules for form analytic data.
     *
     * @return array<string, array<int, mixed>> Validation rules
     */
    public static function rules(): array
    {
        return [
            'formId' => ['required', 'uuid', 'exists:'.FormsTables::FORMS.',id'],
            'eventType' => ['required', Rule::enum(FormAnalyticEventType::class)],
            'origin' => ['nullable', 'string', 'max:255'],
            'ipAddress' => ['nullable', 'ip'],
            'userAgent' => ['nullable', 'string', 'max:500'],
            'sessionId' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
            'metadata.*' => ['nullable'],
        ];
    }

    /**
     * Get the default wrapper key for the data.
     *
     * @return string The wrapper key
     */
    public function defaultWrap(): string
    {
        return 'form_analytic';
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
