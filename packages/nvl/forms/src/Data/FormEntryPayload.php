<?php

declare(strict_types=1);

namespace Nvl\Forms\Data;

use Carbon\Carbon;
use Nvl\Data\Traits\DataTransform;
use Nvl\Forms\Models\FormEntry;
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
 * Read-only transfer object for form entry display and listing.
 *
 * For entry creation mutations, use MutateFormEntryPayload instead.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
class FormEntryPayload extends Data
{
    use DataTransform;

    /**
     * Create the form entry data transfer object.
     *
     * @param  string|Optional  $id  Entry identifier
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
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $subject,

        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $email,

        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $firstName,

        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $lastName,

        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $phone,

        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $address,

        /**
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $body,

        /**
         * @var array<string, mixed>|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        #[Nullable]
        public readonly array|Optional|null $submissionData,

        /**
         * @var string|Optional
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string|Optional $submittedFrom,

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
         * @var bool|Optional
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $isSpam = false,

        /**
         * @var int|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number | null')]
        public readonly int|Optional|null $spamScore = null,

        /**
         * @var array<string, mixed>|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        #[Nullable]
        public readonly array|Optional|null $securityFlags = null,

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
     * Create FormEntryPayload from FormEntry model.
     *
     * @param  FormEntry  $formEntry  The form entry model instance
     * @return self The form entry data instance
     */
    public static function fromModel(FormEntry $formEntry): self
    {
        return new self(
            id: $formEntry->id,
            formId: $formEntry->form_id,
            subject: $formEntry->subject,
            email: $formEntry->email,
            firstName: $formEntry->first_name,
            lastName: $formEntry->last_name,
            phone: $formEntry->phone,
            address: $formEntry->address,
            body: $formEntry->body,
            submissionData: $formEntry->submission_data,
            submittedFrom: $formEntry->submitted_from,
            ipAddress: $formEntry->ip_address,
            userAgent: $formEntry->user_agent,
            sessionId: $formEntry->session_id,
            isSpam: $formEntry->is_spam ?? false,
            spamScore: $formEntry->spam_score,
            securityFlags: $formEntry->security_flags,
            form: Lazy::whenLoaded(
                'form',
                $formEntry,
                fn () => FormPayload::from($formEntry->form),
            ),
            createdAt: $formEntry->created_at,
            updatedAt: $formEntry->updated_at,
        );
    }
}
