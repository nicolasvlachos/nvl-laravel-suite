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
 * Adds or updates a single security flag on a form entry.
 */
final class AddFormEntrySecurityFlagAction
{
    /**
     * Persist a security flag key/value pair on the given entry.
     *
     * @param  FormEntry|string  $entry  Entry model or identifier
     * @param  string  $key  Security flag key
     * @param  mixed  $value  Security flag value
     * @return FormEntry Updated entry model
     *
     * @throws Throwable
     */
    public function execute(
        FormEntry|string $entry,
        string $key,
        mixed $value,
        ?Authenticatable $actor = null,
    ): FormEntry {
        /** @var array{entry: FormEntry, form: Form} $result */
        $result = DB::transaction(function () use ($entry, $key, $value): array {
            $entryModel = $entry instanceof FormEntry ? $entry : FormEntry::findOrFail($entry);

            $entryModel->setSecurityFlag($key, $value);
            $entryModel->save();

            $freshEntry = $entryModel->refresh()->load('form');
            $form = $freshEntry->form;

            return [
                'entry' => $freshEntry,
                'form' => $form->fresh() ?? $form,
            ];
        });

        event(FormEntryChangedEvent::for(
            form: $result['form'],
            entry: $result['entry'],
            operation: 'security_flag_added',
            actor: $actor,
            context: ['flag_key' => $key],
        ));

        return $result['entry'];
    }
}
