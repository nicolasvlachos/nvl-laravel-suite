<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Nvl\Forms\Events\FormChangedEvent;
use Nvl\Forms\Models\Form;
use Throwable;

/** Deletes a form and its associated data. */
final class DeleteFormAction
{
    /**
     * Execute the form deletion.
     *
     * @param  Form|string  $form  Form instance or identifier
     * @param  Authenticatable|null  $actor  Authenticated actor performing the deletion
     * @return bool Deletion success
     *
     * @throws Exception|Throwable If form not found or has dependencies
     */
    public function execute(Form|string $form, ?Authenticatable $actor = null): bool
    {
        // Resolve model if ID provided
        $form = $form instanceof Form
            ? $form
            : Form::findOrFail($form);

        $deleted = DB::transaction(function () use ($form) {
            // Check for dependencies (form entries)
            if ($form->entries()->exists()) {
                throw new Exception((string) trans('forms::forms/messages.error.cannot_delete_with_entries'));
            }

            // Perform deletion
            $deleted = $form->delete();

            if ($deleted === null) {
                throw new Exception((string) trans('forms::forms/shared.messages.error.delete_failed', ['item' => (string) trans('forms::forms/general.entities.singular')]));
            }

            return $deleted;
        });

        event(FormChangedEvent::for($form, 'deleted', $actor));

        return $deleted;
    }
}
