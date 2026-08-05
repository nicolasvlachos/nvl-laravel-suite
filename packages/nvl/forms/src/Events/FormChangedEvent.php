<?php

declare(strict_types=1);

namespace Nvl\Forms\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvl\Forms\Models\Form;

/**
 * Stable integration event for form-definition lifecycle changes.
 */
final class FormChangedEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, bool|float|int|string|null>  $context
     */
    public function __construct(
        public Form $form,
        public string $operation,
        public string|int|null $actorId = null,
        public array $context = [],
    ) {}

    /**
     * Create an event without exposing an application-specific actor model.
     *
     * @param  array<string, bool|float|int|string|null>  $context
     */
    public static function for(
        Form $form,
        string $operation,
        ?Authenticatable $actor = null,
        array $context = [],
    ): self {
        $identifier = $actor?->getAuthIdentifier();
        $actorId = is_string($identifier) || is_int($identifier) ? $identifier : null;

        return new self($form, $operation, $actorId, $context);
    }
}
