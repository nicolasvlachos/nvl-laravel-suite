<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Revision-safe block target in a definition migration plan.
 */
#[TypeScript]
final class ContentDefinitionMigrationPlanItemData extends Data
{
    use DataTransform;

    /**
     * @param  list<int>  $versions
     */
    public function __construct(
        public readonly string $blockId,
        public readonly string $blockKey,
        public readonly string $definition,
        public readonly int $fromVersion,
        public readonly int $toVersion,
        public readonly int $expectedRevision,
        public readonly bool $deleted,
        #[LiteralTypeScriptType('Array<number>')]
        public readonly array $versions,
    ) {}
}
