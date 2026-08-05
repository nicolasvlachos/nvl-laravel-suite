<?php

declare(strict_types=1);

namespace Nvl\Translations\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Nvl\Translations\Data\TranslationEntryPayload;

/**
 * Reports a committed manual translation workspace edit.
 */
final class TranslationEntryUpdated implements ShouldDispatchAfterCommit
{
    /**
     * Create an immutable entry-update event.
     */
    public function __construct(public readonly TranslationEntryPayload $entry) {}
}
