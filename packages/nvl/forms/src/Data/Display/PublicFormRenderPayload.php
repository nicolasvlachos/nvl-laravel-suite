<?php

declare(strict_types=1);

namespace Nvl\Forms\Data\Display;

use Nvl\Data\Traits\DataTransform;
use Nvl\Forms\Data\FormOptions;
use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Enums\FormType;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Public-safe localized form contract used by render consumers.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class PublicFormRenderPayload extends Data
{
    use DataTransform;

    /**
     * @param  string  $id  Form identifier
     * @param  string|null  $handle  Public form handle
     * @param  string  $name  Localized form name
     * @param  string|null  $description  Localized form description
     * @param  FormStatus  $status  Current form status
     * @param  FormType  $type  Public rendering mode
     * @param  string  $locale  Resolved content locale
     * @param  array<string, mixed>  $content  Localized form definition
     * @param  string|null  $submitButtonLabel  Localized submit button label
     * @param  string|null  $successTitle  Localized success title
     * @param  string|null  $successMessage  Localized success message
     * @param  bool  $restrictPublicAccess  Whether origin restrictions are enabled
     * @param  bool  $allowMultipleRegistrations  Whether repeat registrations are allowed
     * @param  FormOptions|null  $options  Public display and behavior options
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $id,

        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $handle,

        #[LiteralTypeScriptType('string')]
        public readonly string $name,

        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $description,

        #[TypeScriptType(FormStatus::class)]
        public readonly FormStatus $status,

        #[TypeScriptType(FormType::class)]
        public readonly FormType $type,

        #[LiteralTypeScriptType('string')]
        public readonly string $locale,

        /** @var array<string, mixed> */
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $content,

        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $submitButtonLabel,

        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $successTitle,

        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $successMessage,

        #[LiteralTypeScriptType('boolean')]
        public readonly bool $restrictPublicAccess,

        #[LiteralTypeScriptType('boolean')]
        public readonly bool $allowMultipleRegistrations,

        #[LiteralTypeScriptType('Nvl.Forms.Data.FormOptions | null')]
        public readonly ?FormOptions $options,
    ) {}
}
