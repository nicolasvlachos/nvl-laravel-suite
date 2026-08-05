<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Forms\Contracts\FormEntryPrivacyPolicy;
use Nvl\Forms\Events\FormEntryChangedEvent;
use Nvl\Forms\Models\FormEntry;

/**
 * Redacts selected personal-data fields without deleting the entry.
 */
final readonly class RedactFormEntryAction
{
    private const array REDACTABLE = [
        'subject',
        'email',
        'first_name',
        'last_name',
        'phone',
        'address',
        'body',
        'submission_data',
        'ip_address',
        'user_agent',
        'session_id',
    ];

    public function __construct(private FormEntryPrivacyPolicy $privacyPolicy) {}

    /**
     * @param  list<string>  $fields
     */
    public function execute(
        FormEntry|string $entry,
        array $fields,
        ?Authenticatable $actor = null,
    ): FormEntry {
        $unknown = array_values(array_diff($fields, self::REDACTABLE));
        if ($fields === [] || $unknown !== []) {
            throw new InvalidArgumentException('Redaction fields must use the documented allowlist.');
        }

        $updated = DB::transaction(function () use ($entry, $fields, $actor): FormEntry {
            $entryId = $entry instanceof FormEntry ? $entry->id : $entry;
            $model = FormEntry::query()
                ->with('form')
                ->lockForUpdate()
                ->findOrFail($entryId);
            $this->privacyPolicy->authorize('redact', $model, $actor);

            $attributes = array_fill_keys($fields, null);
            $attributes['redacted_at'] = now();
            $model->forceFill($attributes)->save();

            return $model->refresh()->load('form');
        });

        event(FormEntryChangedEvent::for(
            $updated->form,
            $updated,
            'redacted',
            $actor,
            ['field_count' => count($fields)],
        ));

        return $updated;
    }
}
