<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One successfully committed block definition upgrade.
 */
#[TypeScript]
final class ContentDefinitionMigrationResultItemData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly string $blockId,
        public readonly string $definition,
        public readonly int $fromVersion,
        public readonly int $toVersion,
        public readonly int $previousRevision,
        public readonly int $revision,
    ) {}
}
