<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Nvl\Forms\Exceptions\FormOwnershipException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

/**
 * Validates that a form entry belongs to the specified form.
 */
final class ValidateFormEntryOwnershipAction
{
    /**
     * Execute the ownership validation.
     *
     * @param  Form  $form  Form model
     * @param  FormEntry  $entry  Form entry model
     *
     * @throws FormOwnershipException If entry doesn't belong to form
     */
    public function execute(Form $form, FormEntry $entry): void
    {
        if ($entry->form_id !== $form->id) {
            throw new FormOwnershipException((string) trans('forms::forms/shared.messages.error.ownership_mismatch', [
                'item' => (string) trans('forms::entries/general.entities.singular'),
                'parent' => (string) trans('forms::forms/general.entities.singular'),
            ]));
        }
    }
}
