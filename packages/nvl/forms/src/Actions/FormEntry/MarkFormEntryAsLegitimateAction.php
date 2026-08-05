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
 * Marks a form entry as legitimate and updates aggregate counters.
 */
final class MarkFormEntryAsLegitimateAction
{
    /**
     * Mark the given entry as legitimate.
     *
     * @param  FormEntry|string  $entry  Entry model or identifier
     * @return FormEntry Updated entry model
     *
     * @throws Throwable
     */
    public function execute(FormEntry|string $entry, ?Authenticatable $actor = null): FormEntry
    {
        /** @var array{entry: FormEntry, form: Form, was_spam: bool} $result */
        $result = DB::transaction(function () use ($entry): array {
            $entryModel = $entry instanceof FormEntry ? $entry : FormEntry::findOrFail($entry);

            $wasSpam = $entryModel->is_spam === true;

            $entryModel->setSecurityFlag('marked_legitimate_at', now()->toISOString());

            $entryModel->update([
                'is_spam' => false,
            ]);

            if ($wasSpam) {
                $entryModel->loadMissing('form');

                if ($entryModel->form->spam_count > 0) {
                    $entryModel->form->decrement('spam_count');
                }
            }

            $freshEntry = $entryModel->refresh()->load('form');
            $form = $freshEntry->form;

            return [
                'entry' => $freshEntry,
                'form' => $form->fresh() ?? $form,
                'was_spam' => $wasSpam,
            ];
        });

        event(FormEntryChangedEvent::for(
            form: $result['form'],
            entry: $result['entry'],
            operation: 'marked_as_legitimate',
            actor: $actor,
            context: ['was_spam' => $result['was_spam']],
        ));

        return $result['entry'];
    }
}
