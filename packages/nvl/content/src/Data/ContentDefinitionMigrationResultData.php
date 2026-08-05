<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Atomic definition migration batch result.
 */
#[TypeScript]
final class ContentDefinitionMigrationResultData extends Data
{
    use DataTransform;

    /**
     * @param  list<ContentDefinitionMigrationResultItemData>  $migrated
     */
    public function __construct(
        public readonly bool $applied,
        #[DataCollectionOf(ContentDefinitionMigrationResultItemData::class)]
        public readonly array $migrated,
    ) {}
}
