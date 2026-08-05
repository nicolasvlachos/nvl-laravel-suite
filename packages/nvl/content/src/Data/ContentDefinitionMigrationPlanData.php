<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Bounded, deterministic, revision-safe definition migration plan.
 */
#[TypeScript]
final class ContentDefinitionMigrationPlanData extends Data
{
    use DataTransform;

    /**
     * @param  list<ContentDefinitionMigrationPlanItemData>  $ready
     * @param  list<ContentDefinitionMigrationProblemData>  $blocked
     */
    public function __construct(
        public readonly ?string $definition,
        public readonly int $limit,
        public readonly int $totalPending,
        public readonly bool $hasMore,
        #[DataCollectionOf(ContentDefinitionMigrationPlanItemData::class)]
        public readonly array $ready,
        #[DataCollectionOf(ContentDefinitionMigrationProblemData::class)]
        public readonly array $blocked,
    ) {}
}
