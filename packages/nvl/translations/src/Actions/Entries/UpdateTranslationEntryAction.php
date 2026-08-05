<?php

declare(strict_types=1);

namespace Nvl\Translations\Actions\Entries;

use Illuminate\Support\Facades\DB;
use Nvl\Translations\Contracts\UpdateTranslationEntryContract;
use Nvl\Translations\Data\TranslationEntryPayload;
use Nvl\Translations\Data\UpdateTranslationEntryPayload;
use Nvl\Translations\Events\TranslationEntryUpdated;
use Nvl\Translations\Exceptions\InvalidTranslationInputException;
use Nvl\Translations\Exceptions\StaleTranslationWorkspaceException;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Services\TranslationProcessLock;
use Nvl\Translations\Support\TranslationValueHash;

/**
 * Updates a single translation entry value.
 */
final class UpdateTranslationEntryAction implements UpdateTranslationEntryContract
{
    public function __construct(
        private readonly TranslationProcessLock $lock,
    ) {}

    /**
     * @param  TranslationEntry|string  $entry  Target translation entry or id
     * @param  UpdateTranslationEntryPayload  $data  Update payload
     * @return TranslationEntry Updated model instance
     */
    public function execute(TranslationEntry|string $entry, UpdateTranslationEntryPayload $data): TranslationEntry
    {
        if ($data->value !== null && ! mb_check_encoding($data->value, 'UTF-8')) {
            throw new InvalidTranslationInputException('Translation values must contain valid UTF-8 text.');
        }

        $entryId = $entry instanceof TranslationEntry ? $entry->id : $entry;
        $updated = $this->lock->execute(
            'update',
            fn (): TranslationEntry => DB::transaction(function () use ($entryId, $data): TranslationEntry {
                $model = TranslationEntry::query()
                    ->lockForUpdate()
                    ->findOrFail($entryId);

                if ($model->revision !== $data->expectedRevision) {
                    throw StaleTranslationWorkspaceException::forEntry($model->id);
                }

                $matchesSource = ! $model->is_missing
                    && $model->source_hash !== null
                    && hash_equals($model->source_hash, TranslationValueHash::make($data->value));

                $model->fill([
                    'value' => $data->value,
                    'is_missing' => false,
                    'sync_status' => $matchesSource ? 'synchronized' : 'edited',
                    'conflict_metadata' => null,
                ]);
                $model->save();

                $model->refresh();

                return $model;
            }),
        );

        event(new TranslationEntryUpdated(TranslationEntryPayload::fromModel($updated)));

        return $updated;
    }
}
