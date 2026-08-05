<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Immutable JSON-safe composition source used by versioning consumers.
 */
#[TypeScript]
final class ContentCompositionSnapshotData extends Data
{
    use DataTransform;

    /**
     * @param  list<ContentCompositionSnapshotBlockData>  $blocks
     */
    public function __construct(
        public readonly string $ownerType,
        public readonly string $ownerId,
        public readonly string $group,
        #[DataCollectionOf(ContentCompositionSnapshotBlockData::class)]
        public readonly array $blocks,
        public readonly string $version,
    ) {}
}
