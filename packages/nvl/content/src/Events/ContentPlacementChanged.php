<?php

declare(strict_types=1);

namespace Nvl\Content\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentPlacementEvent;

/**
 * Announces a committed placement creation, update, or removal.
 */
final class ContentPlacementChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $placementId,
        public readonly ContentPlacementEvent $event,
        public readonly int $revision,
        public readonly ContentActorData $actor,
        public readonly ?string $ownerType = null,
        public readonly ?string $ownerId = null,
        public readonly ?string $group = null,
        public readonly ?string $blockId = null,
    ) {}
}
