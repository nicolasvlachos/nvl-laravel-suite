<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Nvl\Forms\Data\FormEntryPayload;
use Nvl\Forms\Data\Mutations\SubmitFormPayload;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Support\FormSubmissionContext;

/**
 * Combines validated public input with trusted request metadata for persistence.
 */
final class PrepareFormSubmissionDataAction
{
    /**
     * Execute the submission data preparation.
     *
     * @param  Form  $form  Form model
     * @param  SubmitFormPayload  $data  Validated submission data
     * @param  FormSubmissionContext  $context  Trusted submission context
     * @return FormEntryPayload Prepared form entry data
     */
    public function execute(
        Form $form,
        SubmitFormPayload $data,
        FormSubmissionContext $context,
    ): FormEntryPayload {
        $validated = $data->toArray();
        $submittedFrom = $context->originHost;

        if (! is_string($submittedFrom) || $submittedFrom === '') {
            $submittedFrom = $context->requestHost ?? 'localhost';
        }

        return FormEntryPayload::from([
            'formId' => $form->id,
            'subject' => $validated['subject'] ?? null,
            'firstName' => $validated['firstName'] ?? null,
            'lastName' => $validated['lastName'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'body' => $validated['body'] ?? null,
            'submissionData' => $validated['submissionData'] ?? [],
            'submittedFrom' => $submittedFrom,
        ]);
    }
}
