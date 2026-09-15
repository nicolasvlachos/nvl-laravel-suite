<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Nvl\Forms\Contracts\FormEntryDeletionPolicy;
use Nvl\Forms\Events\FormEntryChangedEvent;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Throwable;

/**
 * Deletes a form entry with comprehensive business logic.
 */
final class DeleteFormEntryAction
{
    public function __construct(private readonly FormEntryDeletionPolicy $deletionPolicy) {}

    /**
     * Execute the form entry deletion.
     *
     * @param  FormEntry|string  $formEntry  Form entry instance or identifier
     * @param  Authenticatable|null  $actor  Actor performing the deletion
     * @return bool True if deletion was successful
     *
     * @throws Exception|Throwable If deletion fails or entry cannot be deleted
     */
    public function execute(FormEntry|string $formEntry, ?Authenticatable $actor = null): bool
    {
        $entryId = $formEntry instanceof FormEntry ? $formEntry->id : $formEntry;

        /** @var array{deleted: bool, form: Form, entry: FormEntry, was_spam: bool} $result */
        $result = DB::transaction(function () use ($entryId, $actor) {
            $formEntry = FormEntry::query()->lockForUpdate()->findOrFail($entryId);
            $form = Form::query()->lockForUpdate()->findOrFail($formEntry->form_id);
            $formEntry->setRelation('form', $form);
            $this->validateCanDelete($formEntry, $actor);
            $wasSpam = $formEntry->is_spam;

            $deleted = $formEntry->delete();
            if ($deleted !== true) {
                throw new Exception((string) trans('forms::forms/shared.messages.error.delete_failed', ['item' => (string) trans('forms::entries/general.entities.singular')]));
            }

            if ($wasSpam && $form->spam_count > 0) {
                $form->decrement('spam_count');
            } elseif (! $wasSpam && $form->submissions_count > 0) {
                $form->decrement('submissions_count');
            }

            $freshForm = $form->fresh();
            if (! $freshForm instanceof Form) {
                $freshForm = $form;
            }

            return [
                'deleted' => $deleted,
                'form' => $freshForm,
                'entry' => $formEntry,
                'was_spam' => $wasSpam,
            ];
        });

        event(FormEntryChangedEvent::for(
            form: $result['form'],
            entry: $result['entry'],
            operation: 'deleted',
            actor: $actor,
            context: ['was_spam' => $result['was_spam']],
        ));

        return $result['deleted'];
    }

    /**
     * Validate that the entry can be deleted.
     *
     * @param  FormEntry  $formEntry  The form entry to validate
     *
     * @throws Exception If entry cannot be deleted
     */
    private function validateCanDelete(FormEntry $formEntry, ?Authenticatable $actor = null): void
    {
        // Check if user has permission to delete this entry
        if ($actor !== null && method_exists($actor, 'can') && ! $actor->can('delete', $formEntry)) {
            throw new Exception((string) trans('forms::forms/shared.messages.error.permission_denied'));
        }

        $this->deletionPolicy->authorize($formEntry, $actor);
    }
}
