<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

final readonly class ContentSnapshotCopyData
{
    /** @param list<string> $mediaIds */
    public function __construct(public string $ownerAlias, public string $ownerId, public string $group, public string $grantId, public int $sourceRevision, public string $sourceHash, public ContentCompositionSnapshotData $snapshot, public array $mediaIds) {}
}
