<?php

declare(strict_types=1);

namespace Nvl\Forms\Data;

use Carbon\Carbon;
use Nvl\Data\Traits\DataTransform;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Models\FormRateLimit;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Transfer object representing form rate limit state per IP.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
class FormRateLimitPayload extends Data
{
    use DataTransform;

    /**
     * Create the form rate limit data transfer object.
     *
     * @param  string|Optional  $id  Rate limit identifier
     * @param  string|Optional  $formId  Parent form identifier
     * @param  string|Optional  $ipAddress  Request IP address
     * @param  Carbon|Optional  $windowStart  Rate limit window start
     * @param  Carbon|Optional  $lastSubmissionAt  Last submission timestamp
     * @param  int|Optional  $submissionCount  Submission count in window
     * @param  bool|Optional  $isBlocked  Blocked flag
     * @param  Carbon|Optional|null  $blockedUntil  Blocked until timestamp
     * @param  int|Optional  $violationCount  Violation count
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
        public readonly string|Optional $ipAddress,

        /**
         * @var Carbon|Optional
         */
        #[TypeScriptOptional]
        public readonly Carbon|Optional $windowStart,

        /**
         * @var Carbon|Optional
         */
        #[TypeScriptOptional]
        public readonly Carbon|Optional $lastSubmissionAt,

        /**
         * @var int|Optional
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int|Optional $submissionCount = 0,

        /**
         * @var bool|Optional
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $isBlocked = false,

        /**
         * @var Carbon|Optional|null
         */
        #[TypeScriptOptional]
        public readonly Carbon|Optional|null $blockedUntil = null,

        /**
         * @var int|Optional
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int|Optional $violationCount = 0,

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
     * Create FormRateLimit from FormRateLimit model.
     *
     * @param  FormRateLimit  $formRateLimit  The form rate limit model instance
     * @return self The form rate limit data instance
     */
    public static function fromModel(FormRateLimit $formRateLimit): self
    {
        return new self(
            id: $formRateLimit->id,
            formId: $formRateLimit->form_id,
            ipAddress: $formRateLimit->ip_address,
            windowStart: $formRateLimit->window_start,
            lastSubmissionAt: $formRateLimit->last_submission_at,
            submissionCount: $formRateLimit->submission_count ?? 0,
            isBlocked: $formRateLimit->is_blocked ?? false,
            blockedUntil: $formRateLimit->blocked_until,
            violationCount: $formRateLimit->violation_count ?? 0,
            form: Lazy::whenLoaded(
                'form',
                $formRateLimit,
                fn () => FormPayload::from($formRateLimit->form),
            ),
            createdAt: $formRateLimit->created_at,
            updatedAt: $formRateLimit->updated_at,
        );
    }

    /**
     * Get validation rules for form rate limit data.
     *
     * @return array<string, array<int, mixed>> Validation rules
     */
    public static function rules(): array
    {
        return [
            'formId' => ['required', 'uuid', 'exists:'.FormsTables::FORMS.',id'],
            'ipAddress' => ['required', 'ip'],
            'submissionCount' => ['sometimes', 'integer', 'min:0'],
            'windowStart' => ['required', 'date'],
            'lastSubmissionAt' => ['required', 'date'],
            'isBlocked' => ['boolean'],
            'blockedUntil' => ['nullable', 'date', 'after_or_equal:windowStart'],
            'violationCount' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Get the default wrapper key for the data.
     *
     * @return string The wrapper key
     */
    public function defaultWrap(): string
    {
        return 'form_rate_limit';
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
