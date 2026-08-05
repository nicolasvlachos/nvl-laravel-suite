<?php

declare(strict_types=1);

namespace Nvl\Forms\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

/**
 * Stable integration event for form-entry lifecycle and privacy operations.
 */
final class FormEntryChangedEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, bool|float|int|string|null>  $context
     */
    public function __construct(
        public Form $form,
        public string $entryId,
        public string $operation,
        public string|int|null $actorId = null,
        public array $context = [],
    ) {}

    /**
     * Create a sanitized event without serializing the entry's personal data.
     *
     * @param  array<string, bool|float|int|string|null>  $context
     */
    public static function for(
        Form $form,
        FormEntry|string $entry,
        string $operation,
        ?Authenticatable $actor = null,
        array $context = [],
    ): self {
        $identifier = $actor?->getAuthIdentifier();
        $actorId = is_string($identifier) || is_int($identifier) ? $identifier : null;

        return new self(
            $form,
            $entry instanceof FormEntry ? $entry->id : $entry,
            $operation,
            $actorId,
            $context,
        );
    }
}
