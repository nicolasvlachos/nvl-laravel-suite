<?php

declare(strict_types=1);

namespace Nvl\Forms\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Write-only mutation DTO for form entry creation from submission pipelines.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
class MutateFormEntryPayload extends Data
{
    use DataTransform;

    /**
     * @param  string|Optional  $formId  Parent form identifier
     * @param  string|Optional|null  $subject  Entry subject
     * @param  string|Optional|null  $email  Contact email
     * @param  string|Optional|null  $firstName  Contact first name
     * @param  string|Optional|null  $lastName  Contact last name
     * @param  string|Optional|null  $phone  Contact phone
     * @param  string|Optional|null  $address  Contact address
     * @param  string|Optional|null  $body  Entry body
     * @param  array<string, mixed>|Optional|null  $submissionData  Raw submission payload
     * @param  string|Optional  $submittedFrom  Submission origin
     * @param  string|Optional|null  $ipAddress  Request IP address
     * @param  string|Optional|null  $userAgent  Request user agent
     * @param  string|Optional|null  $sessionId  Session identifier
     * @param  bool|Optional  $isSpam  Spam flag
     * @param  int|Optional|null  $spamScore  Spam score from zero to one hundred
     * @param  array<string, mixed>|Optional|null  $securityFlags  Security flags
     */
    public function __construct(

        /** @var string|Optional */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string|Optional $formId,

        /** @var string|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $subject = null,

        /** @var string|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $email = null,

        /** @var string|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $firstName = null,

        /** @var string|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $lastName = null,

        /** @var string|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $phone = null,

        /** @var string|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $address = null,

        /** @var string|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $body = null,

        /** @var array<string, mixed>|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        #[Nullable]
        public readonly array|Optional|null $submissionData = null,

        /** @var string|Optional */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string|Optional $submittedFrom = '',

        /** @var string|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $ipAddress = null,

        /** @var string|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $userAgent = null,

        /** @var string|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $sessionId = null,

        /** @var bool|Optional */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $isSpam = false,

        /** @var int|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number | null')]
        public readonly int|Optional|null $spamScore = null,

        /** @var array<string, mixed>|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        #[Nullable]
        public readonly array|Optional|null $securityFlags = null,
    ) {}

    /**
     * Validation rules for form entry mutation payloads.
     *
     * @return array<string, array<int, mixed>> Validation rules
     */
    public static function rules(): array
    {
        return [
            'formId' => ['required', 'uuid', 'exists:'.FormsTables::Forms.',id'],
            'subject' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'firstName' => ['nullable', 'string', 'max:100'],
            'lastName' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'submissionData' => ['nullable', 'array'],
            'submittedFrom' => ['required', 'string', 'max:255'],
            'ipAddress' => ['nullable', 'ip'],
            'userAgent' => ['nullable', 'string', 'max:500'],
            'sessionId' => ['nullable', 'string', 'max:100'],
            'isSpam' => ['boolean'],
            'spamScore' => ['nullable', 'integer', 'between:0,100'],
            'securityFlags' => ['nullable', 'array'],
        ];
    }

    /**
     * Custom validation messages sourced from module translations.
     *
     * @return array<string, mixed>
     */
    public static function messages(): array
    {
        return self::translatedMessages('forms::entries');
    }

    /**
     * Attribute name mappings sourced from module translations.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return self::translatedAttributes('forms::entries');
    }
}
