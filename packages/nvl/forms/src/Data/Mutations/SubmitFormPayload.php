<?php

declare(strict_types=1);

namespace Nvl\Forms\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Nvl\Forms\Rules\BoundedSubmissionPayload;
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
 * Write-only mutation DTO for public form submission payloads.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
class SubmitFormPayload extends Data
{
    use DataTransform;

    /**
     * @param  string|Optional|null  $subject  Submission subject
     * @param  string|Optional|null  $firstName  Sender first name
     * @param  string|Optional|null  $lastName  Sender last name
     * @param  string|Optional|null  $email  Sender email
     * @param  string|Optional|null  $phone  Sender phone
     * @param  string|Optional|null  $address  Sender address
     * @param  string|Optional|null  $body  Submission body
     * @param  array<string, mixed>|Optional|null  $submissionData  Raw submission payload
     */
    public function __construct(
        /** @var string|Optional|null */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $subject = null,

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
        public readonly string|Optional|null $email = null,

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

    ) {}

    /**
     * Validation rules for inbound public submissions.
     *
     * @return array<string, array<int, mixed>|string>
     */
    public static function rules(): array
    {
        return [
            'subject' => ['nullable', 'string', 'max:255'],
            'firstName' => ['nullable', 'string', 'max:100'],
            'lastName' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:50000'],
            'submissionData' => ['nullable', 'array', new BoundedSubmissionPayload],
            'submissionData.*' => ['nullable'],
        ];
    }

    /**
     * Custom validation messages sourced from entries translations.
     *
     * @return array<string, mixed>
     */
    public static function messages(): array
    {
        return self::translatedMessages('forms::entries');
    }

    /**
     * Attribute name mappings sourced from entries translations.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return self::translatedAttributes('forms::entries');
    }
}
