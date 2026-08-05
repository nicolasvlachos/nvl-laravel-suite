<?php

declare(strict_types=1);

namespace Nvl\Forms\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Forms\Models\Form;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * FormSuggestion: normalized DTO powering autocomplete payloads.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class FormSuggestion extends Data
{
    use DataTransform;

    /**
     * Create the form suggestion data transfer object.
     *
     * @param  string  $id  Form identifier
     * @param  string  $label  Primary label
     * @param  string|Optional|null  $sublabel  Secondary label
     */
    public function __construct(
        /**
         * Unique form identifier (UUID).
         *
         * @var string
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $id,

        /**
         * Primary suggestion label (form name).
         *
         * @var string
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $label,

        /**
         * Optional secondary label (handle or meta).
         *
         * @var string|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $sublabel = null,
    ) {}

    /**
     * Build the DTO from a Form model instance.
     *
     * @param  Form  $form  Form model instance
     * @return self Form suggestion data
     */
    public static function fromModel(Form $form): self
    {
        $sublabel = null;

        if (! empty($form->handle)) {
            $sublabel = (string) trans('forms::forms/general.card.handle', [
                'handle' => $form->handle,
            ]);
        }

        return new self(
            id: (string) $form->id,
            label: $form->displayName(),
            sublabel: $sublabel,
        );
    }
}
