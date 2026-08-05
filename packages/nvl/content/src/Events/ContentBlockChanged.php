<?php

declare(strict_types=1);

namespace Nvl\Content\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentRevisionEvent;

/**
 * Stable after-commit mutation event.
 */
final class ContentBlockChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $blockId,
        public readonly ContentRevisionEvent $event,
        public readonly int $revision,
        public readonly ContentActorData $actor,
    ) {}
}
