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
        // Resolve model if ID provided and eager load form relationship
        $formEntry = $formEntry instanceof FormEntry
            ? $formEntry->load('form:id,handle,submissions_count,spam_count')
            : FormEntry::with('form:id,handle,submissions_count,spam_count')->findOrFail($formEntry);

        // Validate that entry can be deleted
        $this->validateCanDelete($formEntry, $actor);

        /** @var array{deleted: bool, form: Form, entry: FormEntry, was_spam: bool} $result */
        $result = DB::transaction(function () use ($formEntry) {
            $form = $formEntry->form;
            $wasSpam = $formEntry->is_spam;

            // Update form counters before deletion
            if ($wasSpam && $form->spam_count > 0) {
                $form->decrement('spam_count');
            } elseif (! $wasSpam && $form->submissions_count > 0) {
                $form->decrement('submissions_count');
            }

            // Delete the form entry
            $deleted = $formEntry->delete();

            if ($deleted === null) {
                throw new Exception((string) trans('forms::forms/shared.messages.error.delete_failed', ['item' => (string) trans('forms::entries/general.entities.singular')]));
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
