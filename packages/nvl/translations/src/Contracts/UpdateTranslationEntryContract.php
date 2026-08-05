<?php

declare(strict_types=1);

namespace Nvl\Translations\Contracts;

use Nvl\Translations\Data\UpdateTranslationEntryPayload;
use Nvl\Translations\Models\TranslationEntry;

/**
 * Defines optimistic workspace translation entry updates.
 */
interface UpdateTranslationEntryContract
{
    /**
     * Update one translation value using its current workspace revision.
     */
    public function execute(TranslationEntry|string $entry, UpdateTranslationEntryPayload $data): TranslationEntry;
}
