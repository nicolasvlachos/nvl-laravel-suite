<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Nvl\Forms\Contracts\FormEntryPrivacyPolicy;
use Nvl\Forms\Events\FormEntryChangedEvent;
use Nvl\Forms\Models\FormEntry;

/**
 * Irreversibly removes all submitter-identifying data from an entry.
 */
final readonly class AnonymizeFormEntryAction
{
    public function __construct(private FormEntryPrivacyPolicy $privacyPolicy) {}

    public function execute(
        FormEntry|string $entry,
        ?Authenticatable $actor = null,
    ): FormEntry {
        $updated = DB::transaction(function () use ($entry, $actor): FormEntry {
            $entryId = $entry instanceof FormEntry ? $entry->id : $entry;
            $model = FormEntry::query()
                ->with('form')
                ->lockForUpdate()
                ->findOrFail($entryId);
            $this->privacyPolicy->authorize('anonymize', $model, $actor);
            $model->forceFill([
                'subject' => null,
                'email' => null,
                'first_name' => null,
                'last_name' => null,
                'phone' => null,
                'address' => null,
                'body' => null,
                'submission_data' => null,
                'ip_address' => null,
                'user_agent' => null,
                'session_id' => null,
                'security_flags' => null,
                'redacted_at' => now(),
                'anonymized_at' => now(),
            ])->save();

            return $model->refresh()->load('form');
        });

        event(FormEntryChangedEvent::for($updated->form, $updated, 'anonymized', $actor));

        return $updated;
    }
}
