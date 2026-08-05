<?php

declare(strict_types=1);

namespace Nvl\Templates\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nvl\Templates\Data\TemplateActorData;

/**
 * Announces a durable template aggregate mutation.
 */
final class TemplateChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $templateId,
        public readonly string $operation,
        public readonly TemplateActorData $actor,
    ) {}
}
