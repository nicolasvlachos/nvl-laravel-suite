<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Nvl\Forms\Events\FormEntryChangedEvent;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Throwable;

/**
 * Marks a form entry as spam and updates aggregate counters.
 */
final class MarkFormEntryAsSpamAction
{
    /**
     * Mark the given entry as spam.
     *
     * @param  FormEntry|string  $entry  Entry model or identifier
     * @param  string|null  $reason  Optional reason for spam classification
     * @return FormEntry Updated entry model
     *
     * @throws Throwable
     */
    public function execute(
        FormEntry|string $entry,
        ?string $reason = null,
        ?Authenticatable $actor = null,
    ): FormEntry {
        /** @var array{entry: FormEntry, form: Form} $result */
        $result = DB::transaction(function () use ($entry, $reason): array {
            $entryModel = $entry instanceof FormEntry ? $entry : FormEntry::findOrFail($entry);
            $wasSpam = $entryModel->is_spam === true;

            $entryModel->setSecurityFlag('marked_spam_at', now()->toISOString());

            if ($reason !== null && $reason !== '') {
                $entryModel->setSecurityFlag('spam_reason', $reason);
            }

            $entryModel->update([
                'is_spam' => true,
            ]);

            $entryModel->loadMissing('form');
            if (! $wasSpam) {
                $entryModel->form->increment('spam_count');
            }

            $freshEntry = $entryModel->refresh();
            $form = Form::query()->findOrFail($freshEntry->form_id);
            $freshEntry->setRelation('form', $form);

            return [
                'entry' => $freshEntry,
                'form' => $form->fresh() ?? $form,
            ];
        });

        event(FormEntryChangedEvent::for(
            form: $result['form'],
            entry: $result['entry'],
            operation: 'marked_as_spam',
            actor: $actor,
            context: ['has_reason' => is_string($reason) && $reason !== ''],
        ));

        return $result['entry'];
    }
}
