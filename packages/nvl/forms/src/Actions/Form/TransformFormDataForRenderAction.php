<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Nvl\Forms\Data\Display\PublicFormRenderPayload;
use Nvl\Forms\Models\Form;
use Nvl\Translatable\Services\ContentLocale;

/**
 * Builds the public-safe localized contract used by form render consumers.
 */
final class TransformFormDataForRenderAction
{
    public function __construct(
        private readonly ContentLocale $contentLocale,
    ) {}

    /**
     * Execute the form data transformation.
     *
     * @param  Form  $form  Form model with loaded relationships
     * @return PublicFormRenderPayload Public-safe render data
     */
    public function execute(Form $form): PublicFormRenderPayload
    {
        $locale = $this->contentLocale->get();
        $submitButtonLabel = $this->nullableString(
            $form->translated('submit_button_label', $locale),
        ) ?? $form->options?->submitButtonLabel;

        return new PublicFormRenderPayload(
            id: $form->id,
            handle: $form->handle,
            name: $form->displayName($locale),
            description: $form->displayDescription($locale),
            status: $form->status,
            type: $form->type,
            locale: $locale,
            content: $form->localizedContent($locale),
            submitButtonLabel: $submitButtonLabel,
            successTitle: $this->nullableString($form->translated('success_title', $locale)),
            successMessage: $this->nullableString($form->translated('success_message', $locale)),
            restrictPublicAccess: $form->restrict_public_access,
            allowMultipleRegistrations: $form->allow_multiple_registrations,
            options: $form->options,
        );
    }

    /**
     * Preserve only public string copy from a dynamic translation value.
     */
    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
